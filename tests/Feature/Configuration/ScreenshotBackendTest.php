<?php

namespace Tests\Feature\Configuration;

use App\Domain\Configuration\Actions\ManageReferenceConfiguration;
use App\Domain\Configuration\Services\AllocateReferenceNumber;
use App\Domain\Crm\Actions\ManageCrmSettings;
use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Actions\ManageDealAutomation;
use App\Domain\Crm\Actions\ManageDealPipelines;
use App\Domain\Crm\Actions\ManageDeals;
use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Actions\ManageWorkingCalendar;
use App\Domain\Crm\Models\CustomFieldValue;
use App\Domain\Crm\Models\DealAutomationExecution;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Models\RecordFieldValue;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Queries\JobCardDetails;
use App\Domain\Workflows\Actions\ManageReferenceWorkflows;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ScreenshotBackendTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role = OrganizationRole::Owner): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_deal_stage_amounts_follow_visible_rows_and_amount_permissions(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = app(ManageDealPipelines::class)->save($org, $owner, ['name' => 'Sales']);
        app(ManageDeals::class)->create($org, $owner, ['title' => 'One', 'category' => 'secondary', 'pipeline_id' => $pipeline->id, 'amount' => '10.25']);
        app(ManageDeals::class)->create($org, $owner, ['title' => 'Two', 'category' => 'secondary', 'pipeline_id' => $pipeline->id, 'amount' => '20.30']);
        $this->actingAs($owner)->getJson(route('crm.deals.index', ['pipeline_id' => $pipeline->id]))
            ->assertOk()->assertJsonPath('stageCounts.0.total', 2)->assertJsonPath('stageCounts.0.amount', '30.55');
    }

    public function test_settings_catalog_and_lead_products_are_scoped_and_estimates_return_a_version(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $other = Organization::factory()->create();
        $otherOwner = $this->member($other);
        $this->actingAs($owner)->postJson(route('crm-catalog.store', 'taxes'), ['code' => 'vat5', 'name' => 'VAT 5%', 'active' => true, 'settings' => ['rate' => 5]])->assertOk();
        $unit = $this->postJson(route('crm-catalog.store', 'units'), ['code' => 'each', 'name' => 'Each', 'active' => true, 'settings' => ['symbol' => 'ea', 'precision' => 0]])->assertOk()->json('record.id');
        $product = $this->postJson(route('crm-catalog.store', 'products'), ['code' => 'service', 'name' => 'Service', 'active' => true, 'settings' => ['unit_id' => $unit, 'price' => 10, 'currency' => 'AED']])->assertOk()->json('record.id');
        foreach (['detail-templates' => ['entity' => 'contact', 'fields' => ['name']], 'company-details' => ['legal_name' => 'Example LLC'], 'mailboxes' => ['email' => 'sales@example.test']] as $kind => $settings) {
            $this->postJson(route('crm-catalog.store', $kind), ['code' => 'default', 'name' => 'Default', 'active' => true, 'settings' => $settings])->assertOk();
        }
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Client', 'last_name' => 'Example', 'assigned_to' => $owner->id]);
        $line = $this->postJson(route('crm.leads.products.store', $lead->id), ['product_id' => $product, 'quantity' => 2, 'unit_price' => '10.50', 'currency' => 'AED'])->assertOk()->json('line.id');
        $workflow = app(ManageReferenceWorkflows::class)->pipeline($org, $owner, ['kind' => 'estimate', 'name' => 'Estimates']);
        $this->postJson(route('reference-workflows.store'), ['pipeline_id' => $workflow->id, 'title' => 'Quote', 'assigned_to' => $owner->id, 'operation_key' => (string) Str::uuid(), 'lead_id' => $lead->id, 'details' => ['currency' => 'AED', 'lines' => [['description' => 'Service', 'quantity' => '2', 'unit_price' => '10.50']]]])->assertOk()->assertJsonPath('record.version', 1)->assertJsonPath('record.lead_id', $lead->id);
        $this->getJson(route('crm.leads.products', $lead->id))->assertOk()->assertJsonCount(1, 'products')->assertJsonCount(1, 'estimates');
        $this->actingAs($otherOwner)->getJson(route('crm-catalog.index', 'products'))->assertOk()->assertJsonCount(0, 'records');
        $this->getJson(route('crm.leads.products', $lead->id))->assertNotFound();
        $this->postJson(route('crm.leads.products.store', $lead->id), ['product_id' => $product, 'quantity' => 1, 'unit_price' => 10, 'currency' => 'AED'])->assertNotFound();
        $this->actingAs($owner)->deleteJson(route('crm.leads.products.destroy', ['lead' => $lead->id, 'line' => $line]))->assertOk();
        $this->getJson(route('crm.leads.products', $lead->id))->assertJsonCount(0, 'products')->assertJsonCount(1, 'estimates');
    }

    public function test_typed_deal_filters_and_exports_use_current_field_permissions(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $member = $this->member($org, OrganizationRole::Member);
        $pipeline = app(ManageDealPipelines::class)->save($org, $owner, ['name' => 'Sales']);
        app(ManageCustomFields::class)->save($org, $owner, ['entity' => 'deal', 'name' => 'Budget', 'key' => 'budget', 'type' => 'currency', 'view_roles' => ['owner'], 'edit_roles' => ['owner']]);
        app(ManageDeals::class)->create($org, $owner, ['title' => 'High', 'category' => 'secondary', 'pipeline_id' => $pipeline->id, 'assigned_to' => $member->id, 'custom_fields' => ['budget' => '500.25']]);
        app(ManageDeals::class)->create($org, $owner, ['title' => 'Low', 'category' => 'secondary', 'pipeline_id' => $pipeline->id, 'custom_fields' => ['budget' => '50']]);
        $filter = ['custom_filters' => [['field' => 'budget', 'operator' => 'gte', 'value' => '100']]];
        $this->actingAs($owner)->getJson(route('crm.deals.index', $filter))->assertOk()->assertJsonPath('deals.total', 1)->assertJsonPath('deals.data.0.title', 'High');
        $response = $this->get(route('crm.deals.export', $filter))->assertOk();
        $this->assertStringContainsString('High', $response->streamedContent());
        $this->assertStringNotContainsString('Low', $response->streamedContent());
        $this->actingAs($member)->getJson(route('crm.deals.index', $filter))->assertUnprocessable();
    }

    public function test_conditional_stage_automation_moves_once_without_chaining(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = app(ManageDealPipelines::class)->save($org, $owner, ['name' => 'Sales']);
        $initial = $pipeline->stages()->where('is_initial', true)->firstOrFail();
        $next = app(ManageDealPipelines::class)->stage($org, $owner, $pipeline->id, ['name' => 'Follow up', 'type' => 'normal', 'color' => '#123456', 'active' => true, 'position' => 2]);
        app(ManageCustomFields::class)->save($org, $owner, ['entity' => 'deal', 'key' => 'qualified', 'name' => 'Qualified', 'type' => 'checkbox']);
        app(ManageDealAutomation::class)->save($org, $owner, ['name' => 'Move qualified', 'pipeline_id' => $pipeline->id, 'stage_id' => $initial->id, 'action' => 'change_stage', 'target_stage_id' => $next->id, 'conditions' => [['field' => 'qualified', 'operator' => 'equals', 'value' => true]], 'active' => true, 'delay_minutes' => 0]);
        app(ManageDealAutomation::class)->save($org, $owner, ['name' => 'Would chain', 'pipeline_id' => $pipeline->id, 'stage_id' => $next->id, 'action' => 'create_follow_up', 'active' => true, 'delay_minutes' => 0]);
        $deal = app(ManageDeals::class)->create($org, $owner, ['title' => 'Auto', 'category' => 'secondary', 'pipeline_id' => $pipeline->id, 'custom_fields' => ['qualified' => true]]);
        $this->assertSame($next->id, $deal->current_stage_id);
        $this->assertDatabaseCount('crm_deal_automation_executions', 1);
        app(ManageDealAutomation::class)->execute(DealAutomationExecution::sole()->id);
        $this->assertDatabaseCount('crm_deal_stage_histories', 2);
        $this->assertCount(0, $deal->activities);
    }

    public function test_calendar_excludes_weekends_for_delayed_deal_rules(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(11, 0)); // Friday 16:00 in Karachi.
        $org = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $owner = $this->member($org);
        app(ManageWorkingCalendar::class)->save($org, $owner, ['expected_version' => 0, 'working_days' => [1 => ['start' => '09:00', 'end' => '17:00'], 5 => ['start' => '09:00', 'end' => '17:00']], 'holidays' => []]);
        $deadline = app(ManageWorkingCalendar::class)->deadline($org, 120);
        $this->assertSame('2026-10-12 10:00', $deadline->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
        $this->travelBack();
    }

    public function test_currency_rebase_is_atomic_and_location_parents_stay_in_the_organization(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $settings = app(ManageReferenceConfiguration::class);
        $settings->save($org, $owner, 'currencies', ['code' => 'AED', 'name' => 'Dirham', 'exchange_rate' => '1', 'face_value' => 1, 'is_base' => true, 'is_reporting' => true, 'active' => true]);
        $settings->save($org, $owner, 'currencies', ['code' => 'USD', 'name' => 'Dollar', 'exchange_rate' => '0.2722', 'face_value' => 1, 'is_base' => false, 'is_reporting' => false, 'active' => true]);
        $this->actingAs($owner)->postJson(route('reference-settings.currencies.rebase'), ['base_code' => 'USD', 'rates' => [['code' => 'USD', 'exchange_rate' => '1', 'face_value' => 1]]])->assertUnprocessable();
        $this->assertDatabaseHas('organization_currencies', ['organization_id' => $org->id, 'code' => 'AED', 'is_base' => true]);
        $this->postJson(route('reference-settings.currencies.rebase'), ['base_code' => 'USD', 'rates' => [['code' => 'USD', 'exchange_rate' => '1', 'face_value' => 1], ['code' => 'AED', 'exchange_rate' => '3.673', 'face_value' => 1]]])->assertOk();
        $this->assertDatabaseHas('organization_currencies', ['organization_id' => $org->id, 'code' => 'USD', 'is_base' => true]);
        $country = $settings->save($org, $owner, 'locations', ['name' => 'UAE', 'type' => 'country', 'active' => true]);
        $other = Organization::factory()->create();
        $otherOwner = $this->member($other);
        $this->actingAs($otherOwner)->postJson(route('reference-settings.store', 'locations'), ['name' => 'Foreign region', 'type' => 'region', 'parent_id' => $country, 'active' => true])->assertUnprocessable();
    }

    public function test_numbering_is_replay_safe_and_cannot_rewind(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $data = ['kind' => 'estimate', 'prefix' => 'EST', 'padding' => 4, 'next_number' => 1, 'include_year' => false, 'active' => true];
        $template = app(ManageReferenceConfiguration::class)->save($org, $owner, 'numbering', $data);
        $key = (string) Str::uuid();
        $numbers = app(AllocateReferenceNumber::class);
        $this->assertSame('EST-0001', $numbers->handle($org, 'estimate', $key));
        $this->assertSame('EST-0001', $numbers->handle($org, 'estimate', $key));
        $this->assertSame('EST-0002', $numbers->handle($org, 'estimate', (string) Str::uuid()));
        $this->actingAs($owner)->putJson(route('reference-settings.update', ['kind' => 'numbering', 'record' => $template]), $data)->assertUnprocessable();
    }

    public function test_accepted_estimate_creates_one_exact_draft_invoice_without_posting(): void
    {
        $org = Organization::factory()->create(['vat_enabled' => false]);
        $owner = $this->member($org);
        $pipeline = app(ManageReferenceWorkflows::class)->pipeline($org, $owner, ['kind' => 'estimate', 'name' => 'Quotes']);
        $input = ['pipeline_id' => $pipeline->id, 'title' => 'Estimate', 'assigned_to' => $owner->id, 'operation_key' => (string) Str::uuid(), 'details' => ['currency' => 'AED', 'lines' => [['description' => 'Work', 'quantity' => '1.25', 'unit_price' => '12.34']]]];
        $id = $this->actingAs($owner)->postJson(route('reference-workflows.store'), $input)->assertOk()->assertJsonPath('record.details.total', '15.43')->json('record.id');
        $this->postJson(route('reference-workflows.store'), $input)->assertOk()->assertJsonPath('record.id', $id);
        $success = $pipeline->stages()->where('type', 'success')->firstOrFail();
        $this->putJson(route('reference-workflows.stage', $id), ['stage_id' => $success->id, 'expected_version' => 1])->assertOk();
        $invoice = $this->postJson(route('reference-workflows.invoice', $id), ['expected_version' => 2, 'due_on' => '2026-11-01', 'accounting_treatment' => 'revenue'])->assertOk()->assertJsonPath('invoice.status', 'draft')->assertJsonPath('invoice.total', '15.43')->json('invoice.id');
        $this->postJson(route('reference-workflows.invoice', $id), ['expected_version' => 2, 'due_on' => '2026-11-01', 'accounting_treatment' => 'revenue'])->assertOk()->assertJsonPath('invoice.id', $invoice);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_recruitment_defaults_private_and_stage_requirements_preserve_history(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $member = $this->member($org, OrganizationRole::Member);
        $workflow = app(ManageReferenceWorkflows::class);
        $pipeline = $workflow->pipeline($org, $owner, ['kind' => 'recruitment', 'name' => 'Dubai recruitment']);
        $record = $workflow->save($org, $owner, ['pipeline_id' => $pipeline->id, 'title' => 'Applicant', 'assigned_to' => $owner->id, 'operation_key' => (string) Str::uuid(), 'details' => ['name' => 'Candidate', 'job_title' => 'Agent']]);
        $success = $pipeline->stages()->where('type', 'success')->firstOrFail();
        $workflow->stage($org, $owner, $pipeline->id, ['name' => 'Joined', 'type' => 'success', 'position' => 2, 'active' => true, 'color' => '#00ff00', 'required_fields' => ['joined_on']], $success->id);
        $this->actingAs($owner)->putJson(route('reference-workflows.stage', $record->id), ['stage_id' => $success->id, 'expected_version' => 1])->assertUnprocessable();
        $this->actingAs($member)->getJson(route('reference-workflows.show', $record->id))->assertNotFound();
        $this->getJson(route('reference-workflows.index'))->assertOk()->assertJsonPath('records.total', 0);
        $this->assertDatabaseCount('reference_workflow_histories', 1);
        $this->assertSame(1, $record->fresh()->version);
    }

    public function test_financial_stage_requirements_use_real_invoices_without_marking_them_paid(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = app(ManageDealPipelines::class)->save($org, $owner, ['name' => 'Payments']);
        $won = $pipeline->stages()->where('type', 'won')->firstOrFail();
        app(ManageDealPipelines::class)->stage($org, $owner, $pipeline->id, ['name' => 'Payment received', 'type' => 'won', 'color' => '#00ff00', 'active' => true, 'position' => 2, 'financial_requirement' => 'invoice_settled'], $won->id);
        $deal = app(ManageDeals::class)->create($org, $owner, ['title' => 'Payment tracker', 'category' => 'secondary', 'pipeline_id' => $pipeline->id]);
        $invoice = Invoice::create(['organization_id' => $org->id, 'reference' => 'TEST-INV', 'status' => 'posted', 'total' => '100.00', 'currency' => 'AED']);
        $this->actingAs($owner)->putJson(route('crm.deals.financial-links', $deal), ['kind' => 'invoice', 'record_id' => $invoice->id, 'expected_version' => 1])->assertOk();
        $this->putJson(route('crm.deals.stage', $deal), ['stage_id' => $won->id, 'expected_version' => 2])->assertUnprocessable();
        $this->assertSame('posted', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_local_sms_packets_are_validated_limited_and_never_delivered(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $profile = app(ManageReferenceConfiguration::class)->save($org, $owner, 'providers', ['name' => 'Local SMS', 'capability' => 'sms', 'active' => false, 'settings' => ['daily_limit' => 1]]);
        $data = ['profile_id' => $profile, 'operation_key' => (string) Str::uuid(), 'payload' => ['to' => '+971501234567', 'text' => 'Test preparation']];
        $id = $this->actingAs($owner)->postJson(route('reference-settings.provider-packets'), $data)->assertOk()->assertJsonPath('deliveryEnabled', false)->json('id');
        $this->postJson(route('reference-settings.provider-packets'), $data)->assertOk()->assertJsonPath('id', $id);
        $this->postJson(route('reference-settings.provider-packets'), [...$data, 'operation_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('organization_provider_packets', 1);
    }

    public function test_dynamic_recruitment_fields_and_invoice_source_guards_are_enforced(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $workflows = app(ManageReferenceWorkflows::class);
        $pipeline = $workflows->pipeline($org, $owner, ['kind' => 'recruitment', 'name' => 'Hiring', 'field_definitions' => [['key' => 'licence', 'name' => 'Licence number', 'type' => 'text', 'required' => false]]]);
        $record = $workflows->save($org, $owner, ['pipeline_id' => $pipeline->id, 'title' => 'Applicant', 'assigned_to' => $owner->id, 'operation_key' => (string) Str::uuid(), 'details' => ['name' => 'Applicant', 'job_title' => 'Agent']]);
        $success = $pipeline->stages()->where('type', 'success')->firstOrFail();
        $workflows->stage($org, $owner, $pipeline->id, ['name' => 'Approved', 'type' => 'success', 'active' => true, 'position' => 2, 'color' => '#00ff00', 'required_fields' => ['custom:licence']], $success->id);
        $this->actingAs($owner)->putJson(route('reference-workflows.stage', $record->id), ['stage_id' => $success->id, 'expected_version' => 1])->assertUnprocessable();
        $this->putJson(route('reference-workflows.update', $record->id), ['title' => 'Applicant', 'assigned_to' => $owner->id, 'expected_version' => 1, 'details' => ['name' => 'Applicant', 'job_title' => 'Agent', 'custom_fields' => ['licence' => 'RERA-123']]])->assertOk();
        $this->putJson(route('reference-workflows.stage', $record->id), ['stage_id' => $success->id, 'expected_version' => 2])->assertOk();
        $invoices = $workflows->pipeline($org, $owner, ['kind' => 'invoice', 'name' => 'Invoice progress']);
        $invoice = Invoice::create(['organization_id' => $org->id, 'reference' => 'GUARD', 'total' => '10.00', 'status' => 'draft']);
        $tracker = $workflows->save($org, $owner, ['pipeline_id' => $invoices->id, 'invoice_id' => $invoice->id, 'title' => 'Invoice', 'assigned_to' => $owner->id, 'operation_key' => (string) Str::uuid(), 'details' => []]);
        $paid = $invoices->stages()->where('type', 'success')->firstOrFail();
        $workflows->stage($org, $owner, $invoices->id, ['name' => 'Paid', 'type' => 'success', 'active' => true, 'position' => 2, 'color' => '#00ff00', 'source_statuses' => ['paid']], $paid->id);
        $this->putJson(route('reference-workflows.stage', $tracker->id), ['stage_id' => $paid->id, 'expected_version' => 1])->assertUnprocessable();
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_lead_field_mapping_copies_values_without_modifying_the_lead(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $fields = app(ManageCustomFields::class);
        $source = $fields->save($org, $owner, ['entity' => 'lead', 'key' => 'source_budget', 'name' => 'Budget', 'type' => 'currency']);
        $fields->save($org, $owner, ['entity' => 'deal', 'key' => 'deal_budget', 'name' => 'Budget', 'type' => 'currency']);
        $pipeline = app(ManageDealPipelines::class)->save($org, $owner, ['name' => 'Mapped', 'lead_field_mapping' => ['deal_budget' => 'source_budget']]);
        $leads = app(ManageLeadPipeline::class);
        $lead = $leads->create($org, $owner, ['first_name' => 'Client', 'last_name' => 'Example']);
        CustomFieldValue::create(['organization_id' => $org->id, 'lead_id' => $lead->id, 'field_id' => $source->id, 'value' => '123.45']);
        $won = Pipeline::where('organization_id', $org->id)->sole()->stages()->where('type', 'won')->firstOrFail();
        $leads->move($org, $owner, $lead, ['stage_id' => $won->id, 'expected_stage_id' => $lead->current_stage_id]);
        $deal = app(ManageDeals::class)->create($org, $owner, ['lead_id' => $lead->id, 'title' => 'Mapped deal', 'category' => 'secondary', 'pipeline_id' => $pipeline->id], $lead);
        $this->assertSame('123.45', RecordFieldValue::where('record_id', $deal->id)->where('entity', 'deal')->sole()->value);
        $this->assertSame('123.45', CustomFieldValue::sole()->value);
    }

    public function test_zero_delay_working_hours_rule_waits_for_the_next_open_window(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(14, 0));
        $org = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $owner = $this->member($org);
        app(ManageWorkingCalendar::class)->save($org, $owner, ['expected_version' => 0, 'working_days' => [1 => ['start' => '09:00', 'end' => '17:00']], 'holidays' => []]);
        $this->assertSame('2026-10-12 09:00', app(ManageWorkingCalendar::class)->deadline($org, 0)->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
        $this->travelBack();
    }

    public function test_document_tracking_respects_restricted_document_access_and_foreign_packets(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $manager = $this->member($org, OrganizationRole::Manager);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Private property', 'type' => 'residential']);
        $document = $property->documents()->create(['organization_id' => $org->id, 'uploaded_by' => $owner->id, 'name' => 'Evidence', 'path' => 'private/evidence.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'version_number' => 1, 'content_hash' => str_repeat('a', 64)]);
        $pipeline = app(ManageReferenceWorkflows::class)->pipeline($org, $owner, ['kind' => 'document', 'name' => 'Documents', 'read_roles' => ['owner', 'manager'], 'edit_roles' => ['owner', 'manager']]);
        $record = app(ManageReferenceWorkflows::class)->save($org, $owner, ['pipeline_id' => $pipeline->id, 'title' => 'Evidence', 'document_id' => $document->id, 'assigned_to' => $owner->id, 'operation_key' => (string) Str::uuid(), 'details' => []]);
        app(ManageCrmSettings::class)->section($org, $owner, ['principal_type' => 'user', 'principal_id' => (string) $manager->id, 'permission' => 'documents.view', 'enabled' => false]);
        $this->actingAs($manager)->getJson(route('reference-workflows.index'))->assertOk()->assertJsonPath('records.total', 0);
        $this->getJson(route('reference-workflows.show', $record->id))->assertForbidden();
        $this->get(route('inventory.properties.show', $property))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('documents', 0));
        $other = Organization::factory()->create();
        $otherOwner = $this->member($other);
        $profile = app(ManageReferenceConfiguration::class)->save($other, $otherOwner, 'providers', ['name' => 'Local signature', 'capability' => 'signature', 'active' => false]);
        $this->actingAs($otherOwner)->postJson(route('reference-settings.provider-packets'), ['profile_id' => $profile, 'operation_key' => (string) Str::uuid(), 'payload' => ['document_id' => $document->id, 'version_number' => 1, 'sha256' => str_repeat('a', 64), 'signers' => ['signer@example.test']]])->assertNotFound();
        $this->assertDatabaseCount('organization_provider_packets', 0);
    }

    public function test_inventory_export_restrictions_intersect_the_existing_inventory_permission(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $manager = $this->member($org, OrganizationRole::Manager);
        $this->actingAs($manager)->get(route('reports.export', 'inventory'))->assertOk();
        app(ManageCrmSettings::class)->section($org, $owner, ['principal_type' => 'user', 'principal_id' => (string) $manager->id, 'permission' => 'inventory.export', 'enabled' => false]);
        $this->get(route('reports.export', 'inventory'))->assertForbidden();
        $this->assertTrue($manager->can('viewInventory', $org));
    }

    public function test_job_evidence_restrictions_cover_both_download_paths_and_uploads(): void
    {
        Storage::fake('local');
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $technician = $this->member($org, OrganizationRole::Member);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Property', 'type' => 'residential']);
        $job = MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'reference' => 'EVIDENCE-TEST', 'title' => 'Repair', 'assigned_to' => $technician->id, 'status' => 'open']);
        $document = $job->documents()->create(['organization_id' => $org->id, 'uploaded_by' => $owner->id, 'name' => 'Proof.pdf', 'path' => 'proof.pdf', 'mime_type' => 'application/pdf', 'size' => 9]);
        Storage::disk('local')->put('proof.pdf', '%PDF-1.4');
        $settings = app(ManageCrmSettings::class);
        foreach (['documents.view', 'documents.manage'] as $permission) {
            $settings->section($org, $owner, ['principal_type' => 'user', 'principal_id' => (string) $technician->id, 'permission' => $permission, 'enabled' => false]);
        }
        $this->actingAs($technician)->get(route('documents.download', $document))->assertForbidden();
        $this->get(route('maintenance.job-card.evidence.download', [$job, $document]))->assertForbidden();
        $this->post(route('maintenance.job-card.evidence', $job), ['file' => UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4 proof')])->assertForbidden();
        $this->assertCount(0, app(JobCardDetails::class)->for($org, $technician, $job)['job']->documents);
    }
}
