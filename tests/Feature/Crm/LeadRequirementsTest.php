<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\LeadRequirement;
use App\Domain\Crm\Services\LeadRequirementSchema;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadRequirementsTest extends TestCase
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
        return CrmLead::create(['organization_id' => $org->id, 'first_name' => 'Requirement', 'last_name' => 'Buyer', 'assigned_to' => $assignee->id]);
    }

    public function test_complete_requirements_round_trip_and_partial_updates_are_versioned(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($owner = $this->member($org));
        $lead = $this->lead($org, $owner);
        $url = "/crm/leads/{$lead->id}/requirements";
        $this->getJson($url)->assertOk()->assertJsonPath('requirement.id', null)->assertJsonPath('requirement.version', 0)->assertJsonPath('requirement.data.amenities', [])->assertJsonPath('requirement.permissions.edit', true)->assertJsonPath('configuration.version', 0);
        $this->assertDatabaseCount('crm_lead_requirements', 0);
        $this->assertDatabaseCount('crm_lead_requirement_settings', 0);
        $data = ['type' => 'buyer', 'purpose' => 'buy', 'unit_category' => 'residential', 'emirate' => 'dubai', 'property_type' => 'villa', 'location' => 'Palm', 'bedrooms_min' => 2, 'bedrooms_max' => 4, 'bathrooms_min' => 2, 'furnishing' => 'furnished', 'size_min' => '1200', 'size_max' => '1800.50', 'size_unit' => 'sq_ft', 'budget_min' => '1500000', 'budget_max' => '2500000.50', 'budget_currency' => 'AED', 'rent_frequency' => 'yearly', 'timeline' => 'immediate', 'completion_status' => 'off_plan', 'handover_on' => '2027-06-30', 'payment_method' => 'mortgage', 'down_payment_percent' => '20', 'roi_percent' => '7.5', 'financing_status' => 'pre_approved', 'language' => 'en', 'amenities' => ['pool', 'gym'], 'preferences' => 'Quiet street', 'lead_score' => 85, 'temperature' => 'hot'];
        $this->putJson($url, ['expected_version' => 0, 'data' => $data])->assertOk()->assertJsonPath('requirement.version', 1)->assertJsonPath('requirement.lead_id', $lead->id)->assertJsonPath('requirement.data.budget_max', '2500000.50')->assertJsonPath('requirement.data.roi_percent', '7.50')->assertJsonPath('requirement.permissions.read', true);
        $stored = $this->getJson($url)->assertOk()->json('requirement.data');
        $this->assertEquals(app(LeadRequirementSchema::class)->normalize($data), $stored);
        $this->putJson($url, ['expected_version' => 1, 'data' => ['preferences' => null, 'amenities' => [], 'lead_score' => 90]])->assertOk()->assertJsonPath('requirement.version', 2)->assertJsonPath('requirement.data.preferences', null)->assertJsonPath('requirement.data.amenities', [])->assertJsonPath('requirement.data.bedrooms_min', 2)->assertJsonPath('requirement.data.lead_score', 90);
        $this->putJson($url, ['data' => ['lead_score' => 1]])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson($url, ['expected_version' => 1, 'data' => ['lead_score' => 1]])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->getJson($url)->assertOk()->assertJsonPath('requirement.version', 2)->assertJsonPath('requirement.data.lead_score', 90);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead.requirements_updated', 'subject_id' => $lead->id]);
    }

    public function test_validation_checks_merged_ranges_and_field_types_without_mutating(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($owner = $this->member($org));
        $lead = $this->lead($org, $owner);
        $url = "/crm/leads/{$lead->id}/requirements";
        $this->putJson($url, ['expected_version' => 0, 'data' => ['budget_min' => '100.01', 'budget_max' => '100.02', 'budget_currency' => 'AED', 'size_min' => '2.01', 'size_max' => '2.02', 'size_unit' => 'sq_m', 'bedrooms_min' => 2, 'bedrooms_max' => 3]])->assertOk();
        foreach ([
            [['budget_min' => '100.03'], 'data.budget_max'],
            [['size_min' => '2.03'], 'data.size_max'],
            [['bedrooms_max' => 1], 'data.bedrooms_max'],
            [['budget_currency' => null], 'data.budget_currency'],
            [['size_unit' => null], 'data.size_unit'],
            [['lead_score' => 101], 'data.lead_score'],
            [['down_payment_percent' => '100.01'], 'data.down_payment_percent'],
            [['roi_percent' => '1000.01'], 'data.roi_percent'],
            [['budget_min' => '-1'], 'data.budget_min'],
            [['size_min' => '1.001'], 'data.size_min'],
            [['handover_on' => '2027-02-30'], 'data.handover_on'],
            [['temperature' => 'unknown'], 'data.temperature'],
            [['amenities' => ['pool', 'pool']], 'data.amenities.0'],
            [['unknown' => true], 'data'],
        ] as [$data, $field]) {
            $this->putJson($url, ['expected_version' => 1, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->getJson($url)->assertOk()->assertJsonPath('requirement.version', 1)->assertJsonPath('requirement.data.budget_min', '100.01');
    }

    public function test_access_follows_lead_visibility_edit_grants_and_conversion(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $member = $this->member($org, OrganizationRole::Member);
        $lead = $this->lead($org, $member);
        $hidden = $this->lead($org, $owner);
        $foreignOrg = Organization::factory()->create();
        $foreign = $this->lead($foreignOrg, $this->member($foreignOrg));
        $url = "/crm/leads/{$lead->id}/requirements";
        $this->actingAs($member)->getJson($url)->assertOk()->assertJsonPath('requirement.permissions.edit', false);
        $this->putJson($url, ['expected_version' => 0, 'data' => ['lead_score' => 50]])->assertForbidden();
        $this->getJson("/crm/leads/{$hidden->id}/requirements")->assertNotFound();
        $this->getJson("/crm/leads/{$foreign->id}/requirements")->assertNotFound();
        $this->actingAs($owner)->put(route('crm.hierarchy.edit-grant'), ['user_id' => $member->id, 'allowed' => true])->assertRedirect();
        $this->actingAs($member)->putJson($url, ['expected_version' => 0, 'data' => ['lead_score' => 50]])->assertOk()->assertJsonPath('requirement.permissions.edit', true);
        $this->putJson("/crm/leads/{$hidden->id}/requirements", ['expected_version' => 0, 'data' => ['lead_score' => 50]])->assertNotFound();
        $lead->update(['converted_at' => now()]);
        $this->getJson($url)->assertOk()->assertJsonPath('requirement.permissions.edit', false);
        $this->putJson($url, ['expected_version' => 1, 'data' => ['lead_score' => 70]])->assertUnprocessable()->assertJsonValidationErrors('lead');
        $this->assertSame(1, LeadRequirement::sole()->version);
    }

    public function test_organization_choices_are_versioned_and_used_values_can_only_be_archived(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($owner = $this->member($org));
        $lead = $this->lead($org, $owner);
        $url = "/crm/leads/{$lead->id}/requirements";
        $settings = '/crm/settings/lead-requirements';
        $this->putJson($url, ['expected_version' => 0, 'data' => ['property_type' => 'villa', 'amenities' => ['pool']]])->assertOk();
        foreach (['property_type', 'amenities'] as $field) {
            $this->putJson($settings, ['expected_version' => 0, 'choices' => [$field => []]])->assertUnprocessable()->assertJsonValidationErrors('choices.'.$field);
        }
        $choices = ['property_type' => [['value' => 'villa', 'label' => 'Historical villa', 'active' => false], ['value' => 'duplex', 'label' => 'Duplex', 'active' => true]], 'amenities' => [['value' => 'pool', 'label' => 'Pool', 'active' => false]]];
        $this->putJson($settings, ['choices' => $choices])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson($settings, ['expected_version' => 0, 'choices' => $choices])->assertOk()->assertJsonPath('configuration.version', 1)->assertJsonPath('configuration.permissions.edit', true)->assertJsonPath('configuration.choices.property_type.1.value', 'duplex');
        $this->putJson($settings, ['expected_version' => 0, 'choices' => $choices])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson($url, ['expected_version' => 1, 'data' => ['lead_score' => 70]])->assertOk()->assertJsonPath('requirement.data.property_type', 'villa')->assertJsonPath('requirement.data.amenities', ['pool']);
        $newLead = $this->lead($org, $owner);
        $this->putJson("/crm/leads/{$newLead->id}/requirements", ['expected_version' => 0, 'data' => ['property_type' => 'villa', 'amenities' => ['pool']]])->assertUnprocessable()->assertJsonValidationErrors(['data.property_type', 'data.amenities']);
        $this->putJson("/crm/leads/{$newLead->id}/requirements", ['expected_version' => 0, 'data' => ['property_type' => 'duplex']])->assertOk();
        $this->putJson($settings, ['expected_version' => 1, 'choices' => ['type' => [['value' => 'buyer', 'label' => 'Buyer', 'active' => true], ['value' => 'buyer', 'label' => 'Duplicate', 'active' => true]]]])->assertUnprocessable()->assertJsonValidationErrors('choices.type');
        $this->actingAs($this->member($org, OrganizationRole::Member))->getJson($settings)->assertOk()->assertJsonPath('configuration.permissions.edit', false);
        $this->putJson($settings, ['expected_version' => 1, 'choices' => $choices])->assertForbidden();
        $other = Organization::factory()->create();
        $this->actingAs($this->member($other))->getJson($settings)->assertOk()->assertJsonPath('configuration.version', 0)->assertJsonPath('configuration.choices.property_type.0.value', 'apartment');
    }
}
