<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Domain\Operations\Actions\NotifySlaBreaches;
use App\Domain\Operations\Models\JobSlaCycle;
use App\Domain\Operations\Services\JobSlaClock;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class JobSlaTest extends TestCase
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
        $this->travelTo(now()->setDate(2026, 9, 29)->setTime(4, 0)); // 09:00 Karachi.
        $this->organization = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->technician = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->organization->users()->attach($this->technician, ['role' => OrganizationRole::Member->value]);
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Service property', 'type' => 'residential']);
        $this->job = MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $property->id, 'reference' => 'MNT-SLA', 'title' => 'Repair', 'assigned_to' => $this->technician->id]);
        $this->actingAs($this->manager);
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        return ['days' => '1,2,3,4,5', 'start' => '09:00', 'end' => '17:00', 'holidays' => '2026-09-30', 'response_minutes' => 30, 'resolution_minutes' => 120];
    }

    private function enable(): void
    {
        $this->post(route('maintenance.sla.enable', $this->job), $this->policy())->assertRedirect()->assertSessionHasNoErrors();
    }

    private function changeStatus(string $status, string $reason = ''): void
    {
        $this->actingAs($this->manager)->put(route('maintenance.status.update', $this->job), ['status' => $status, 'reason' => $reason])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_calendar_snapshot_and_explicit_acknowledgement_are_preserved_on_retries(): void
    {
        $this->enable();
        $this->changeStatus('in_progress');
        $this->assertNull(JobSlaCycle::sole()->acknowledged_at);
        $this->travel(15)->minutes();
        $this->actingAs($this->technician)->post(route('maintenance.sla.acknowledge', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $first = JobSlaCycle::sole()->acknowledged_at;
        $this->travel(10)->minutes();
        $this->post(route('maintenance.sla.acknowledge', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($first->equalTo(JobSlaCycle::sole()->acknowledged_at));
        $this->assertSame($this->technician->id, JobSlaCycle::sole()->acknowledged_by);
        $this->assertSame(900, app(JobSlaClock::class)->summary(JobSlaCycle::sole())['response_elapsed_seconds']);
        $this->organization->update(['timezone' => 'UTC']);
        $this->assertSame('Asia/Karachi', JobSlaCycle::sole()->timezone);
        $this->actingAs($this->manager)->post(route('maintenance.sla.enable', $this->job), $this->policy())->assertSessionHasErrors('sla');
        $this->assertDatabaseCount('job_sla_cycles', 1);
    }

    public function test_only_reasoned_manager_holds_pause_both_targets(): void
    {
        $this->enable();
        $this->travel(10)->minutes();
        $this->put(route('maintenance.status.update', $this->job), ['status' => 'on_hold'])->assertSessionHasErrors('sla');
        $this->assertSame('open', $this->job->fresh()->status);
        $this->changeStatus('on_hold', 'Waiting for property access');
        $this->travel(60)->minutes();
        $summary = app(JobSlaClock::class)->summary(JobSlaCycle::sole());
        $this->assertSame(600, $summary['response_elapsed_seconds']);
        $this->assertSame(600, $summary['resolution_elapsed_seconds']);
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertSessionHasErrors('status');
        $this->changeStatus('in_progress');
        $this->travel(5)->minutes();
        $this->assertSame(900, app(JobSlaClock::class)->summary(JobSlaCycle::sole())['resolution_elapsed_seconds']);
        $this->assertSame('Waiting for property access', JobSlaCycle::sole()->holds[0]['reason']);
        $this->assertNull(JobSlaCycle::sole()->held_at);
    }

    public function test_confirmation_closes_cycle_and_reopening_preserves_history(): void
    {
        $this->job->update(['requires_manager_confirmation' => true]);
        $this->enable();
        $this->travel(60)->minutes();
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(JobSlaCycle::sole()->closed_at);
        $this->travel(61)->minutes();
        $this->actingAs($this->manager)->post(route('maintenance.job-card.confirm', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $old = JobSlaCycle::sole();
        $this->assertTrue(app(JobSlaClock::class)->summary($old)['resolution_breached']);
        $closed = $old->closed_at;
        $this->travel(10)->minutes();
        $this->post(route('maintenance.job-card.confirm', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($closed->equalTo($old->fresh()->closed_at));
        $this->post(route('maintenance.job-card.reopen', $this->job), ['reason' => 'More work needed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('job_sla_cycles', 2);
        $new = JobSlaCycle::orderByDesc('id')->firstOrFail();
        $this->assertSame(2, $new->cycle_number);
        $this->assertNull($new->acknowledged_at);
        $this->assertSame(0, app(JobSlaClock::class)->summary($new)['resolution_elapsed_seconds']);
        $this->assertTrue($closed->equalTo($old->fresh()->closed_at));
        $this->changeStatus('in_progress');
        $this->assertDatabaseCount('job_sla_cycles', 2);
    }

    public function test_unchecked_completion_closes_without_confirmation_and_cancelled_jobs_have_no_breach_result(): void
    {
        $this->enable();
        $this->actingAs($this->technician)->post(route('maintenance.job-card.finish', $this->job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', JobSlaCycle::sole()->outcome);
        $this->changeStatus('in_progress', 'Follow-up');
        $this->travel(150)->minutes();
        $this->changeStatus('cancelled', 'Duplicate');
        $cycle = JobSlaCycle::orderByDesc('id')->firstOrFail();
        $this->assertSame('cancelled', $cycle->outcome);
        $this->assertFalse(app(JobSlaClock::class)->summary($cycle)['resolution_breached']);
        $this->assertSame(0, app(NotifySlaBreaches::class)->handle());
    }

    public function test_breach_alerts_are_repeat_safe_and_only_go_to_operations_managers(): void
    {
        $this->enable();
        $this->travel(30)->minutes();
        $this->assertSame(0, app(NotifySlaBreaches::class)->handle());
        $this->travel(1)->seconds();
        $this->assertSame(1, app(NotifySlaBreaches::class)->handle());
        $this->assertSame(0, app(NotifySlaBreaches::class)->handle());
        $this->travel(90)->minutes();
        $this->assertSame(1, app(NotifySlaBreaches::class)->handle());
        $this->assertDatabaseCount('organization_notifications', 2);
        $this->assertSame([$this->manager->id], OrganizationNotification::distinct()->pluck('user_id')->all());
    }

    public function test_technicians_only_see_and_acknowledge_assigned_jobs_and_cannot_enable_policies(): void
    {
        $this->enable();
        $unassigned = $this->job->replicate();
        $unassigned->reference = 'MNT-OTHER';
        $unassigned->assigned_to = null;
        $unassigned->save();
        $this->actingAs($this->technician)->get(route('helpdesk.index'))->assertInertia(fn (Assert $page) => $page->component('operations/Helpdesk')->has('jobs.data', 1)->where('jobs.data.0.id', $this->job->id));
        $this->get(route('maintenance.job-card', $this->job))->assertInertia(fn (Assert $page) => $page->has('slaCycles', 1));
        $this->post(route('maintenance.sla.enable', $this->job), $this->policy())->assertForbidden();
        $this->post(route('maintenance.sla.acknowledge', $unassigned))->assertNotFound();
        $this->actingAs($this->manager)->get(route('helpdesk.index', ['search' => 'OTHER']))->assertInertia(fn (Assert $page) => $page->has('jobs.data', 1)->where('jobs.data.0.id', $unassigned->id));
        $other = Organization::factory()->create();
        $unassigned->organization_id = $other->id;
        $unassigned->save();
        $this->post(route('maintenance.sla.enable', $unassigned), $this->policy())->assertNotFound();
        $this->post(route('maintenance.sla.acknowledge', $unassigned))->assertNotFound();
    }

    public function test_invalid_calendar_and_targets_leave_no_partial_policy(): void
    {
        foreach ([['holidays' => '2026-02-30'], ['days' => '0,1'], ['start' => '18:00'], ['resolution_minutes' => 15]] as $changes) {
            $this->post(route('maintenance.sla.enable', $this->job), [...$this->policy(), ...$changes])->assertSessionHasErrors('sla');
        }
        $this->assertDatabaseCount('job_sla_cycles', 0);
        $this->changeStatus('on_hold');
        $this->post(route('maintenance.sla.enable', $this->job), $this->policy())->assertSessionHasErrors('sla');
    }

    public function test_audit_failure_rolls_back_policy_creation(): void
    {
        $this->mock(RecordOrganizationAuditLog::class)->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit failed'));
        $this->withoutExceptionHandling();
        try {
            $this->post(route('maintenance.sla.enable', $this->job), $this->policy());
            $this->fail('Audit failure expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('job_sla_cycles', 0);
    }
}
