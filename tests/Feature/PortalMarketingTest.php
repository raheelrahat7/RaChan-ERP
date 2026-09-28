<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalMarketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function context(): array
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Canal Tower', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => 'A-1', 'type' => 'apartment']);
        $listing = Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'LST-PORTAL', 'purpose' => 'rent', 'price' => '80000.00', 'currency' => 'AED', 'status' => 'active']);

        return [$org, $owner, $listing];
    }

    public function test_local_publication_enquiry_subscription_and_costing_are_honestly_labelled(): void
    {
        [$org, $owner, $listing] = $this->context();
        $this->actingAs($owner)->post(route('marketing.publications.store'), ['listing_id' => $listing->id, 'portal' => 'bayut'])->assertRedirect();
        $publication = DB::table('portal_publications')->sole();
        $this->assertSame('local_validated', $publication->status);
        $this->assertSame(64, strlen($publication->packet_sha256));
        $lead = CrmLead::create(['organization_id' => $org->id, 'listing_id' => $listing->id, 'assigned_to' => $owner->id, 'first_name' => 'Sara', 'last_name' => 'Lead']);
        $this->post(route('marketing.enquiries.store', $publication->id), ['lead_id' => $lead->id, 'source_reference' => 'manual-1', 'received_at' => now()->toDateTimeString()])->assertRedirect();
        $this->post(route('marketing.subscriptions.store'), ['portal' => 'bayut', 'package' => 'Basic', 'contract_value_aed' => '1000.00', 'billing_cycle' => 'monthly', 'credits_total' => 100, 'starts_on' => today()->toDateString()])->assertRedirect();
        $this->post(route('marketing.costing.store'), ['listing_id' => $listing->id, 'publication_id' => $publication->id, 'channel' => 'bayut', 'source' => 'estimate', 'amount_aed' => '50.00', 'incurred_on' => today()->toDateString(), 'reason' => 'Proposed promotion'])->assertRedirect();
        $this->get(route('marketing.costing.index'))->assertInertia(fn (Assert $page) => $page
            ->where('portals.0.portal', 'bayut')->where('portals.0.published_listings', 0)
            ->where('portals.0.local_validated_listings', 1)->where('portals.0.total_leads', 1)
            ->where('portals.0.actual_spend_aed', 0)->where('portals.0.estimated_spend_aed', 50)
            ->where('portals.0.cost_per_deal', null)->etc());
    }

    public function test_actual_spend_requires_a_posted_local_vendor_bill_and_cannot_exceed_it(): void
    {
        [$org, $owner, $listing] = $this->context();
        $vendor = MaintenanceVendor::create(['organization_id' => $org->id, 'name' => 'Portal Vendor']);
        $bill = VendorBill::create(['organization_id' => $org->id, 'vendor_id' => $vendor->id, 'reference' => 'VB-PORTAL', 'description' => 'Listing ads', 'status' => 'posted', 'bill_date' => today()->toDateString(), 'total' => '100.00', 'currency' => 'AED']);
        $input = ['listing_id' => $listing->id, 'channel' => 'bayut', 'source' => 'bill_linked', 'vendor_bill_id' => $bill->id,
            'amount_aed' => '80.00', 'incurred_on' => today()->toDateString(), 'reason' => 'Featured ad'];
        $this->actingAs($owner)->post(route('marketing.costing.store'), $input)->assertRedirect();
        $this->post(route('marketing.costing.store'), [...$input, 'amount_aed' => '30.00'])->assertSessionHasErrors(['amount_aed']);
        $this->assertDatabaseCount('listing_spend', 1);
    }

    public function test_foreign_listing_cannot_be_validated_for_a_portal(): void
    {
        [$org, $owner] = $this->context();
        $other = Organization::factory()->create();
        $property = Property::create(['organization_id' => $other->id, 'name' => 'Outside', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $other->id, 'property_id' => $property->id, 'number' => 'X', 'type' => 'apartment']);
        $listing = Listing::create(['organization_id' => $other->id, 'unit_id' => $unit->id, 'reference' => 'FOREIGN-PORTAL', 'purpose' => 'sale', 'price' => '100.00', 'currency' => 'AED', 'status' => 'active']);
        $this->actingAs($owner)->post(route('marketing.publications.store'), ['listing_id' => $listing->id, 'portal' => 'bayut'])->assertSessionHasErrors(['listing_id']);
        $this->assertDatabaseCount('portal_publications', 0);
    }
}
