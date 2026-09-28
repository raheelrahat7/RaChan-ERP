<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Actions\ManagePreventiveMaintenance;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class PreventiveMaintenanceReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private PreventiveMaintenancePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->setDate(2026, 9, 27)->setTime(20, 30));
        $this->organization = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Local', 'type' => 'residential']);
        $this->plan = PreventiveMaintenancePlan::create(['organization_id' => $this->organization->id, 'property_id' => $property->id, 'title' => 'Daily inspection', 'frequency_days' => 1, 'next_due_on' => '2026-09-25']);
        $this->actingAs($this->manager);
    }

    public function test_retry_of_old_occurrence_does_not_generate_next_overdue_occurrence(): void
    {
        $data = ['due_on' => '2026-09-25'];
        $this->post(route('preventive-maintenance.generate', $this->plan), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('preventive-maintenance.generate', $this->plan), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('maintenance_requests', 1);
        $this->assertSame('2026-09-26', $this->plan->fresh()->next_due_on->toDateString());
        $this->assertSame(1, AuditLog::where('event', 'operations.preventive_plan.generated')->count());
        $this->post(route('preventive-maintenance.generate', $this->plan), ['due_on' => '2026-09-26'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('maintenance_requests', 2);
        $this->assertSame('2026-09-27', $this->plan->fresh()->next_due_on->toDateString());
    }

    public function test_stale_model_calls_share_one_occurrence_and_database_uniqueness_enforces_it(): void
    {
        $stale = PreventiveMaintenancePlan::findOrFail($this->plan->id);
        $action = app(ManagePreventiveMaintenance::class);
        $first = $action->generate($this->organization, $this->manager, $this->plan, '2026-09-25');
        $second = $action->generate($this->organization, $this->manager, $stale, '2026-09-25');
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('maintenance_requests', 1);
        $this->expectException(QueryException::class);
        MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $this->plan->property_id, 'reference' => 'DUPLICATE', 'title' => 'Duplicate', 'preventive_maintenance_plan_id' => $this->plan->id, 'preventive_due_on' => '2026-09-25']);
    }

    public function test_generation_failure_rolls_back_request_schedule_and_audit(): void
    {
        $audit = Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(ManagePreventiveMaintenance::class)->generate($this->organization, $this->manager, $this->plan, '2026-09-25');
            $this->fail('Generation should fail when auditing fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('maintenance_requests', 0);
        $this->assertSame('2026-09-25', $this->plan->fresh()->next_due_on->toDateString());
        $this->assertSame(0, AuditLog::where('event', 'operations.preventive_plan.generated')->count());
    }

    public function test_local_date_is_used_for_eligibility_page_and_utc_deadline(): void
    {
        $this->plan->update(['next_due_on' => '2026-09-28']);
        $this->get(route('preventive-maintenance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('today', '2026-09-28')->where('plans.0.next_due_on', '2026-09-28'));
        $this->post(route('preventive-maintenance.generate', $this->plan), ['due_on' => '2026-09-28'])->assertRedirect()->assertSessionHasNoErrors();
        $item = MaintenanceRequest::sole();
        $this->assertSame('2026-09-28', $item->preventive_due_on->toDateString());
        $this->assertSame('2026-09-28 18:59:59', $item->due_at->utc()->toDateTimeString());
        $this->assertSame('2026-09-29', $this->plan->fresh()->next_due_on->toDateString());
    }

    public function test_missing_future_changed_and_inactive_occurrences_are_rejected(): void
    {
        $route = route('preventive-maintenance.generate', $this->plan);
        $this->post($route)->assertSessionHasErrors('due_on');
        $this->post($route, ['due_on' => 'invalid'])->assertSessionHasErrors('due_on');
        $this->post($route, ['due_on' => '2026-09-24'])->assertSessionHasErrors('due_on');
        $this->plan->update(['next_due_on' => '2026-09-29']);
        $this->post($route, ['due_on' => '2026-09-29'])->assertSessionHasErrors('due_on');
        $this->plan->update(['next_due_on' => '2026-09-25', 'is_active' => false]);
        $this->post($route, ['due_on' => '2026-09-25'])->assertSessionHasErrors('due_on');
        $this->assertDatabaseCount('maintenance_requests', 0);
        $this->assertSame('2026-09-25', $this->plan->fresh()->next_due_on->toDateString());
    }

    public function test_foreign_plans_links_and_nonmanagers_are_denied_without_generation(): void
    {
        $foreign = Organization::factory()->create();
        $foreignProperty = Property::create(['organization_id' => $foreign->id, 'name' => 'Foreign', 'type' => 'residential']);
        $foreignPlan = PreventiveMaintenancePlan::create(['organization_id' => $foreign->id, 'property_id' => $foreignProperty->id, 'title' => 'Foreign', 'frequency_days' => 1, 'next_due_on' => '2026-09-25']);
        $this->post(route('preventive-maintenance.generate', $foreignPlan), ['due_on' => '2026-09-25'])->assertNotFound();
        $this->plan->update(['property_id' => $foreignProperty->id]);
        $this->post(route('preventive-maintenance.generate', $this->plan), ['due_on' => '2026-09-25'])->assertNotFound();
        $this->organization->users()->updateExistingPivot($this->manager->id, ['role' => OrganizationRole::Viewer->value]);
        $this->post(route('preventive-maintenance.generate', $this->plan), ['due_on' => '2026-09-25'])->assertForbidden();
        $this->assertDatabaseCount('maintenance_requests', 0);
        $this->assertSame('2026-09-25', $this->plan->fresh()->next_due_on->toDateString());
    }
}
