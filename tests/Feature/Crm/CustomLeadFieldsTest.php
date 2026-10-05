<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\CustomField;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomLeadFieldsTest extends TestCase
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

    public function test_field_settings_validate_keys_and_preserve_values_on_rename_and_archive(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Duplicate Source', 'key' => 'source', 'type' => 'text'])->assertSessionHasErrors('key');
        $this->post(route('crm.custom-fields.store'), ['name' => 'Dealer Category', 'key' => 'dealer_category', 'type' => 'single_select', 'options' => ['Dealer', 'Direct'], 'required' => true])->assertRedirect();
        $field = CustomField::sole();
        $this->post(route('crm.leads.store'), ['first_name' => 'Sara', 'last_name' => 'Ali'])->assertSessionHasErrors('custom_fields.dealer_category');
        $this->post(route('crm.leads.store'), ['first_name' => 'Sara', 'last_name' => 'Ali', 'custom_fields' => ['dealer_category' => 'Dealer']])->assertRedirect();
        $lead = CrmLead::sole();
        $this->assertDatabaseHas('crm_custom_field_values', ['lead_id' => $lead->id, 'field_id' => $field->id, 'search_text' => 'Dealer']);
        $this->put(route('crm.custom-fields.update', $field), ['name' => 'Dealer type', 'key' => 'dealer_category', 'type' => 'single_select'])->assertRedirect();
        $this->assertDatabaseHas('crm_custom_field_values', ['lead_id' => $lead->id, 'search_text' => 'Dealer']);
        $this->delete(route('crm.custom-fields.archive', $field))->assertRedirect();
        $this->assertDatabaseHas('crm_custom_field_values', ['lead_id' => $lead->id, 'search_text' => 'Dealer']);
        $this->assertDatabaseHas('crm_custom_fields', ['id' => $field->id, 'active' => false]);
    }

    public function test_restricted_fields_are_not_exposed_or_writable_by_a_manager(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Admin reference', 'key' => 'admin_reference', 'type' => 'text', 'view_roles' => ['owner', 'administrator'], 'edit_roles' => ['owner', 'administrator']])->assertRedirect();
        $this->actingAs($manager)->get(route('crm.custom-fields.index'))->assertForbidden();
        $this->post(route('crm.leads.store'), ['first_name' => 'Nadia', 'last_name' => 'Khan', 'custom_fields' => ['admin_reference' => 'SECRET']])->assertSessionHasErrors('custom_fields.admin_reference');
        $this->post(route('crm.leads.store'), ['first_name' => 'Nadia', 'last_name' => 'Khan'])->assertRedirect();
        $this->get(route('crm.leads.index'))->assertInertia(fn (Assert $page) => $page->where('customFields', [])->where('leads.0.custom_fields', [])->etc());
        $other = Organization::factory()->create();
        $outsider = $this->member($other, OrganizationRole::Owner);
        $this->actingAs($outsider)->put(route('crm.custom-fields.update', CustomField::sole()), ['name' => 'Stolen'])->assertNotFound();
    }

    public function test_search_and_combined_filters_apply_only_to_visible_records_and_fields(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Max Price', 'key' => 'max_price', 'type' => 'currency'])->assertRedirect();
        $this->post(route('crm.custom-fields.store'), ['name' => 'Admin code', 'key' => 'admin_code', 'type' => 'text', 'view_roles' => ['owner'], 'edit_roles' => ['owner']])->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'Amina', 'last_name' => 'Saleh', 'city' => 'Dubai', 'custom_fields' => ['max_price' => '300000', 'admin_code' => 'LOCKED-77']])->assertRedirect();
        $lead = CrmLead::sole();
        $this->put(route('crm.leads.assignment', $lead), ['assigned_to' => $manager->id])->assertRedirect();
        $this->actingAs($manager)->get(route('crm.leads.index', ['q' => 'LOCKED-77']))->assertInertia(fn (Assert $page) => $page->has('leads', 0)->etc());
        $this->get(route('crm.leads.index', ['filters' => [
            ['field' => 'city', 'operator' => 'equals', 'value' => 'Dubai'],
            ['field' => 'custom:max_price', 'operator' => 'gte', 'value' => '250000'],
        ]]))->assertInertia(fn (Assert $page) => $page->has('leads', 1)->where('filteredTotal', 1)->etc());
        $this->get(route('crm.leads.index', ['filters' => [['field' => 'custom:admin_code', 'operator' => 'equals', 'value' => 'LOCKED-77']]]))->assertSessionHasErrors('filters.0.field');
        $this->actingAs($owner)->get(route('crm.leads.index', ['q' => 'LOCKED-77']))->assertInertia(fn (Assert $page) => $page->has('leads', 1)->etc());
    }

    public function test_stage_totals_sum_the_first_visible_currency_field(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Budget', 'key' => 'budget', 'type' => 'currency'])->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'A', 'last_name' => 'One', 'custom_fields' => ['budget' => '100']])->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'B', 'last_name' => 'Two', 'custom_fields' => ['budget' => '250.5']])->assertRedirect();
        $this->post(route('crm.leads.store'), ['first_name' => 'C', 'last_name' => 'Three'])->assertRedirect();

        $this->get(route('crm.leads.index'))->assertInertia(fn (Assert $page) => $page
            ->where('amountField.key', 'budget')
            ->where('stageCounts.0.count', 3)
            ->where('stageCounts.0.amount', 350.5)
            ->where('stageCounts.1.amount', 0)
            ->etc());

        $this->actingAs($manager)->post(route('crm.custom-fields.store'), ['name' => 'X', 'key' => 'x', 'type' => 'currency'])->assertForbidden();
    }
}
