<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Building;
use App\Models\CrmLead;
use App\Models\Lease;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\SalesContract;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordSearchTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    /** @return array<string,int> */
    private function records(Organization $org, User $assignee, string $suffix): array
    {
        $property = Property::create(['organization_id' => $org->id, 'name' => 'SRCH Property '.$suffix, 'type' => 'residential', 'city' => 'Dubai']);
        $building = Building::create(['organization_id' => $org->id, 'property_id' => $property->id, 'name' => 'Tower '.$suffix]);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'building_id' => $building->id,
            'number' => 'SRCH-UNIT-'.$suffix, 'type' => 'apartment']);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $assignee->id,
            'first_name' => 'Sara', 'last_name' => 'SRCH-'.$suffix, 'company' => 'Company '.$suffix]);
        $listing = Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'SRCH-LIST-'.$suffix,
            'purpose' => 'sale', 'price' => 100000, 'status' => 'active']);
        $reservation = Reservation::create(['organization_id' => $org->id, 'unit_id' => $unit->id,
            'reference' => 'SRCH-RES-'.$suffix, 'expires_at' => now()->addDay()]);
        $lease = Lease::create(['organization_id' => $org->id, 'unit_id' => $unit->id,
            'reference' => 'SRCH-LEASE-'.$suffix, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addYear()->toDateString()]);
        $sale = SalesContract::create(['organization_id' => $org->id, 'unit_id' => $unit->id,
            'reference' => 'SRCH-SALE-'.$suffix, 'contracted_on' => today()->toDateString()]);
        $job = MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'unit_id' => $unit->id,
            'assigned_to' => $assignee->id, 'reference' => 'SRCH-JOB-'.$suffix, 'title' => 'Repair '.$suffix]);

        return ['lead' => $lead->id, 'listing' => $listing->id, 'unit' => $unit->id,
            'reservation' => $reservation->id, 'lease' => $lease->id, 'sale' => $sale->id, 'job' => $job->id];
    }

    public function test_every_record_type_returns_only_current_organization_matches_with_labels(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $foreignOwner = $this->member($other, OrganizationRole::Owner);
        $ids = $this->records($org, $owner, 'OWN');
        $this->records($other, $foreignOwner, 'FOREIGN');

        $this->actingAs($owner);
        foreach (['lead', 'listing', 'unit', 'reservation', 'lease', 'sale', 'job'] as $type) {
            $this->getJson(route('records.search', ['type' => $type, 'q' => 'SRCH']))
                ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $ids[$type]);
        }
        $this->getJson(route('records.search', ['type' => 'lead', 'q' => 'SRCH']))
            ->assertJsonPath('0.label', 'Sara SRCH-OWN')->assertJsonPath('0.sublabel', 'Company OWN');
        $this->getJson(route('records.search', ['type' => 'lead', 'q' => 'Sara SRCH-OWN']))
            ->assertJsonCount(1)->assertJsonPath('0.id', $ids['lead']);
        $this->getJson(route('records.search', ['type' => 'listing', 'q' => 'SRCH']))
            ->assertJsonPath('0.label', 'SRCH-LIST-OWN')->assertJsonPath('0.sublabel', 'SRCH Property OWN · Dubai');
        $this->getJson(route('records.search', ['type' => 'unit', 'q' => 'SRCH']))
            ->assertJsonPath('0.sublabel', 'SRCH Property OWN');
        $this->getJson(route('records.search', ['type' => 'listing', 'q' => 'Property OWN']))
            ->assertJsonCount(1)->assertJsonPath('0.id', $ids['listing']);
        $this->getJson(route('records.search', ['type' => 'job', 'q' => 'SRCH']))
            ->assertJsonPath('0.sublabel', 'Repair OWN');
        $this->post(route('tasks.store'), ['title' => 'Call buyer', 'assigned_to' => $owner->id,
            'related_type' => 'listing', 'related_id' => $ids['listing']])->assertRedirect();
        $this->post(route('meetings.store'), ['type' => 'viewing', 'title' => 'View unit', 'assigned_to' => $owner->id,
            'listing_id' => $ids['listing'], 'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDay()->addHour()->toDateTimeString()])->assertRedirect();
        $this->assertSame($ids['listing'], (int) DB::table('work_tasks')->sole()->related_id);
        $this->assertSame($ids['listing'], (int) DB::table('brokerage_appointments')->sole()->listing_id);
    }

    public function test_lead_and_job_visibility_can_be_intersected_with_selected_assignee(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $member = $this->member($org, OrganizationRole::Member);
        $this->records($org, $owner, 'OWNER');
        $memberIds = $this->records($org, $member, 'MEMBER');

        foreach (['lead', 'job'] as $type) {
            $this->actingAs($member)->getJson(route('records.search', ['type' => $type, 'q' => 'SRCH']))
                ->assertJsonCount(1)->assertJsonPath('0.id', $memberIds[$type]);
            $this->actingAs($owner)->getJson(route('records.search', ['type' => $type, 'q' => 'SRCH', 'assignee_id' => $member->id]))
                ->assertJsonCount(1)->assertJsonPath('0.id', $memberIds[$type]);
        }
    }

    public function test_empty_short_invalid_and_unassigned_queries_are_safe(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $other = Organization::factory()->create();
        $foreign = $this->member($other, OrganizationRole::Member);
        $this->actingAs($owner)->getJson(route('records.search', ['type' => 'lead']))->assertExactJson([]);
        $this->getJson(route('records.search', ['type' => 'lead', 'q' => 'a']))->assertExactJson([]);
        $this->getJson(route('records.search', ['type' => 'lead', 'q' => 'س']))->assertExactJson([]);
        $this->getJson(route('records.search', ['type' => 'unknown', 'q' => 'SRCH']))->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->getJson(route('records.search', ['type' => 'lead', 'q' => 'SRCH', 'assignee_id' => $foreign->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['assignee_id']);
    }
}
