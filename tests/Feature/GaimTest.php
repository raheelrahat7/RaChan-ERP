<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_rule_driven_form_record_tracks_explicit_status_and_audit_history(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $viewer = $this->member($org, OrganizationRole::Viewer);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Dubai Tower', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => 'A', 'type' => 'apartment']);
        $listing = Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'GAIM-LIST', 'purpose' => 'sale', 'price' => 100000, 'status' => 'active']);
        $this->actingAs($owner)->post(route('gaim.rules.store'), ['emirate' => 'Dubai', 'event' => 'listing_publish', 'authority' => 'DLD', 'form_code' => 'TRAKHEESI', 'form_name' => 'Trakheesi listing permit'])->assertRedirect();
        $rule = DB::table('gaim_routing_rules')->sole();
        $this->post(route('gaim.records.store'), ['routing_rule_id' => $rule->id, 'subject_type' => 'listing', 'subject_id' => $listing->id])->assertRedirect();
        $record = DB::table('gaim_compliance_records')->sole();
        $this->post(route('gaim.records.status', $record->id), ['status' => 'preparing'])->assertRedirect();
        $this->post(route('gaim.records.status', $record->id), ['status' => 'submitted'])->assertSessionHasErrors(['authority_reference']);
        $this->post(route('gaim.records.status', $record->id), ['status' => 'submitted', 'authority_reference' => 'DLD-123'])->assertRedirect();
        $this->post(route('gaim.records.status', $record->id), ['status' => 'approved', 'expires_on' => now()->addYear()->toDateString()])->assertRedirect();
        $this->assertDatabaseHas('gaim_compliance_records', ['id' => $record->id, 'status' => 'approved', 'authority_reference' => 'DLD-123']);
        $this->assertSame(4, DB::table('gaim_record_events')->where('record_id', $record->id)->count());
        $this->actingAs($viewer)->get(route('gaim.index'))->assertInertia(fn (Assert $page) => $page
            ->where('summary.1.emirate', 'Dubai')->where('summary.1.approved', 1)
            ->where('records.0.form_code', 'TRAKHEESI')->where('canManage', false)->etc());
    }

    public function test_foreign_subject_and_unprivileged_routing_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $viewer = $this->member($org, OrganizationRole::Viewer);
        $ruleInput = ['emirate' => 'Sharjah', 'event' => 'lease_sign', 'authority' => 'SRERA', 'form_code' => 'TENANCY', 'form_name' => 'Tenancy form'];
        $this->actingAs($viewer)->post(route('gaim.rules.store'), $ruleInput)->assertForbidden();
        $this->actingAs($owner)->post(route('gaim.rules.store'), $ruleInput)->assertRedirect();
        $rule = DB::table('gaim_routing_rules')->sole();
        $this->post(route('gaim.records.store'), ['routing_rule_id' => $rule->id, 'subject_type' => 'lease', 'subject_id' => 999])->assertSessionHasErrors(['subject_id']);
        $this->assertDatabaseCount('gaim_compliance_records', 0);
        $this->post(route('gaim.bulletins.store'), ['authority' => 'SRERA', 'title' => 'Form update', 'body' => 'Review the new form.', 'affected_forms' => ['TENANCY']])->assertRedirect();
        $bulletin = DB::table('gaim_bulletins')->sole();
        $this->actingAs($viewer)->get(route('gaim.bulletins.index'))->assertInertia(fn (Assert $page) => $page->has('bulletins.data', 0)->etc());
        $this->actingAs($owner)->post(route('gaim.bulletins.publish', $bulletin->id))->assertRedirect();
        $this->actingAs($viewer)->get(route('gaim.bulletins.index'))->assertInertia(fn (Assert $page) => $page->has('bulletins.data', 1)->etc());
    }

    public function test_sync_materializes_only_effective_required_forms_once(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Dubai Tower', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => 'B', 'type' => 'apartment']);
        $listing = Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => 'GAIM-SYNC', 'purpose' => 'sale', 'price' => 100000, 'status' => 'active']);
        $this->actingAs($owner);
        foreach ([
            ['form_code' => 'TRAKHEESI', 'required' => true],
            ['form_code' => 'TRANSFER', 'required' => true],
            ['form_code' => 'OPTIONAL', 'required' => false],
            ['form_code' => 'FUTURE', 'required' => true, 'effective_from' => now()->addMonth()->toDateString()],
        ] as $form) {
            $this->post(route('gaim.rules.store'), array_merge([
                'emirate' => 'Dubai', 'event' => 'listing_publish', 'authority' => 'DLD', 'form_name' => $form['form_code'],
            ], $form))->assertRedirect();
        }
        $input = ['emirate' => 'Dubai', 'event' => 'listing_publish', 'subject_type' => 'listing', 'subject_id' => $listing->id];
        $this->post(route('gaim.records.sync'), $input)->assertRedirect();
        $this->post(route('gaim.records.sync'), $input)->assertRedirect();
        $this->assertSame(2, DB::table('gaim_compliance_records')->count());
        $this->assertSame(2, DB::table('gaim_record_events')->count());
    }
}
