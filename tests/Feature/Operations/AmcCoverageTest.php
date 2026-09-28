<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Actions\ManageAmcCoverage;
use App\Domain\Operations\Models\AmcContract;
use App\Domain\Operations\Models\OperationsEquipment;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AmcCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private Property $property;

    private AmcContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->organization = Organization::factory()->create();
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Covered', 'type' => 'residential']);
        $this->contract = app(ManageAmcCoverage::class)->contract($this->organization, $this->manager, ['reference' => 'AMC-1', 'title' => 'Annual coverage', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'service_limit' => 1, 'property_ids' => [$this->property->id], 'equipment_ids' => []]);
        $this->actingAs($this->manager);
    }

    private function job(string $reference): MaintenanceRequest
    {
        return MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $this->property->id, 'reference' => $reference, 'title' => 'Repair']);
    }

    public function test_coverage_limits_require_reason_and_usage_tracks_cancellation_and_reopening(): void
    {
        $first = $this->job('JOB-1');
        $second = $this->job('JOB-2');
        $input = ['maintenance_request_id' => $first->id, 'service_on' => '2026-09-27'];
        $this->post(route('amc.visits', $this->contract), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('amc.visits', $this->contract), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('amc.visits', $this->contract), [...$input, 'service_on' => '2026-09-28'])->assertSessionHasErrors('maintenance_request_id');
        $this->post(route('amc.visits', $this->contract), [...$input, 'maintenance_request_id' => $second->id])->assertSessionHasErrors('override_reason');
        $first->update(['status' => 'cancelled']);
        $this->post(route('amc.visits', $this->contract), [...$input, 'maintenance_request_id' => $second->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, app(ManageAmcCoverage::class)->usage($this->organization, $this->contract));
        $first->update(['status' => 'open']);
        $this->assertSame(2, app(ManageAmcCoverage::class)->usage($this->organization, $this->contract));
        $third = $this->job('JOB-3');
        $this->post(route('amc.visits', $this->contract), [...$input, 'maintenance_request_id' => $third->id, 'override_reason' => 'Owner authorized extra visit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('amc.index'))->assertInertia(fn (Assert $page) => $page->component('operations/Amc')->where('contracts.data.0.used', 3)->where('contracts.data.0.over_limit', true));
        $this->assertDatabaseCount('amc_service_visits', 3);
        $this->assertDatabaseCount('vendor_bills', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_coverage_dates_properties_and_cancelled_contracts_are_enforced(): void
    {
        $job = $this->job('JOB');
        $input = ['maintenance_request_id' => $job->id, 'service_on' => '2027-01-01'];
        $this->post(route('amc.visits', $this->contract), $input)->assertSessionHasErrors('service_on');
        $other = Property::create(['organization_id' => $this->organization->id, 'name' => 'Not covered', 'type' => 'residential']);
        $job->update(['property_id' => $other->id]);
        $this->post(route('amc.visits', $this->contract), [...$input, 'service_on' => '2026-01-01'])->assertSessionHasErrors('maintenance_request_id');
        $job->update(['property_id' => $this->property->id]);
        $this->post(route('amc.cancel', $this->contract), ['reason' => 'Superseded contract'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('amc.visits', $this->contract), [...$input, 'service_on' => '2026-01-01'])->assertSessionHasErrors('amc_contract_id');
        $this->assertDatabaseCount('amc_service_visits', 0);
        $this->assertDatabaseHas('amc_contracts', ['id' => $this->contract->id, 'cancellation_reason' => 'Superseded contract']);
    }

    public function test_equipment_only_coverage_and_cross_organization_links_are_scoped(): void
    {
        $equipment = OperationsEquipment::create(['organization_id' => $this->organization->id, 'property_id' => $this->property->id, 'reference' => 'AC-1', 'name' => 'AC']);
        $contract = app(ManageAmcCoverage::class)->contract($this->organization, $this->manager, ['reference' => 'AMC-E', 'title' => 'AC coverage', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'property_ids' => [], 'equipment_ids' => [$equipment->id]]);
        $job = $this->job('JOB');
        $input = ['maintenance_request_id' => $job->id, 'service_on' => '2026-12-31'];
        $this->post(route('amc.visits', $contract), $input)->assertSessionHasErrors('maintenance_request_id');
        $this->post(route('amc.visits', $contract), [...$input, 'operations_equipment_id' => $equipment->id])->assertRedirect()->assertSessionHasNoErrors();
        $org = Organization::factory()->create();
        $otherProperty = Property::create(['organization_id' => $org->id, 'name' => 'Other', 'type' => 'residential']);
        $otherEquipment = OperationsEquipment::create(['organization_id' => $org->id, 'property_id' => $otherProperty->id, 'reference' => 'FOREIGN', 'name' => 'Foreign']);
        $this->post(route('amc.visits', $contract), [...$input, 'maintenance_request_id' => $this->job('OTHER-JOB')->id, 'operations_equipment_id' => $otherEquipment->id])->assertNotFound();
        $this->post(route('amc.store'), ['reference' => 'BAD', 'title' => 'Bad', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'property_ids' => [$otherProperty->id], 'equipment_ids' => []])->assertSessionHasErrors('property_ids.0');
        $member = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($member)->get(route('amc.index'))->assertForbidden();
        $this->post(route('amc.visits', $contract), $input)->assertForbidden();
    }

    public function test_visit_audit_failure_rolls_back_reservation(): void
    {
        $job = $this->job('JOB');
        $audit = \Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(ManageAmcCoverage::class)->visit($this->organization, $this->manager, $this->contract, ['maintenance_request_id' => $job->id, 'service_on' => '2026-09-27']);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('amc_service_visits', 0);
    }
}
