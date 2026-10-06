<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyAndPickerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_party_detail_link_and_version_checks_are_organization_scoped(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);
        $company = CrmAccount::create(['organization_id' => $org->id, 'name' => 'North']);
        $contact = CrmContact::create(['organization_id' => $org->id, 'first_name' => 'Ada', 'last_name' => 'West']);
        $this->actingAs($user)->getJson('/crm/companies?q=North')->assertOk()->assertJsonPath('companies.total', 1);
        $this->postJson('/crm/companies', ['name' => 'South'])->assertCreated()->assertJsonPath('company.version', 1);
        $this->getJson("/crm/contacts/{$contact->id}")->assertOk()->assertJsonPath('contact.version', 1)->assertJsonPath('contact.permissions.edit', true);
        $this->putJson("/crm/contacts/{$contact->id}", ['expected_version' => 1, 'account_id' => $company->id])->assertOk()->assertJsonPath('contact.account.id', $company->id)->assertJsonPath('contact.version', 2);
        $this->putJson("/crm/contacts/{$contact->id}", ['expected_version' => 1, 'first_name' => 'Stale'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("/crm/companies/{$company->id}", ['expected_version' => 1, 'name' => 'North Group'])->assertOk()->assertJsonPath('company.version', 2)->assertJsonPath('company.contacts.0.id', $contact->id);
        $other = Organization::factory()->create();
        $foreign = CrmAccount::create(['organization_id' => $other->id, 'name' => 'Foreign']);
        $this->putJson("/crm/contacts/{$contact->id}", ['expected_version' => 2, 'account_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->getJson("/crm/companies/{$foreign->id}")->assertNotFound();
    }

    public function test_qualified_picker_only_returns_visible_won_leads_without_deals(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);
        $lead = app(ManageLeadPipeline::class)->create($org, $user, ['first_name' => 'Eligible', 'last_name' => 'Person']);
        $this->actingAs($user)->getJson('/crm/leads/qualified?q=Eligible')->assertOk()->assertJsonPath('leads.total', 0);
        $won = Pipeline::where('organization_id', $org->id)->sole()->stages()->where('type', 'won')->firstOrFail();
        app(ManageLeadPipeline::class)->move($org, $user, $lead, ['stage_id' => $won->id, 'expected_stage_id' => $lead->current_stage_id]);
        $this->getJson('/crm/leads/qualified?q=Eligible')->assertOk()->assertJsonPath('leads.total', 1)->assertJsonPath('leads.data.0.name', 'Eligible Person');
    }

    public function test_other_settings_are_tenant_scoped_and_product_reads_are_active_only(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $setting = $this->actingAs($owner)->postJson('/organization/crm-catalog/other-settings', ['code' => 'display_hint', 'name' => 'Display hint', 'active' => true, 'settings' => ['value' => 'Compact', 'description' => 'CRM view preference']])->assertOk()->assertJsonPath('record.settings.value', 'Compact')->assertJsonPath('record.version', 1)->json('record.id');
        $this->getJson('/organization/crm-catalog/other-settings')->assertOk()->assertJsonPath('records.0.code', 'display_hint');
        $this->putJson("/organization/crm-catalog/other-settings/{$setting}", ['code' => 'display_hint', 'name' => 'Display hint', 'active' => true, 'settings' => ['value' => 'Expanded'], 'expected_version' => 1])->assertOk()->assertJsonPath('record.version', 2);
        $this->putJson("/organization/crm-catalog/other-settings/{$setting}", ['code' => 'display_hint', 'name' => 'Display hint', 'active' => true, 'settings' => ['value' => 'Stale'], 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson('/organization/crm-catalog/products', ['code' => 'visible', 'name' => 'Visible', 'active' => true, 'settings' => ['price' => '10', 'currency' => 'AED']])->assertOk();
        $this->postJson('/organization/crm-catalog/products', ['code' => 'hidden', 'name' => 'Hidden', 'active' => false, 'settings' => ['price' => '10', 'currency' => 'AED']])->assertOk();
        $member = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($member, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($member)->getJson('/organization/crm-catalog/products')->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.code', 'visible');
        $this->postJson('/organization/crm-catalog/products', ['code' => 'new', 'name' => 'New', 'active' => true, 'settings' => ['price' => '10', 'currency' => 'AED']])->assertForbidden();
    }
}
