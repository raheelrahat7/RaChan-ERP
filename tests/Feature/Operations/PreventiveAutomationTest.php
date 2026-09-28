<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Actions\GenerateDuePreventiveMaintenance;
use App\Domain\Operations\Actions\ManagePreventiveMaintenance;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PreventiveAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private PreventiveMaintenancePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->utc()->setDateTime(2026, 9, 27, 20, 30));
        $this->organization = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Local', 'type' => 'residential']);
        $this->plan = PreventiveMaintenancePlan::create(['organization_id' => $this->organization->id, 'property_id' => $property->id, 'title' => 'Daily service', 'frequency_days' => 1, 'next_due_on' => '2026-09-25']);
    }

    public function test_opted_in_automation_preserves_every_missed_local_occurrence_and_is_replay_safe(): void
    {
        $this->assertSame(['created' => 0, 'failed' => 0], app(GenerateDuePreventiveMaintenance::class)->handle());
        $this->plan->update(['auto_generate_enabled' => true, 'requires_manager_confirmation' => true]);
        $this->artisan('operations:generate-preventive')->expectsOutput('Created 4 preventive jobs; 0 plans failed.')->assertSuccessful();
        $this->assertSame(['2026-09-25', '2026-09-26', '2026-09-27', '2026-09-28'], MaintenanceRequest::orderBy('preventive_due_on')->get()->map(fn ($job) => $job->preventive_due_on->toDateString())->all());
        $this->assertSame('2026-09-29', $this->plan->fresh()->next_due_on->toDateString());
        $last = MaintenanceRequest::latest('id')->first();
        $this->assertSame('2026-09-28 18:59:59', $last->due_at->utc()->toDateTimeString());
        $this->assertTrue($last->requires_manager_confirmation);
        $this->assertNull($last->assigned_to);
        $this->assertNotNull($this->plan->fresh()->automation_last_run_at);
        $this->assertSame(4, AuditLog::whereNull('actor_id')->where('properties->source', 'automatic')->count());
        $this->assertSame(['created' => 0, 'failed' => 0], app(GenerateDuePreventiveMaintenance::class)->handle());
        $this->assertDatabaseCount('maintenance_requests', 4);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->actingAs($this->manager)->get(route('preventive-maintenance.index'))->assertInertia(fn (Assert $page) => $page->has('plans.0.occurrences', 4)->where('plans.0.occurrences.0.id', $last->id));
    }

    public function test_catch_up_is_bounded_and_continues_in_the_next_batch(): void
    {
        $this->plan->update(['auto_generate_enabled' => true, 'next_due_on' => '2026-08-01']);
        $this->assertSame(['created' => 25, 'failed' => 0], app(GenerateDuePreventiveMaintenance::class)->handle());
        $this->assertSame('2026-08-26', $this->plan->fresh()->next_due_on->toDateString());
        $this->assertSame(['created' => 25, 'failed' => 0], app(GenerateDuePreventiveMaintenance::class)->handle());
        $this->assertDatabaseCount('maintenance_requests', 50);
        $this->assertSame(50, MaintenanceRequest::distinct()->count('preventive_due_on'));
    }

    public function test_inactive_future_and_disabled_plans_are_not_generated_from_stale_models(): void
    {
        $stale = $this->plan;
        $this->plan->update(['auto_generate_enabled' => true, 'is_active' => false]);
        $this->assertFalse(app(ManagePreventiveMaintenance::class)->generateAutomaticNext($this->organization, $stale));
        $this->plan->update(['is_active' => true, 'auto_generate_enabled' => false]);
        $this->assertFalse(app(ManagePreventiveMaintenance::class)->generateAutomaticNext($this->organization, $stale));
        $this->plan->update(['auto_generate_enabled' => true, 'next_due_on' => '2026-09-29']);
        $this->assertFalse(app(ManagePreventiveMaintenance::class)->generateAutomaticNext($this->organization, $stale));
        $this->assertDatabaseCount('maintenance_requests', 0);
    }

    public function test_only_local_managers_configure_automation_without_immediate_generation(): void
    {
        $input = ['auto_generate_enabled' => true, 'requires_manager_confirmation' => true, 'is_active' => true];
        $member = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($member)->put(route('preventive-maintenance.automation', $this->plan), $input)->assertForbidden();
        $this->actingAs($this->manager)->put(route('preventive-maintenance.automation', $this->plan), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->plan->fresh()->auto_generate_enabled);
        $this->assertDatabaseCount('maintenance_requests', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.preventive_plan.automation_configured', 'actor_id' => $this->manager->id]);
        $this->put(route('preventive-maintenance.automation', $this->plan), [...$input, 'is_active' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['created' => 0, 'failed' => 0], app(GenerateDuePreventiveMaintenance::class)->handle());
        $foreign = Organization::factory()->create();
        $this->plan->update(['organization_id' => $foreign->id]);
        $this->put(route('preventive-maintenance.automation', $this->plan), $input)->assertNotFound();
    }

    public function test_one_invalid_plan_is_visible_without_blocking_other_plans_and_can_be_paused(): void
    {
        $this->plan->update(['auto_generate_enabled' => true, 'frequency_days' => 0]);
        $valid = PreventiveMaintenancePlan::create(['organization_id' => $this->organization->id, 'property_id' => $this->plan->property_id, 'title' => 'Valid plan', 'frequency_days' => 30, 'next_due_on' => '2026-09-25', 'auto_generate_enabled' => true]);
        $this->assertSame(['created' => 1, 'failed' => 1], app(GenerateDuePreventiveMaintenance::class)->handle());
        $this->assertNotNull($this->plan->fresh()->automation_last_error);
        $this->assertSame('2026-09-25', $this->plan->fresh()->next_due_on->toDateString());
        $this->assertSame($valid->id, MaintenanceRequest::sole()->preventive_maintenance_plan_id);
        $this->assertSame(['created' => 0, 'failed' => 0], app(GenerateDuePreventiveMaintenance::class)->handle());
        $this->travel(61)->minutes();
        $this->artisan('operations:generate-preventive')->expectsOutput('Created 0 preventive jobs; 1 plans failed.')->assertExitCode(1);
        $foreign = Organization::factory()->create();
        $foreignProperty = Property::create(['organization_id' => $foreign->id, 'name' => 'Foreign', 'type' => 'residential']);
        $this->plan->update(['property_id' => $foreignProperty->id]);
        $this->actingAs($this->manager)->put(route('preventive-maintenance.automation', $this->plan), ['auto_generate_enabled' => false, 'requires_manager_confirmation' => false, 'is_active' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($this->plan->fresh()->auto_generate_enabled);
    }

    public function test_failed_audit_rolls_back_the_occurrence_and_schedule(): void
    {
        $this->plan->update(['auto_generate_enabled' => true]);
        $audit = \Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        $this->assertSame(['created' => 0, 'failed' => 1], app(GenerateDuePreventiveMaintenance::class)->handle());
        $this->assertDatabaseCount('maintenance_requests', 0);
        $this->assertSame('2026-09-25', $this->plan->fresh()->next_due_on->toDateString());
        $this->assertNotNull($this->plan->fresh()->automation_last_error);
    }

    public function test_manual_generation_and_automation_share_occurrence_identity_and_record_sources(): void
    {
        $this->plan->update(['auto_generate_enabled' => true]);
        $first = app(ManagePreventiveMaintenance::class)->generate($this->organization, $this->manager, $this->plan, '2026-09-25');
        $this->assertSame(['created' => 3, 'failed' => 0], app(GenerateDuePreventiveMaintenance::class)->handle());
        $retry = app(ManagePreventiveMaintenance::class)->generate($this->organization, $this->manager, $this->plan, '2026-09-25');
        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('maintenance_requests', 4);
        $this->assertSame(1, AuditLog::where('properties->source', 'manual')->count());
        $this->assertSame(3, AuditLog::where('properties->source', 'automatic')->count());
    }
}
