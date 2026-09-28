<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocalMatchmakerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(Organization $org): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);

        return $user;
    }

    public function test_local_matches_use_purpose_city_type_budget_and_organization_scope(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->owner($org);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $owner->id, 'first_name' => 'Buyer', 'last_name' => 'One']);
        foreach (['Dubai', 'Abu Dhabi'] as $index => $city) {
            $property = Property::create(['organization_id' => $org->id, 'name' => 'Tower '.$index, 'type' => 'residential', 'city' => $city]);
            $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => 'A-'.$index, 'type' => 'apartment', 'status' => 'available']);
            Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'MATCH-'.$index, 'purpose' => 'sale', 'price' => $index === 0 ? '900000.00' : '500000.00', 'currency' => 'AED', 'status' => 'active']);
        }
        $this->actingAs($owner)->get(route('crm.matchmaker.show', $lead->id))->assertInertia(fn (Assert $page) => $page->where('matches', [])->etc());
        $this->put(route('crm.matchmaker.preference', $lead->id), ['purpose' => 'sale', 'city' => 'Dubai', 'property_type' => 'apartment', 'max_price_aed' => '1000000.00'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('crm.matchmaker.show', $lead->id))->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'local_rules')->has('matches', 1)->where('matches.0.reference', 'MATCH-0')->etc());
    }

    public function test_foreign_lead_cannot_be_matched(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->owner($org);
        $foreign = CrmLead::create(['organization_id' => $other->id, 'first_name' => 'Other', 'last_name' => 'Lead']);
        $this->actingAs($owner)->get(route('crm.matchmaker.show', $foreign->id))->assertNotFound();
        $this->put(route('crm.matchmaker.preference', $foreign->id), ['purpose' => 'sale'])->assertNotFound();
    }
}
