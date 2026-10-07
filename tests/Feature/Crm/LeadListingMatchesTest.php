<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadListingMatchesTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role = OrganizationRole::Owner): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function lead(Organization $org, User $assignee): CrmLead
    {
        return CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $assignee->id, 'first_name' => 'Buying', 'last_name' => 'Lead']);
    }

    private function listing(Organization $org, string $reference, string $city, string $price = '1000000.00', string $status = 'active'): Listing
    {
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Tower '.$reference, 'type' => 'residential', 'city' => $city]);
        $unit = Unit::create(['organization_id' => $org->id, 'property_id' => $property->id, 'number' => $reference, 'type' => 'apartment', 'area' => '1200.00', 'area_unit' => 'sq_ft', 'status' => 'available']);

        return Listing::create(['organization_id' => $org->id, 'unit_id' => $unit->id, 'reference' => $reference, 'purpose' => 'sale', 'status' => $status, 'price' => $price, 'currency' => 'AED']);
    }

    public function test_suggestions_rank_current_requirements_and_saved_matches_recompute_without_overriding(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($owner = $this->member($org));
        $lead = $this->lead($org, $owner);
        $best = $this->listing($org, 'MATCH-BEST', 'Dubai');
        $other = $this->listing($org, 'MATCH-OTHER', 'Sharjah', '1500000.00');
        $this->listing($org, 'MATCH-PAUSED', 'Dubai', '1000000.00', 'paused');
        $foreign = $this->listing(Organization::factory()->create(), 'MATCH-FOREIGN', 'Dubai');
        $this->putJson("/crm/leads/{$lead->id}/requirements", ['expected_version' => 0, 'data' => ['purpose' => 'buy', 'property_type' => 'apartment', 'location' => 'Dubai', 'budget_min' => '900000.00', 'budget_max' => '1100000.00', 'budget_currency' => 'AED', 'size_min' => '1100.00', 'size_max' => '1300.00', 'size_unit' => 'sq_ft']])->assertOk();
        $base = "/crm/leads/{$lead->id}/matches";
        $this->getJson($base.'/suggest')->assertOk()->assertJsonPath('candidates.total', 2)->assertJsonPath('candidates.data.0.listing.id', $best->id)->assertJsonPath('candidates.data.0.match_percent', 100)->assertJsonPath('candidates.data.1.listing.id', $other->id)->assertJsonPath('candidates.data.1.match_percent', 60)->assertJsonPath('candidates.data.0.listing.community', null);
        $this->getJson($base.'/suggest?q=Sharjah')->assertOk()->assertJsonPath('candidates.total', 1)->assertJsonPath('candidates.data.0.listing.id', $other->id);
        $this->getJson($base.'/suggest?per_page=1&page=2')->assertOk()->assertJsonPath('candidates.current_page', 2)->assertJsonCount(1, 'candidates.data');
        $this->postJson($base, ['listing_id' => $best->id])->assertCreated()->assertJsonPath('match.version', 1)->assertJsonPath('match.match_percent', 100)->assertJsonPath('match.score_source', 'automatic')->assertJsonPath('match.permissions.edit', true);
        $this->getJson($base.'/suggest')->assertOk()->assertJsonPath('candidates.total', 1)->assertJsonPath('candidates.data.0.listing.id', $other->id);
        $id = $this->getJson($base)->assertOk()->assertJsonPath('matches.total', 1)->assertJsonPath('matches.data.0.match_percent', 100)->json('matches.data.0.id');
        $this->putJson("/crm/leads/{$lead->id}/requirements", ['expected_version' => 1, 'data' => ['location' => 'Sharjah']])->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('matches.data.0.match_percent', 80);
        $this->putJson("$base/$id", ['expected_version' => 1, 'match_percent_override' => 73, 'shared' => true, 'viewing_status' => 'scheduled', 'viewing_at' => '2027-01-02T10:00:00+00:00', 'notes' => 'Client requested a viewing'])->assertOk()->assertJsonPath('match.version', 2)->assertJsonPath('match.match_percent', 73)->assertJsonPath('match.score_source', 'manual')->assertJsonPath('match.shared', true)->assertJsonPath('match.viewing_status', 'scheduled');
        $this->putJson("/crm/leads/{$lead->id}/requirements", ['expected_version' => 2, 'data' => ['location' => 'Dubai']])->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('matches.data.0.match_percent', 73);
        $this->putJson("$base/$id", ['expected_version' => 2, 'match_percent_override' => null])->assertOk()->assertJsonPath('match.version', 3)->assertJsonPath('match.match_percent', 100)->assertJsonPath('match.score_source', 'automatic');
        $best->update(['status' => 'paused']);
        $this->getJson($base)->assertOk()->assertJsonPath('matches.data.0.listing.status', 'paused');
        $this->postJson($base, ['listing_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('listing_id');
        $this->postJson($base, ['listing_id' => $best->id])->assertUnprocessable()->assertJsonValidationErrors('listing_id');
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead.match_added', 'subject_id' => $lead->id]);
    }

    public function test_validation_versions_and_deletion_preserve_stored_match(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($owner = $this->member($org));
        $lead = $this->lead($org, $owner);
        $listing = $this->listing($org, 'MATCH-VERSION', 'Dubai');
        $base = "/crm/leads/{$lead->id}/matches";
        $this->postJson($base, ['listing_id' => $listing->id, 'viewing_status' => 'scheduled'])->assertUnprocessable()->assertJsonValidationErrors('viewing_at');
        $this->postJson($base, ['listing_id' => $listing->id, 'match_percent_override' => 101])->assertUnprocessable()->assertJsonValidationErrors('match_percent_override');
        $id = $this->postJson($base, ['listing_id' => $listing->id])->assertCreated()->json('match.id');
        $this->postJson($base, ['listing_id' => $listing->id])->assertUnprocessable()->assertJsonValidationErrors('listing_id');
        $this->putJson("$base/$id", ['shared' => true])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("$base/$id", ['expected_version' => 0, 'shared' => true])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("$base/$id", ['expected_version' => 1, 'viewing_status' => 'scheduled'])->assertUnprocessable()->assertJsonValidationErrors('viewing_at');
        $this->putJson("$base/$id", ['expected_version' => 1, 'notes' => 'Reviewed'])->assertOk()->assertJsonPath('match.version', 2);
        $this->deleteJson("$base/$id", ['expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->getJson($base)->assertOk()->assertJsonPath('matches.total', 1)->assertJsonPath('matches.data.0.notes', 'Reviewed');
        $this->deleteJson("$base/$id", ['expected_version' => 2])->assertOk()->assertJsonPath('deleted', true)->assertJsonPath('version', 2);
        $this->getJson($base)->assertOk()->assertJsonPath('matches.total', 0);
    }

    public function test_viewing_statuses_are_organization_editable_and_archived_values_survive(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($owner = $this->member($org));
        $lead = $this->lead($org, $owner);
        $listing = $this->listing($org, 'MATCH-STATUS', 'Dubai');
        $base = "/crm/leads/{$lead->id}/matches";
        $settings = '/crm/settings/lead-matches';
        $this->getJson($settings)->assertOk()->assertJsonPath('configuration.version', 0)->assertJsonPath('configuration.viewing_statuses.0.value', 'not_scheduled');
        $this->assertDatabaseCount('crm_lead_match_settings', 0);
        $choices = [['value' => 'not_scheduled', 'label' => 'Open', 'active' => true], ['value' => 'scheduled', 'label' => 'Scheduled', 'active' => true], ['value' => 'confirmed', 'label' => 'Confirmed by client', 'active' => true]];
        $this->putJson($settings, ['viewing_statuses' => $choices])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson($settings, ['expected_version' => 0, 'viewing_statuses' => $choices])->assertOk()->assertJsonPath('configuration.version', 1)->assertJsonPath('configuration.viewing_statuses.2.value', 'confirmed');
        $this->putJson($settings, ['expected_version' => 0, 'viewing_statuses' => $choices])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $id = $this->postJson($base, ['listing_id' => $listing->id, 'viewing_status' => 'confirmed'])->assertCreated()->assertJsonPath('match.viewing_status', 'confirmed')->json('match.id');
        $this->putJson($settings, ['expected_version' => 1, 'viewing_statuses' => array_slice($choices, 0, 2)])->assertUnprocessable()->assertJsonValidationErrors('viewing_statuses');
        $choices[2]['active'] = false;
        $this->putJson($settings, ['expected_version' => 1, 'viewing_statuses' => $choices])->assertOk()->assertJsonPath('configuration.version', 2);
        $this->putJson("$base/$id", ['expected_version' => 1, 'notes' => 'Keep historical status'])->assertOk()->assertJsonPath('match.viewing_status', 'confirmed');
        $new = $this->listing($org, 'MATCH-NEW', 'Dubai');
        $this->postJson($base, ['listing_id' => $new->id, 'viewing_status' => 'confirmed'])->assertUnprocessable()->assertJsonValidationErrors('viewing_status');
        $this->putJson($settings, ['expected_version' => 2, 'viewing_statuses' => [$choices[0], $choices[0]]])->assertUnprocessable()->assertJsonValidationErrors('viewing_statuses');
        $this->actingAs($this->member($org, OrganizationRole::Member))->getJson($settings)->assertOk()->assertJsonPath('configuration.permissions.edit', false);
        $this->putJson($settings, ['expected_version' => 2, 'viewing_statuses' => $choices])->assertForbidden();
        $other = Organization::factory()->create();
        $this->actingAs($this->member($other))->getJson($settings)->assertOk()->assertJsonPath('configuration.version', 0)->assertJsonPath('configuration.viewing_statuses.0.label', 'Not scheduled');
    }

    public function test_lead_visibility_edit_grants_conversion_and_tenant_scope(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $member = $this->member($org, OrganizationRole::Member);
        $lead = $this->lead($org, $member);
        $hidden = $this->lead($org, $owner);
        $foreignOrg = Organization::factory()->create();
        $foreignLead = $this->lead($foreignOrg, $this->member($foreignOrg));
        $listing = $this->listing($org, 'MATCH-SCOPE', 'Dubai');
        $base = "/crm/leads/{$lead->id}/matches";
        $this->actingAs($member)->getJson($base)->assertOk()->assertJsonPath('permissions.add', false);
        $this->getJson($base.'/suggest')->assertOk()->assertJsonPath('candidates.data.0.permissions.add', false);
        $this->postJson($base, ['listing_id' => $listing->id])->assertForbidden();
        $this->getJson("/crm/leads/{$hidden->id}/matches")->assertNotFound();
        $this->getJson("/crm/leads/{$foreignLead->id}/matches/suggest")->assertNotFound();
        $this->actingAs($owner)->put(route('crm.hierarchy.edit-grant'), ['user_id' => $member->id, 'allowed' => true])->assertRedirect();
        $id = $this->actingAs($member)->postJson($base, ['listing_id' => $listing->id])->assertCreated()->json('match.id');
        $this->getJson($base)->assertOk()->assertJsonPath('matches.data.0.permissions.edit', true);
        $this->putJson("/crm/leads/{$hidden->id}/matches/$id", ['expected_version' => 1, 'shared' => true])->assertNotFound();
        $lead->update(['converted_at' => now()]);
        $this->getJson($base)->assertOk()->assertJsonPath('matches.data.0.permissions.edit', false)->assertJsonPath('permissions.add', false);
        $this->putJson("$base/$id", ['expected_version' => 1, 'shared' => true])->assertUnprocessable()->assertJsonValidationErrors('lead');
    }
}
