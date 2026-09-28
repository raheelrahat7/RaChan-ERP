<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Models\SavedReportFilter;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SavedReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_are_private_validated_and_never_expand_technician_access(): void
    {
        $this->withoutVite();
        $org = Organization::factory()->create();
        $tech = User::factory()->create(['current_organization_id' => $org->id]);
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($tech, ['role' => OrganizationRole::Member->value]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Local', 'type' => 'residential']);
        MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'reference' => 'ASSIGNED', 'title' => 'Assigned', 'assigned_to' => $tech->id]);
        MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'reference' => 'HIDDEN', 'title' => 'Hidden', 'assigned_to' => $manager->id]);
        $input = ['name' => 'Open jobs', 'filters' => ['status' => 'open', 'property_id' => $property->id]];
        $this->actingAs($tech)->post(route('operations.reports.filters.save'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('operations.reports.filters.save'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('saved_report_filters', 1);
        $filter = SavedReportFilter::sole();
        $this->get(route('operations.reports', $filter->filters))->assertInertia(fn (Assert $page) => $page->has('savedFilters', 1)->has('jobs.data', 1)->where('jobs.data.0.reference', 'ASSIGNED'));
        $this->actingAs($manager)->get(route('operations.reports'))->assertInertia(fn (Assert $page) => $page->has('savedFilters', 0));
        $this->delete(route('operations.reports.filters.remove', $filter))->assertNotFound();
        $foreign = Property::create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Foreign', 'type' => 'residential']);
        $this->actingAs($tech)->post(route('operations.reports.filters.save'), ['name' => 'Foreign', 'filters' => ['property_id' => $foreign->id]])->assertSessionHasErrors('property_id');
        $this->post(route('operations.reports.filters.save'), ['name' => 'Invalid', 'filters' => ['created_from' => '2026-09-27', 'created_to' => '2026-09-26']])->assertSessionHasErrors('created_to');
        $this->delete(route('operations.reports.filters.remove', $filter))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('saved_report_filters', 0);
    }
}
