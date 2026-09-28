<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_counts_workload_and_plan_window_follow_both_filters_and_organization_timezone(): void
    {
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 9, 22)->setTime(20, 30));
        [$organization, $viewer, $property, $vendor] = $this->context();
        $otherProperty = Property::create(['organization_id' => $organization->id, 'name' => 'Second property', 'type' => 'residential']);
        $otherVendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Second vendor']);
        foreach ([
            ['open', 'urgent', now()->subMinute(), $viewer->id],
            ['in_progress', 'medium', now(), null],
            ['on_hold', 'high', now()->subDay(), $viewer->id],
            ['completed', 'urgent', now()->subDay(), $viewer->id],
            ['cancelled', 'medium', now()->subDay(), null],
        ] as $index => [$status, $priority, $dueAt, $assignee]) {
            MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'vendor_id' => $vendor->id, 'reference' => 'MNT-'.$index, 'title' => 'Request '.$index, 'status' => $status, 'priority' => $priority, 'due_at' => $dueAt, 'assigned_to' => $assignee]);
        }
        MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $otherProperty->id, 'vendor_id' => $otherVendor->id, 'reference' => 'MNT-OTHER-PROPERTY', 'title' => 'Other property request']);
        foreach ([['2026-09-23', true], ['2026-09-30', true], ['2026-10-01', true], ['2026-09-22', false]] as $index => [$dueOn, $active]) {
            PreventiveMaintenancePlan::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'vendor_id' => $vendor->id, 'title' => 'Plan '.$index, 'frequency_days' => 30, 'next_due_on' => $dueOn, 'is_active' => $active]);
        }
        PreventiveMaintenancePlan::create(['organization_id' => $organization->id, 'property_id' => $otherProperty->id, 'vendor_id' => $otherVendor->id, 'title' => 'Other property plan', 'frequency_days' => 30, 'next_due_on' => '2026-09-22']);
        [$foreign, , $foreignProperty, $foreignVendor] = $this->context();
        MaintenanceRequest::create(['organization_id' => $foreign->id, 'property_id' => $foreignProperty->id, 'reference' => 'MNT-PRIVATE', 'title' => 'Private work', 'priority' => 'urgent', 'due_at' => now()->subDay()]);
        PreventiveMaintenancePlan::create(['organization_id' => $foreign->id, 'property_id' => $foreignProperty->id, 'vendor_id' => $foreignVendor->id, 'title' => 'Private plan', 'frequency_days' => 30, 'next_due_on' => '2026-09-22']);

        $this->actingAs($viewer)->get(route('operations.overview'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('operations/Overview')
                ->where('today', '2026-09-23')->where('throughDate', '2026-09-30')
                ->where('summary', ['total' => 6, 'active' => 4, 'overdue' => 1, 'unassigned' => 2, 'urgent' => 1, 'on_hold' => 1, 'completed' => 1, 'cancelled' => 1, 'due_plans' => 2])
                ->where('backlog.total', 4)->where('plans.total', 3)->has('properties', 2)->has('vendors', 2));
        $this->get(route('operations.overview', ['property_id' => $property->id, 'vendor_id' => $vendor->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.active', 3)->where('summary.due_plans', 1)->where('plans.total', 2)
                ->where('backlog.data.0.reference', 'MNT-0')->where('backlog.data.0.overdue', 1)
                ->where('backlog.data.1.reference', 'MNT-2')->where('backlog.data.1.overdue', 0)
                ->where('backlog.data.2.reference', 'MNT-1')->where('backlog.data.2.overdue', 0)
                ->where('workload.0.assignee_id', $viewer->id)->where('workload.0.active', 2)
                ->where('workload.0.overdue', 1)->where('workload.0.on_hold', 1));
        $this->get(route('operations.overview', ['property_id' => $property->id, 'vendor_id' => $otherVendor->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 0)->where('summary.due_plans', 0)->has('workload', 0)->has('backlog.data', 0)->has('plans.data', 0));
        $this->assertDatabaseCount('maintenance_requests', 7);
        $this->assertDatabaseCount('preventive_maintenance_plans', 6);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_csv_matches_filters_includes_all_pages_and_escapes_user_controlled_cells(): void
    {
        [$organization, $viewer, $property, $vendor] = $this->context();
        $viewer->update(['name' => '@Agent']);
        $property->update(['name' => '=Property']);
        $vendor->update(['name' => '+Vendor']);
        for ($index = 0; $index < 25; $index++) {
            MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'vendor_id' => $vendor->id, 'assigned_to' => $viewer->id, 'reference' => sprintf('MNT-%02d', $index), 'title' => '=SUM(1,1)']);
            PreventiveMaintenancePlan::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'vendor_id' => $vendor->id, 'title' => 'Plan '.$index, 'frequency_days' => 30, 'next_due_on' => today()]);
        }
        MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'reference' => 'MNT-NO-VENDOR', 'title' => 'Excluded work']);
        $filters = ['property_id' => $property->id, 'vendor_id' => $vendor->id];
        $this->actingAs($viewer)->get(route('operations.overview', $filters))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.active', 25)->where('summary.due_plans', 25)
                ->where('backlog.total', 25)->has('backlog.data', 20)->where('plans.total', 25)->has('plans.data', 20));
        $this->get(route('operations.overview', [...$filters, 'requests_page' => 2, 'plans_page' => 2]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('backlog.data', 5)->has('plans.data', 5)->where('backlog.data.4.reference', 'MNT-24'));
        $csv = $this->get(route('operations.overview.export', [...$filters, 'requests_page' => 2]))->assertOk()->streamedContent();
        $this->assertStringContainsString('active,25', $csv);
        $this->assertStringContainsString('due_plans,25', $csv);
        $this->assertStringContainsString("'@Agent,25,0,0", $csv);
        $this->assertStringContainsString("\"'=SUM(1,1)\"", $csv);
        $this->assertStringContainsString("'=Property,'+Vendor,'@Agent", $csv);
        $this->assertStringContainsString('MNT-00', $csv);
        $this->assertStringContainsString('MNT-24', $csv);
        $this->assertStringContainsString('Plan 24', $csv);
        $this->assertStringNotContainsString('Excluded work', $csv);
    }

    public function test_foreign_filters_and_nonmembers_are_denied_on_both_endpoints(): void
    {
        [$organization, $viewer] = $this->context();
        [, , $foreignProperty, $foreignVendor] = $this->context();
        foreach (['operations.overview', 'operations.overview.export'] as $route) {
            $this->actingAs($viewer)->getJson(route($route, ['property_id' => $foreignProperty->id]))->assertUnprocessable()->assertJsonValidationErrors('property_id');
            $this->getJson(route($route, ['vendor_id' => $foreignVendor->id]))->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
            $this->getJson(route($route, ['requests_page' => -1]))->assertUnprocessable()->assertJsonValidationErrors('requests_page');
            $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
            $this->actingAs($outsider)->get(route($route))->assertForbidden();
        }
    }

    public function test_foreign_related_labels_are_not_disclosed_by_malformed_legacy_links(): void
    {
        [$organization, $viewer, $property] = $this->context();
        [, $foreignUser, $foreignProperty, $foreignVendor] = $this->context();
        $foreignUser->update(['name' => 'Foreign secret person']);
        $foreignProperty->update(['name' => 'Foreign secret property']);
        $foreignVendor->update(['name' => 'Foreign secret vendor']);
        MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $foreignProperty->id, 'vendor_id' => $foreignVendor->id, 'assigned_to' => $foreignUser->id, 'reference' => 'MNT-LEGACY', 'title' => 'Legacy links']);
        PreventiveMaintenancePlan::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'vendor_id' => $foreignVendor->id, 'title' => 'Legacy plan', 'frequency_days' => 30, 'next_due_on' => today()]);
        $this->actingAs($viewer)->get(route('operations.overview'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('backlog.data.0.property', null)->where('backlog.data.0.vendor', null)
                ->where('backlog.data.0.assignee', 'Unavailable member')->where('workload.0.assignee', 'Unavailable member')->where('plans.data.0.vendor', null));
        $csv = $this->get(route('operations.overview.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Foreign secret', $csv);
    }

    /** @return array{Organization, User, Property, MaintenanceVendor} */
    private function context(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $viewer = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($viewer, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Palm Court', 'type' => 'residential']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Cool Air']);

        return [$organization, $viewer, $property, $vendor];
    }
}
