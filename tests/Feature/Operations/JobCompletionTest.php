<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Actions\ManageJobCompletion;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class JobCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $technician;

    private MaintenanceRequest $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->organization = Organization::factory()->create();
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->technician = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->organization->users()->attach($this->technician, ['role' => OrganizationRole::Member->value]);
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Service property', 'type' => 'residential']);
        $this->job = MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $property->id, 'reference' => 'MNT-POLICY', 'title' => 'Service job', 'assigned_to' => $this->technician->id]);
    }

    public function test_confirmation_is_selected_at_creation_and_defaults_to_direct_completion(): void
    {
        $input = ['property_id' => $this->job->property_id, 'title' => 'Created job', 'priority' => 'medium'];
        $this->actingAs($this->manager)->post(route('maintenance.store'), [...$input, 'requires_manager_confirmation' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(MaintenanceRequest::latest('id')->first()->requires_manager_confirmation);
        $this->post(route('maintenance.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(MaintenanceRequest::latest('id')->first()->requires_manager_confirmation);
        $this->actingAs($this->technician)->post(route('maintenance.store'), $input)->assertForbidden();
    }

    public function test_generated_job_uses_the_selected_policy_and_retries_cannot_change_it(): void
    {
        $plan = PreventiveMaintenancePlan::create(['organization_id' => $this->organization->id, 'property_id' => $this->job->property_id, 'title' => 'Recurring service', 'frequency_days' => 30, 'next_due_on' => now()->toDateString()]);
        $input = ['due_on' => $plan->next_due_on->toDateString(), 'requires_manager_confirmation' => true];
        $this->actingAs($this->manager)->post(route('preventive-maintenance.generate', $plan), $input)->assertRedirect()->assertSessionHasNoErrors();
        $job = MaintenanceRequest::where('preventive_maintenance_plan_id', $plan->id)->sole();
        $this->assertTrue($job->requires_manager_confirmation);
        $this->post(route('preventive-maintenance.generate', $plan), [...$input, 'requires_manager_confirmation' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($job->fresh()->requires_manager_confirmation);
        $this->assertSame(1, MaintenanceRequest::where('preventive_maintenance_plan_id', $plan->id)->count());
    }

    public function test_direct_completion_and_manager_reopening_preserve_history_and_retry_dates(): void
    {
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $this->job->fresh()->status);
        $this->assertSame($this->technician->id, $this->job->fresh()->completed_by);
        $first = $this->job->fresh()->completed_at->toDateTimeString();
        $this->travel(1)->days();
        $this->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($first, $this->job->fresh()->completed_at->toDateTimeString());
        $this->post(route('maintenance.job-card.reopen', $this->job), ['reason' => 'Not satisfied'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('maintenance.job-card.reopen', $this->job), ['reason' => '   '])->assertSessionHasErrors('reason');
        $this->post(route('maintenance.job-card.reopen', $this->job), ['reason' => 'Repair needs further testing'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $this->job->fresh()->status);
        $this->assertNull($this->job->fresh()->completed_at);
        $this->assertNull($this->job->fresh()->completed_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.job.reopened', 'actor_id' => $this->manager->id, 'subject_id' => $this->job->id]);
        $this->get(route('maintenance.job-card', $this->job))->assertInertia(fn (Assert $page) => $page->where('history.0.properties.reason', 'Repair needs further testing')->where('canEdit', true));
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotSame($first, $this->job->fresh()->completed_at->toDateTimeString());
    }

    public function test_review_submission_is_read_only_and_only_manager_can_confirm(): void
    {
        $this->job->update(['requires_manager_confirmation' => true]);
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $this->job->fresh()->status);
        $this->assertNull($this->job->fresh()->completed_at);
        $this->assertSame($this->technician->id, $this->job->fresh()->submitted_by);
        $submitted = $this->job->fresh()->submitted_at->toDateTimeString();
        $this->travel(1)->hours();
        $this->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($submitted, $this->job->fresh()->submitted_at->toDateTimeString());
        $this->post(route('maintenance.job-card.notes', $this->job), ['note' => 'Changed after submission'])->assertUnprocessable();
        $this->get(route('maintenance.job-card', $this->job))->assertInertia(fn (Assert $page) => $page->where('canEdit', false)->where('submitter.name', $this->technician->name));
        $this->post(route('maintenance.job-card.confirm', $this->job))->assertForbidden();
        $this->actingAs($this->manager)->put(route('maintenance.work-order.update', $this->job), ['actual_cost' => 10])->assertUnprocessable();
        $this->put(route('maintenance.status.update', $this->job), ['status' => 'completed', 'assigned_to' => $this->manager->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($this->manager)->post(route('maintenance.job-card.confirm', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $job = $this->job->fresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame($this->manager->id, $job->confirmed_by);
        $this->assertNotNull($job->confirmed_at);
        $completed = $job->completed_at->toDateTimeString();
        $this->travel(1)->days();
        $this->post(route('maintenance.job-card.confirm', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($completed, $this->job->fresh()->completed_at->toDateTimeString());
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.job.confirmed', 'actor_id' => $this->manager->id]);
        $this->post(route('maintenance.job-card.reopen', $this->job), ['reason' => 'Further changes required'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->job->fresh()->submitted_by);
        $this->assertNull($this->job->fresh()->confirmed_by);
        $this->actingAs($this->technician)->post(route('maintenance.job-card.notes', $this->job), ['note' => 'Additional changes made'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->job->fresh()->completed_at);
        $this->assertNotSame($submitted, $this->job->fresh()->submitted_at->toDateTimeString());
        $this->actingAs($this->manager)->post(route('maintenance.job-card.confirm', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotSame($completed, $this->job->fresh()->completed_at->toDateTimeString());
    }

    public function test_existing_status_route_cannot_bypass_confirmation_or_reopening_reason(): void
    {
        $this->job->update(['requires_manager_confirmation' => true]);
        $this->actingAs($this->manager)->put(route('maintenance.status.update', $this->job), ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->post(route('maintenance.job-card.confirm', $this->job))->assertSessionHasErrors('status');
        $this->assertSame('open', $this->job->fresh()->status);
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->put(route('maintenance.status.update', $this->job), ['status' => 'in_progress', 'assigned_to' => $this->manager->id])->assertSessionHasErrors('reason');
        $this->put(route('maintenance.status.update', $this->job), ['status' => 'completed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, $this->job->fresh()->confirmed_by);
        $this->put(route('maintenance.status.update', $this->job), ['status' => 'open'])->assertSessionHasErrors('reason');
        $this->put(route('maintenance.status.update', $this->job), ['status' => 'open', 'reason' => 'Additional changes'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->job->fresh()->submitted_at);
        $this->assertNull($this->job->fresh()->confirmed_at);
        $this->assertTrue($this->job->fresh()->requires_manager_confirmation);
        $this->put(route('maintenance.work-order.update', $this->job), ['requires_manager_confirmation' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->job->fresh()->requires_manager_confirmation);
    }

    public function test_required_checklist_blocks_submission_direct_completion_and_legacy_completion(): void
    {
        $task = $this->job->jobTasks()->create(['organization_id' => $this->organization->id, 'label' => 'Required test', 'is_required' => true]);
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertSessionHasErrors('checklist');
        $this->actingAs($this->manager)->put(route('maintenance.status.update', $this->job), ['status' => 'completed'])->assertSessionHasErrors('checklist');
        $this->job->update(['requires_manager_confirmation' => true]);
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertSessionHasErrors('checklist');
        $this->put(route('maintenance.job-card.tasks.check', [$this->job, $task]), ['complete' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('maintenance.job-card.tasks.check', [$this->job, $task]), ['complete' => false])->assertUnprocessable();
        $this->actingAs($this->manager)->post(route('maintenance.job-card.reopen', $this->job), ['reason' => 'Add more testing'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.tasks', $this->job), ['label' => 'Extra test', 'is_required' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertSessionHasErrors('checklist');
        $this->assertDatabaseCount('maintenance_job_tasks', 2);
    }

    public function test_cancellation_requires_reason_and_work_order_edits_require_reopening(): void
    {
        $this->actingAs($this->manager)->put(route('maintenance.status.update', $this->job), ['status' => 'cancelled'])->assertSessionHasErrors('reason');
        $this->put(route('maintenance.status.update', $this->job), ['status' => 'cancelled', 'reason' => 'Work no longer needed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('maintenance.work-order.update', $this->job), ['actual_cost' => 10])->assertUnprocessable();
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertSessionHasErrors('status');
        $this->actingAs($this->manager)->put(route('maintenance.status.update', $this->job), ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->post(route('maintenance.job-card.reopen', $this->job), ['reason' => 'Work needed again'])->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('maintenance.work-order.update', $this->job), ['actual_cost' => 10])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_reassigned_and_removed_members_cannot_finish_a_job(): void
    {
        $this->job->update(['assigned_to' => $this->manager->id]);
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertNotFound();
        $this->job->update(['assigned_to' => $this->technician->id]);
        $this->organization->users()->detach($this->technician);
        $this->post(route('maintenance.job-card.finish', $this->job))->assertNotFound();
        $this->assertSame('open', $this->job->fresh()->status);
    }

    public function test_completion_rolls_back_when_audit_fails(): void
    {
        $audit = \Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(ManageJobCompletion::class)->finish($this->organization, $this->technician, $this->job);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('open', $this->job->fresh()->status);
        $this->assertNull($this->job->fresh()->completed_at);
        $this->assertNull($this->job->fresh()->completed_by);
    }
}
