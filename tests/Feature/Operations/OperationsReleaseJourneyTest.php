<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Models\JobSlaCycle;
use App\Domain\Operations\Models\SparePart;
use App\Domain\Operations\Models\StockBalance;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Models\StockStore;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsReleaseJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_preventive_job_parts_sla_confirmation_reopening_and_reports_work_together(): void
    {
        $this->withoutVite();
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 9, 29)->setTime(4, 0));
        $organization = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $technician = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $organization->users()->attach($technician, ['role' => OrganizationRole::Member->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Release rehearsal property', 'type' => 'residential']);

        $this->actingAs($manager)->post(route('preventive-maintenance.store'), ['property_id' => $property->id, 'title' => 'Rehearsal service', 'frequency_days' => 30, 'next_due_on' => '2026-09-29'])->assertRedirect()->assertSessionHasNoErrors();
        $plan = PreventiveMaintenancePlan::sole();
        $this->put(route('preventive-maintenance.automation', $plan), ['auto_generate_enabled' => true, 'requires_manager_confirmation' => true, 'is_active' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->artisan('operations:generate-preventive')->expectsOutput('Created 1 preventive jobs; 0 plans failed.')->assertSuccessful();
        $this->artisan('operations:generate-preventive')->expectsOutput('Created 0 preventive jobs; 0 plans failed.')->assertSuccessful();
        $job = MaintenanceRequest::sole();
        $this->assertTrue($job->requires_manager_confirmation);
        $this->assertSame('2026-10-29', $plan->fresh()->next_due_on->toDateString());
        $this->put(route('maintenance.status.update', $job), ['status' => 'in_progress', 'assigned_to' => $technician->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.sla.enable', $job), ['days' => '1,2,3,4,5', 'start' => '09:00', 'end' => '17:00', 'holidays' => '', 'response_minutes' => 30, 'resolution_minutes' => 120])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.tasks', $job), ['label' => 'Inspect and repair', 'is_required' => true])->assertRedirect()->assertSessionHasNoErrors();
        $task = $job->jobTasks()->sole();

        $this->post(route('stock.parts'), ['code' => 'REPAIR', 'name' => 'Repair kit', 'unit' => 'pcs'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.stores'), ['code' => 'MAIN', 'name' => 'Main store'])->assertRedirect()->assertSessionHasNoErrors();
        $part = SparePart::sole();
        $store = StockStore::sole();
        $receipt = ['type' => 'receipt', 'operation_key' => (string) Str::uuid(), 'spare_part_id' => $part->id, 'stock_store_id' => $store->id, 'quantity' => '5', 'reference' => 'Rehearsal opening'];
        $this->post(route('stock.movements'), $receipt)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $receipt)->assertRedirect()->assertSessionHasNoErrors();
        $issue = [...$receipt, 'type' => 'issue', 'operation_key' => (string) Str::uuid(), 'maintenance_request_id' => $job->id, 'quantity' => '2', 'reference' => 'Rehearsal issue'];
        $this->post(route('stock.movements'), $issue)->assertRedirect()->assertSessionHasNoErrors();
        $issued = StockMovement::where('type', 'issue')->sole();
        $this->post(route('stock.movements'), ['type' => 'return', 'operation_key' => (string) Str::uuid(), 'related_movement_id' => $issued->id, 'quantity' => '1', 'reference' => 'Unused kit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(4000, StockBalance::sole()->quantity);

        $this->travel(10)->minutes();
        $this->actingAs($technician)->post(route('maintenance.sla.acknowledge', $job))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.notes', $job), ['note' => 'Repair in progress'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.costs', $job), ['category' => 'labor', 'description' => 'Service labor', 'quantity' => '1.25', 'unit_rate' => '10.50'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $issue)->assertForbidden();
        $this->post(route('maintenance.job-card.finish', $job))->assertSessionHasErrors('checklist');

        $this->actingAs($manager)->put(route('maintenance.status.update', $job), ['status' => 'on_hold', 'reason' => 'Waiting for access'])->assertRedirect()->assertSessionHasNoErrors();
        $this->travel(30)->minutes();
        $this->put(route('maintenance.status.update', $job), ['status' => 'in_progress'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($technician)->put(route('maintenance.job-card.tasks.check', [$job, $task]), ['complete' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.finish', $job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(JobSlaCycle::sole()->closed_at);
        $this->actingAs($manager)->post(route('stock.movements'), [...$issue, 'operation_key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->travel(111)->minutes();
        $this->artisan('operations:notify-sla-breaches')->expectsOutput('Created 1 SLA breach notifications.')->assertSuccessful();
        $this->artisan('operations:notify-sla-breaches')->expectsOutput('Created 0 SLA breach notifications.')->assertSuccessful();
        $this->post(route('maintenance.job-card.confirm', $job))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page->where('summary.statuses.completed', 1)->where('summary.costs.0.recorded_cost', '13.13')->where('summary.sla.completed_resolution_breaches', 1)->where('summary.preventive.completed_on_time', 1));
        $closedAt = JobSlaCycle::sole()->closed_at;
        $this->post(route('maintenance.job-card.reopen', $job), ['reason' => 'Review found another repair'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($closedAt->equalTo(JobSlaCycle::where('cycle_number', 1)->sole()->closed_at));
        $this->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page->where('summary.sla.active_cycles', 1)->where('summary.sla.completed_cycles', 1)->where('summary.sla.active_resolution_breaches', 0)->where('summary.statuses.in_progress', 1));
        $this->assertStringContainsString('sla,completed_resolution_breaches,1', $this->get(route('operations.reports.export'))->streamedContent());
        $this->actingAs($technician)->get(route('maintenance.job-card', $job))->assertInertia(fn (Assert $page) => $page->has('stockMovements', 2)->has('slaCycles', 2)->where('totals.total', '13.13'));

        $foreign = Organization::factory()->create();
        $foreignManager = User::factory()->create(['current_organization_id' => $foreign->id]);
        $foreign->users()->attach($foreignManager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($foreignManager)->get(route('maintenance.job-card', $job))->assertNotFound();
        $this->post(route('maintenance.sla.acknowledge', $job))->assertNotFound();
        $this->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page->where('jobs.total', 0));
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('vendor_bills', 0);
    }
}
