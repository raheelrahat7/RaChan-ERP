<?php

namespace Tests\Feature\RealEstate;

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

class ListingInquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_records_a_listing_inquiry_in_the_existing_crm_pipeline(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Harbor', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => 'H-101', 'type' => 'apartment', 'status' => 'available']);

        $this->actingAs($manager)->post(route('real-estate.listings.store'), ['unit_id' => $unit->id, 'purpose' => 'rent', 'price' => 90000])->assertRedirect();
        $listing = Listing::sole();
        $this->post(route('real-estate.listings.inquiries.store'), [
            'listing_id' => $listing->id,
            'first_name' => 'Amina',
            'last_name' => 'Khan',
            'email' => 'amina@example.test',
            'notes' => 'Requested a viewing',
        ])->assertRedirect();

        $lead = CrmLead::sole();
        $this->assertSame($organization->id, $lead->organization_id);
        $this->assertSame($listing->id, $lead->listing_id);
        $this->assertSame('Listing inquiry', $lead->source);
        $this->assertSame(1, $lead->history()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead.created', 'subject_id' => $lead->id]);
        $this->get(route('crm.leads.index'))->assertInertia(fn (Assert $page) => $page->where('leads.0.listing.reference', $listing->reference)->where('leads.0.source', 'Listing inquiry'));
    }

    public function test_foreign_listings_and_viewer_inquiries_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $viewer = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $organization->users()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
        $property = Property::create(['organization_id' => $other->id, 'name' => 'Foreign', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $other->id, 'property_id' => $property->id, 'number' => 'F-1', 'type' => 'apartment']);
        $listing = Listing::create(['organization_id' => $other->id, 'unit_id' => $unit->id, 'reference' => 'LST-FOREIGN', 'purpose' => 'sale', 'price' => 100000]);
        $input = ['listing_id' => $listing->id, 'first_name' => 'Outside', 'last_name' => 'Lead'];

        $this->actingAs($manager)->post(route('real-estate.listings.inquiries.store'), $input)->assertNotFound();
        $this->actingAs($viewer)->post(route('real-estate.listings.inquiries.store'), $input)->assertForbidden();
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_an_active_listing_accepts_a_rate_limited_public_inquiry(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Canal Residence', 'type' => 'residential', 'city' => 'Dubai']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => 'C-22', 'type' => 'apartment', 'status' => 'available', 'area' => 950]);
        $this->actingAs($manager)->post(route('real-estate.listings.store'), ['unit_id' => $unit->id, 'purpose' => 'rent', 'price' => 85000])->assertRedirect();
        $listing = Listing::sole();

        $this->get(route('public.listings.show', $listing->public_token))->assertNotFound();
        $this->put(route('real-estate.listings.status.update', $listing), ['status' => 'active'])->assertRedirect();
        $this->actingAsGuest()->get(route('public.listings.show', $listing->public_token))->assertInertia(fn (Assert $page) => $page
            ->component('public/Listing')
            ->where('listing.reference', $listing->reference)
            ->where('listing.property.name', 'Canal Residence')
            ->where('listing.property.city', 'Dubai')
            ->where('listing.unit.type', 'apartment')
            ->missing('listing.unit.property_id'));
        $this->post(route('public.listings.inquiries.store', $listing->public_token), [
            'first_name' => 'Public',
            'last_name' => 'Prospect',
            'phone' => '+971500000000',
            'notes' => 'Please arrange a viewing.',
            'website' => '',
        ])->assertRedirect();

        $lead = CrmLead::sole();
        $this->assertSame($listing->id, $lead->listing_id);
        $this->assertSame('Public listing inquiry', $lead->source);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead.public_inquiry_created', 'subject_id' => $lead->id, 'actor_id' => null]);
        $this->post(route('public.listings.inquiries.store', $listing->public_token), ['first_name' => 'No', 'last_name' => 'Contact', 'website' => ''])->assertSessionHasErrors(['email', 'phone']);
        $this->actingAs($manager)->put(route('real-estate.listings.status.update', $listing), ['status' => 'paused'])->assertRedirect();
        $this->actingAsGuest()->get(route('public.listings.show', $listing->public_token))->assertNotFound();
    }
}
