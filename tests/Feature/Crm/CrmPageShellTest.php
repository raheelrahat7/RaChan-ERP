<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CrmPageShellTest extends TestCase
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

    public function test_deals_page_defaults_to_an_accessible_pipeline_and_matches_the_json_endpoint(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $first = $this->actingAs($owner)->postJson('/crm/deals/pipelines', ['name' => 'Off plan'])->assertSuccessful()->json('pipeline.id');
        $second = $this->postJson('/crm/deals/pipelines', ['name' => 'Resale'])->assertSuccessful()->json('pipeline.id');
        $this->postJson('/crm/deals', ['title' => 'Marina 1204', 'category' => 'offplan', 'pipeline_id' => $first, 'amount' => '1500000'])->assertCreated();
        $this->postJson('/crm/deals', ['title' => 'Palm 08', 'category' => 'resale', 'pipeline_id' => $second])->assertCreated();

        $page = $this->get(route('deals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('crm/Deals')
            ->has('pipelines', 2)
            ->has('deals.data', 1)
            ->where('deals.data.0.title', fn ($title) => in_array($title, ['Marina 1204', 'Palm 08'], true))
            ->has('stageCounts')
            ->has('categoryOptions')
            ->where('filters.pipeline_id', fn ($id) => in_array($id, [$first, $second], true))
            ->etc());

        $json = $this->getJson('/crm/deals?pipeline_id='.$second)->assertOk()->json();
        $this->get(route('deals.index', ['pipeline_id' => $second]))->assertInertia(fn (Assert $page) => $page
            ->where('deals.data.0.id', $json['deals']['data'][0]['id'])->where('filters.pipeline_id', (string) $second)->etc());
        $this->assertNotNull($page);
    }

    public function test_deal_page_exposes_the_detail_payload_and_hides_other_organizations(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $pipeline = $this->actingAs($owner)->postJson('/crm/deals/pipelines', ['name' => 'Listings'])->assertSuccessful()->json('pipeline.id');
        $deal = $this->postJson('/crm/deals', ['title' => 'Villa 3', 'category' => 'listing', 'pipeline_id' => $pipeline])->assertCreated()->json('deal.id');

        $this->get(route('deals.show', $deal))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('crm/DealShow')->where('deal.id', $deal)->where('deal.title', 'Villa 3')->has('stages')->has('activities.data')->has('history.data')->has('timeline.data')->has('pipelines')->etc());

        $outsider = $this->member(Organization::factory()->create(), OrganizationRole::Owner);
        $this->actingAs($outsider)->get(route('deals.show', $deal))->assertNotFound();
        $this->get('/deals/not-a-number')->assertNotFound();
    }

    public function test_permissions_page_is_for_owners_and_administrators_while_settings_needs_crm_access(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $member = $this->member($org, OrganizationRole::Member);

        $this->actingAs($owner)->get(route('crm.permissions'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/Permissions'));
        $this->actingAs($member)->get(route('crm.permissions'))->assertForbidden();
        $this->actingAs($member)->get(route('crm.deal-pipelines.page'))->assertForbidden();
        $this->actingAs($owner)->get(route('crm.deal-pipelines.page'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/DealPipelines'));
        $this->actingAs($owner)->get(route('crm.settings'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/Settings'));
        $this->get(route('deals.index'))->assertOk();
        auth()->logout();
        $this->get(route('deals.index'))->assertRedirect(route('login'));
    }

    public function test_reference_settings_pages_render_known_sections_only(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);

        foreach (['currency', 'locations', 'num-documents', 'num-invoices'] as $section) {
            $this->actingAs($owner)->get(route('crm.settings.reference', $section))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('crm/SettingsReference')->where('section', $section));
        }
        $this->get('/crm/settings/reference/unknown')->assertNotFound();
        $this->getJson('/organization/reference-settings/currencies')->assertOk()->assertJsonStructure(['records']);
    }

    public function test_field_list_serves_every_entity_through_the_shared_endpoints(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);

        foreach (['contact', 'company', 'deal'] as $entity) {
            $this->actingAs($owner)->post('/crm/custom-fields', ['entity' => $entity, 'name' => 'Passport '.$entity, 'key' => 'passport', 'type' => 'text'])->assertRedirect();
            $this->getJson('/crm/settings/fields?entity='.$entity)->assertOk()->assertJsonPath('fields.0.key', 'passport')->assertJsonCount(1, 'fields');
        }
        $this->getJson('/crm/settings/fields')->assertOk()->assertJsonCount(0, 'fields');
    }

    public function test_workflow_pages_render_and_pipeline_management_needs_settings_access(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $member = $this->member($org, OrganizationRole::Member);

        $this->actingAs($owner)->get(route('workflows.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('workflows/Index'));
        $this->get(route('workflows.pipelines'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('workflows/Pipelines'));
        $this->actingAs($member)->get(route('workflows.pipelines'))->assertForbidden();
    }

    public function test_reference_workflow_endpoints_support_the_board_flow(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner);

        $pipeline = $this->postJson('/reference-workflows/pipelines', ['kind' => 'recruitment', 'name' => 'Hiring'])->assertSuccessful()->json('pipeline');
        $this->assertCount(3, $pipeline['stages']);
        $record = $this->postJson('/reference-workflows', ['pipeline_id' => $pipeline['id'], 'title' => 'Sara', 'assigned_to' => $owner->id, 'operation_key' => (string) Str::uuid(), 'details' => ['name' => 'Sara', 'job_title' => 'Agent']])->assertSuccessful()->json('record');
        $success = collect($pipeline['stages'])->firstWhere('type', 'success');
        $this->putJson('/reference-workflows/'.$record['id'].'/stage', ['stage_id' => $success['id'], 'expected_version' => $this->getJson('/reference-workflows/'.$record['id'])->json('record.version')])->assertSuccessful();
        $this->getJson('/reference-workflows?kind=recruitment')->assertOk()->assertJsonPath('records.data.0.title', 'Sara');
        $this->getJson('/reference-workflows/'.$record['id'])->assertOk()->assertJsonCount(2, 'history.data');
    }

    public function test_selection_lists_page_is_admin_only_and_options_round_trip(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $member = $this->member($org, OrganizationRole::Member);

        $this->actingAs($member)->get(route('crm.settings.lists'))->assertForbidden();
        $this->actingAs($owner)->get(route('crm.settings.lists'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/SettingsLists'));
        $id = $this->postJson('/crm/settings/options', ['list_key' => 'sources', 'name' => 'Instagram', 'position' => 1, 'active' => true])->assertSuccessful()->json('option.id');
        $this->putJson('/crm/settings/options/'.$id, ['list_key' => 'sources', 'name' => 'Instagram', 'position' => 1, 'active' => false])->assertSuccessful();
        $this->getJson('/crm/settings/data')->assertOk()->assertJsonPath('options.0.name', 'Instagram');
    }

    public function test_catalog_pages_render_known_sections_and_lead_products_round_trip(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner);

        foreach (['taxes', 'units', 'detail-templates', 'company-details', 'mailboxes', 'products'] as $section) {
            $this->get(route('crm.settings.catalog', $section))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/SettingsCatalog')->where('section', $section));
        }
        $this->get('/crm/settings/catalog/nope')->assertNotFound();

        $product = $this->postJson('/organization/crm-catalog/products', ['code' => 'survey', 'name' => 'Survey', 'active' => true, 'settings' => ['price' => 250, 'currency' => 'AED']])->assertSuccessful()->json('record.id');
        $this->getJson('/organization/crm-catalog/products')->assertOk()->assertJsonPath('records.0.name', 'Survey');
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $owner->id, 'first_name' => 'Lina', 'last_name' => 'K'])->id;
        $this->postJson('/crm/leads/'.$lead.'/products', ['product_id' => $product, 'quantity' => 2, 'unit_price' => 250, 'currency' => 'AED'])->assertSuccessful();
        $this->getJson('/crm/leads/'.$lead.'/products')->assertOk()->assertJsonPath('products.0.name', 'Survey')->assertJsonStructure(['estimates']);
    }

    public function test_deal_automation_page_filters_and_export_work_end_to_end(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $member = $this->member($org, OrganizationRole::Member);

        $this->actingAs($member)->get(route('crm.deal-automation.page'))->assertForbidden();
        $this->actingAs($owner)->get(route('crm.deal-automation.page'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/DealAutomation'));

        $pipeline = $this->postJson('/crm/deals/pipelines', ['name' => 'Sales'])->assertSuccessful()->json('pipeline');
        $stage = collect($this->getJson('/crm/deals/configuration')->json('pipelines'))->firstWhere('id', $pipeline['id'])['stages'][0]['id'];
        $this->postJson('/crm/deals', ['title' => 'Villa 9', 'category' => 'listing', 'pipeline_id' => $pipeline['id']])->assertCreated();
        $this->postJson('/crm/deals/automation', ['name' => 'Ping', 'pipeline_id' => $pipeline['id'], 'stage_id' => $stage, 'action' => 'notify_assignee', 'active' => true, 'delay_minutes' => 0, 'conditions' => []])->assertSuccessful();
        $this->getJson('/crm/deals/automation')->assertOk()->assertJsonPath('rules.0.name', 'Ping');

        $this->get(route('deals.index', ['pipeline_id' => $pipeline['id']]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('filterFields'));
        $this->get('/crm/deals/export?pipeline_id='.$pipeline['id'])->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_working_calendar_page_and_endpoint_round_trip(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $member = $this->member($org, OrganizationRole::Member);

        $this->actingAs($member)->get(route('crm.settings.calendar.page'))->assertForbidden();
        $this->actingAs($owner)->get(route('crm.settings.calendar.page'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/WorkingCalendar'));
        $this->getJson('/crm/settings/calendar')->assertOk()->assertJsonPath('calendar', null);
        $this->putJson('/crm/settings/calendar', ['expected_version' => 0, 'working_days' => [1 => ['start' => '09:00', 'end' => '18:00'], 2 => ['start' => '09:00', 'end' => '13:00']], 'holidays' => ['2026-12-02']])->assertSuccessful();
        $this->getJson('/crm/settings/calendar')->assertOk()->assertJsonPath('calendar.working_days.2.end', '13:00')->assertJsonPath('calendar.holidays.0', '2026-12-02');
    }

    public function test_an_estimate_created_for_a_lead_shows_on_the_lead(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $owner->id, 'first_name' => 'Noor', 'last_name' => 'A'])->id;
        $pipeline = $this->postJson('/reference-workflows/pipelines', ['kind' => 'estimate', 'name' => 'Quotes'])->assertSuccessful()->json('pipeline.id');

        $this->postJson('/reference-workflows', ['pipeline_id' => $pipeline, 'title' => 'Estimate – Noor', 'assigned_to' => $owner->id, 'lead_id' => $lead, 'operation_key' => (string) Str::uuid(), 'details' => ['currency' => 'AED', 'lines' => [['description' => 'Survey', 'quantity' => '2', 'unit_price' => '250']]]])->assertSuccessful();
        $this->getJson('/crm/leads/'.$lead.'/products')->assertOk()->assertJsonPath('estimates.0.title', 'Estimate – Noor');
    }

    public function test_workflow_stage_rules_are_saved_and_returned(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner);
        $pipeline = $this->postJson('/reference-workflows/pipelines', ['kind' => 'estimate', 'name' => 'Quotes'])->assertSuccessful()->json('pipeline');
        $first = $pipeline['stages'][0]['id'];

        $this->postJson('/reference-workflows/pipelines/'.$pipeline['id'].'/stages', ['name' => 'Review', 'type' => 'normal', 'active' => true, 'position' => 4, 'color' => '#336699', 'is_initial' => false, 'allowed_from_stage_ids' => [$first], 'entry_roles' => ['owner'], 'required_fields' => ['lines'], 'source_statuses' => null])->assertSuccessful();
        $stage = collect($this->getJson('/reference-workflows?kind=estimate')->json('pipelines.0.stages'))->firstWhere('name', 'Review');
        $this->assertSame([$first], $stage['allowed_from_stage_ids']);
        $this->assertSame(['owner'], $stage['entry_roles']);
        $this->assertSame(['lines'], $stage['required_fields']);
    }

    public function test_deal_page_supports_pipeline_transfer_and_exposes_finance_links(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner);
        $first = $this->postJson('/crm/deals/pipelines', ['name' => 'Sales'])->assertSuccessful()->json('pipeline.id');
        $second = $this->postJson('/crm/deals/pipelines', ['name' => 'Listings'])->assertSuccessful()->json('pipeline.id');
        $deal = $this->postJson('/crm/deals', ['title' => 'Villa 5', 'category' => 'listing', 'pipeline_id' => $first])->assertCreated()->json('deal');
        $target = collect($this->getJson('/crm/deals/configuration')->json('pipelines'))->firstWhere('id', $second)['stages'][0]['id'];

        $this->get(route('deals.show', $deal['id']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/DealShow')->has('financialRecords')->where('deal.permissions.transfer', true)->etc());
        $this->putJson('/crm/deals/'.$deal['id'].'/transfer', ['expected_version' => $deal['version'], 'pipeline_id' => $second, 'stage_id' => $target, 'confirmed' => true])->assertSuccessful()->assertJsonPath('deal.pipeline_id', $second);
        $this->getJson('/crm/leads/qualified')->assertOk()->assertJsonStructure(['leads' => ['data']]);
    }

    public function test_contact_and_company_pages_render_details_and_edits_use_versions(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner);
        $company = $this->postJson('/crm/companies', ['name' => 'Acme Realty', 'email' => 'hello@acme.test'])->assertCreated()->json('company');
        $contact = $this->postJson('/crm/contacts', ['first_name' => 'Lina', 'last_name' => 'Karim', 'company' => 'Acme Realty'], ['Accept' => 'application/json'])->assertSuccessful()->json('contact');

        $this->get(route('companies.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/Companies'));
        $this->get(route('companies.show', $company['id']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/CompanyShow')->where('company.name', 'Acme Realty')->has('customFields')->has('activities.data')->etc());
        $this->get(route('contacts.show', $contact['id']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/ContactShow')->where('contact.first_name', 'Lina')->has('leads')->has('deals')->etc());
        $this->putJson('/crm/contacts/'.$contact['id'], ['expected_version' => $contact['version'], 'first_name' => 'Lena'])->assertSuccessful()->assertJsonPath('contact.first_name', 'Lena');
        $this->putJson('/crm/contacts/'.$contact['id'], ['expected_version' => $contact['version'], 'first_name' => 'Stale'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $other = $this->member(Organization::factory()->create(), OrganizationRole::Owner);
        $this->actingAs($other)->get(route('contacts.show', $contact['id']))->assertNotFound();
    }
}
