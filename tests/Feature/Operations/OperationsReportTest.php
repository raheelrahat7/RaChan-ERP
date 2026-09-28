<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Models\JobCostLine;
use App\Domain\Operations\Models\JobSlaCycle;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $technician;

    private Property $property;

    private MaintenanceVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 9, 29)->setTime(7, 0));
        $this->organization = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->technician = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->organization->users()->attach($this->technician, ['role' => OrganizationRole::Member->value]);
        $this->property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Property', 'type' => 'residential']);
        $this->vendor = MaintenanceVendor::create(['organization_id' => $this->organization->id, 'name' => 'Vendor']);
        $this->actingAs($this->manager);
    }

    /** @param array<string, mixed> $changes */
    private function job(array $changes = [], int $age = 0): MaintenanceRequest
    {
        $job = MaintenanceRequest::create([...['organization_id' => $this->organization->id, 'property_id' => $this->property->id, 'vendor_id' => $this->vendor->id, 'reference' => 'MNT-'.(MaintenanceRequest::count() + 1), 'title' => 'Repair', 'assigned_to' => $this->technician->id], ...$changes]);
        $job->forceFill(['created_at' => now()->subDays($age)])->save();

        return $job;
    }

    public function test_current_status_aging_and_workload_follow_job_filters(): void
    {
        $this->job(['status' => 'open', 'priority' => 'urgent'], 2);
        $this->job(['status' => 'in_progress'], 7);
        $this->job(['status' => 'on_hold', 'assigned_to' => null], 30);
        $this->job(['status' => 'open', 'assigned_to' => null], 31);
        $this->job(['status' => 'completed', 'completed_at' => now()], 40);
        $this->job(['status' => 'cancelled'], 50);
        $this->get(route('operations.reports'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('operations/Reports')
            ->where('summary.statuses', ['open' => 2, 'in_progress' => 1, 'on_hold' => 1, 'completed' => 1, 'cancelled' => 1])
            ->where('summary.aging', ['0–2 days' => 1, '3–7 days' => 1, '8–30 days' => 1, '31+ days' => 1])
            ->where('summary.workload.0.active', 2)->where('summary.workload.0.completed', 1)
            ->where('summary.workload.1.assignee', 'Unassigned')->where('summary.workload.1.active', 2)->where('summary.workload.1.on_hold', 1));
        $this->get(route('operations.reports', ['status' => 'open', 'priority' => 'urgent', 'assigned_to' => $this->technician->id, 'property_id' => $this->property->id, 'vendor_id' => $this->vendor->id]))
            ->assertInertia(fn (Assert $page) => $page->where('jobs.total', 1)->where('summary.statuses.open', 1)->where('summary.aging.0–2 days', 1));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_cost_totals_are_exact_separate_by_currency_and_exclude_voided_or_foreign_lines(): void
    {
        $job = $this->job(['estimated_cost' => '0.10', 'actual_cost' => '0.20', 'currency' => 'AED']);
        $second = $this->job(['estimated_cost' => '0.20', 'currency' => 'AED']);
        $this->job(['estimated_cost' => '3.00', 'actual_cost' => '4.00', 'currency' => 'USD']);
        foreach ([[$job, 'labor', '0.10', null], [$second, 'labor', '0.20', null], [$job, 'material', '1.25', null], [$job, 'material', '99.00', now()]] as [$target, $category, $amount, $voided]) {
            JobCostLine::create(['organization_id' => $this->organization->id, 'maintenance_request_id' => $target->id, 'recorded_by' => $this->manager->id, 'category' => $category, 'description' => 'Work', 'quantity' => '1', 'unit_rate' => $amount, 'amount' => $amount, 'voided_at' => $voided]);
        }
        $foreign = Organization::factory()->create();
        JobCostLine::create(['organization_id' => $foreign->id, 'maintenance_request_id' => $job->id, 'category' => 'labor', 'description' => 'Foreign', 'quantity' => '1', 'unit_rate' => '50', 'amount' => '50']);
        $this->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page->has('summary.costs', 2)
            ->where('summary.costs.0.currency', 'AED')->where('summary.costs.0.estimated_cost', '0.30')->where('summary.costs.0.actual_cost', '0.20')
            ->where('summary.costs.0.labor_cost', '0.30')->where('summary.costs.0.material_cost', '1.25')->where('summary.costs.0.recorded_cost', '1.55')->where('summary.costs.0.missing_actuals', 1)
            ->where('summary.costs.1.currency', 'USD')->where('summary.costs.1.actual_cost', '4.00'));
        $csv = $this->get(route('operations.reports.export'))->streamedContent();
        $this->assertStringContainsString('AED,0.30,0.20,0.30,1.25,1.55,0,1', $csv);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_preventive_results_use_local_due_day_and_latest_completion_without_counting_future_jobs(): void
    {
        $plan = PreventiveMaintenancePlan::create(['organization_id' => $this->organization->id, 'property_id' => $this->property->id, 'title' => 'Plan', 'frequency_days' => 1, 'next_due_on' => '2026-10-01']);
        foreach ([
            ['2026-09-27', 'completed', '2026-09-27 18:59:59'],
            ['2026-09-28', 'completed', '2026-09-28 19:00:00'],
            ['2026-09-26', 'in_progress', null],
            ['2026-09-29', 'open', null],
            ['2026-09-25', 'cancelled', null],
            ['2026-10-01', 'open', null],
            ['2026-09-24', 'completed', null],
        ] as [$due, $status, $completed]) {
            $this->job(['preventive_maintenance_plan_id' => $plan->id, 'preventive_due_on' => $due, 'status' => $status, 'completed_at' => $completed]);
        }
        $this->job();
        $this->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page
            ->where('summary.preventive', ['due_today' => 1, 'outstanding_overdue' => 1, 'completed_on_time' => 1, 'completed_late' => 1, 'completion_date_unknown' => 1, 'cancelled' => 1, 'completed_on_time_percent' => 50])
            ->where('jobs.total', 8));
    }

    public function test_sla_report_counts_preserved_completed_and_active_cycles_and_excludes_cancellations(): void
    {
        $job = $this->job();
        foreach ([['completed', now()->subMinutes(10)], [null, null], ['cancelled', now()->subMinutes(5)]] as $index => [$outcome, $closed]) {
            JobSlaCycle::create(['organization_id' => $this->organization->id, 'maintenance_request_id' => $job->id, 'cycle_number' => $index + 1, 'timezone' => 'Asia/Karachi', 'working_days' => [2 => ['start' => '09:00', 'end' => '17:00']], 'holidays' => [], 'response_seconds' => 1800, 'resolution_seconds' => 1800, 'started_at' => now()->subHour(), 'holds' => [], 'closed_at' => $closed, 'outcome' => $outcome]);
        }
        $this->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page
            ->where('summary.sla', ['active_cycles' => 1, 'completed_cycles' => 1, 'cancelled_cycles' => 1, 'active_response_breaches' => 1, 'active_resolution_breaches' => 1, 'completed_response_breaches' => 1, 'completed_resolution_breaches' => 1])
            ->has('jobs.data.0.sla_cycles', 3));
        $csv = $this->get(route('operations.reports.export'))->streamedContent();
        $this->assertStringContainsString('sla,completed_resolution_breaches,1', $csv);
        $this->assertStringContainsString('MNT-1,1,completed,', $csv);
        $this->assertStringContainsString('MNT-1,2,active,', $csv);
    }

    public function test_csv_uses_same_creation_cohort_in_local_timezone_exports_all_pages_and_escapes_formulas(): void
    {
        $this->property->update(['name' => '=Property']);
        $this->vendor->update(['name' => '+Vendor']);
        $this->technician->update(['name' => '@Technician']);
        for ($index = 0; $index < 26; $index++) {
            $job = $this->job(['title' => '=SUM(1,1)']);
            $job->forceFill(['created_at' => $index === 0 ? '2026-09-28 19:00:00' : '2026-09-29 18:59:59'])->save();
        }
        $before = $this->job(['title' => 'Before day']);
        $before->forceFill(['created_at' => '2026-09-28 18:59:59'])->save();
        $after = $this->job(['title' => 'After day']);
        $after->forceFill(['created_at' => '2026-09-29 19:00:00'])->save();
        $filters = ['created_from' => '2026-09-29', 'created_to' => '2026-09-29', 'property_id' => $this->property->id, 'vendor_id' => $this->vendor->id];
        $this->get(route('operations.reports', $filters))->assertInertia(fn (Assert $page) => $page->where('jobs.total', 26)->has('jobs.data', 25)->where('summary.statuses.open', 26));
        $this->get(route('operations.reports', [...$filters, 'page' => 2]))->assertInertia(fn (Assert $page) => $page->has('jobs.data', 1));
        $csv = $this->get(route('operations.reports.export', [...$filters, 'page' => 2]))->assertOk()->streamedContent();
        $this->assertSame(26, substr_count($csv, "\"'=SUM(1,1)\""));
        $this->assertStringContainsString('statuses,open,26', $csv);
        $this->assertStringContainsString("'=Property,'+Vendor,'@Technician", $csv);
        $this->assertStringNotContainsString('Before day', $csv);
        $this->assertStringNotContainsString('After day', $csv);
    }

    public function test_assignment_and_organization_access_apply_to_summary_rows_and_csv(): void
    {
        $own = $this->job(['title' => 'Assigned work']);
        $this->job(['assigned_to' => $this->manager->id, 'title' => 'Manager private work']);
        $foreign = Organization::factory()->create();
        $foreignProperty = Property::create(['organization_id' => $foreign->id, 'name' => 'Foreign property', 'type' => 'residential']);
        $this->job(['organization_id' => $foreign->id, 'property_id' => $foreignProperty->id, 'title' => 'Foreign work']);
        $this->actingAs($this->technician)->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page->where('jobs.total', 1)->where('jobs.data.0.id', $own->id)->where('summary.statuses.open', 1)->has('members', 1));
        $csv = $this->get(route('operations.reports.export'))->streamedContent();
        $this->assertStringContainsString('Assigned work', $csv);
        $this->assertStringNotContainsString('Manager private work', $csv);
        $this->assertStringNotContainsString('Foreign work', $csv);
        $this->get(route('operations.reports', ['assigned_to' => $this->manager->id]))->assertInertia(fn (Assert $page) => $page->where('jobs.total', 0));
        $this->get(route('operations.reports', ['property_id' => $foreignProperty->id]))->assertSessionHasErrors('property_id');
        $this->get(route('operations.reports.export', ['property_id' => $foreignProperty->id]))->assertSessionHasErrors('property_id');
        $outsider = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->actingAs($outsider)->get(route('operations.reports'))->assertForbidden();
        $this->get(route('operations.reports.export'))->assertForbidden();
    }

    public function test_empty_reports_and_invalid_dates_do_not_invent_results(): void
    {
        $this->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page->where('jobs.total', 0)->has('summary.costs', 0)->has('summary.workload', 0)->where('summary.preventive.completed_on_time_percent', null));
        $this->get(route('operations.reports', ['created_from' => '2026-02-30']))->assertSessionHasErrors('created_from');
        $this->get(route('operations.reports', ['created_from' => '2026-09-30', 'created_to' => '2026-09-29']))->assertSessionHasErrors('created_to');
        $this->get(route('operations.reports', ['created_to' => '2026-09-29']))->assertSessionHasNoErrors();
        $this->get(route('operations.reports', ['priority' => 'invalid', 'status' => 'invalid']))->assertSessionHasErrors(['priority', 'status']);
    }
}
