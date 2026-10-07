<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Workflows\Models\WorkflowPipeline;
use App\Domain\Workflows\Models\WorkflowRecord;
use App\Domain\Workflows\Models\WorkflowStage;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeadCommercialTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_offer_and_contract_records_are_versioned_scoped_and_statuses_are_configurable(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $owner->id, 'first_name' => 'Buyer', 'last_name' => 'One']);
        $otherOrg = Organization::factory()->create();
        $other = CrmLead::create(['organization_id' => $otherOrg->id, 'assigned_to' => $this->member($otherOrg, OrganizationRole::Owner)->id, 'first_name' => 'Buyer', 'last_name' => 'Two']);
        $base = "/crm/leads/{$lead->id}/commercial";
        $this->actingAs($owner);
        $this->getJson($base)->assertOk()->assertJsonPath('configuration.version', 0)->assertJsonPath('permissions.create', true);
        $id = $this->postJson($base, ['kind' => 'offer', 'title' => 'Unit 12 offer', 'status' => 'submitted', 'party_name' => 'Buyer One', 'amount' => '1500000.00', 'currency' => 'AED', 'submitted_on' => '2026-10-07'])->assertCreated()->assertJsonPath('record.version', 1)->assertJsonPath('record.submitted_on', '2026-10-07')->assertJsonPath('record.permissions.edit', true)->json('record.id');
        $this->getJson($base)->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.status', 'submitted');
        $this->getJson("/crm/leads/{$other->id}/commercial")->assertNotFound();
        $this->putJson("$base/$id", ['status' => 'accepted'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("$base/$id", ['expected_version' => 0, 'status' => 'accepted'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson("$base/$id", ['expected_version' => 1, 'status' => 'accepted'])->assertOk()->assertJsonPath('record.version', 2);
        $this->putJson("$base/$id", ['expected_version' => 2, 'kind' => 'contract'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson($base, ['kind' => 'contract', 'title' => 'Sale memorandum', 'status' => 'signed', 'signed_on' => '2026-10-07'])->assertCreated()->assertJsonPath('record.kind', 'contract');
        $choices = ['offer' => [['value' => 'submitted', 'label' => 'Submitted', 'active' => true], ['value' => 'accepted', 'label' => 'Accepted', 'active' => true], ['value' => 'countered', 'label' => 'Countered', 'active' => true]], 'contract' => [['value' => 'signed', 'label' => 'Signed', 'active' => true]]];
        $settings = '/crm/settings/lead-commercial';
        $this->putJson($settings, ['expected_version' => 0, 'statuses' => $choices])->assertOk()->assertJsonPath('configuration.version', 1);
        $this->putJson($settings, ['expected_version' => 0, 'statuses' => $choices])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->postJson($base, ['kind' => 'offer', 'title' => 'Counter', 'status' => 'countered'])->assertCreated();
        $this->postJson($base, ['kind' => 'offer', 'title' => 'Draft', 'status' => 'draft'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.lead_commercial_record.created']);
    }

    public function test_accounting_link_shows_only_authorized_existing_estimates_and_invoices(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $owner->id, 'first_name' => 'Buyer', 'last_name' => 'Account']);
        $pipeline = WorkflowPipeline::create(['organization_id' => $org->id, 'kind' => 'estimate', 'name' => 'Quotes', 'read_roles' => ['owner'], 'edit_roles' => ['owner']]);
        $stage = WorkflowStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Accepted', 'type' => 'success']);
        $invoice = Invoice::create(['organization_id' => $org->id, 'reference' => 'INV-ONE', 'status' => 'draft', 'subtotal' => '100.00', 'vat_amount' => '5.00', 'total' => '105.00', 'currency' => 'AED']);
        WorkflowRecord::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'assigned_to' => $owner->id, 'reference' => 'EST-ONE', 'title' => 'Quote', 'details' => ['currency' => 'AED', 'total' => '100.00'], 'invoice_id' => $invoice->id, 'lead_id' => $lead->id, 'stage_changed_at' => now(), 'operation_key' => (string) Str::uuid()]);
        $url = "/crm/leads/{$lead->id}/accounting-links";
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('permissions.view', true)->assertJsonPath('documents.0.kind', 'estimate')->assertJsonPath('documents.0.amount', '100.00')->assertJsonPath('documents.0.vat', null)->assertJsonPath('documents.1.kind', 'invoice')->assertJsonPath('documents.1.vat', '5.00')->assertJsonPath('documents.1.total', '105.00');
        $member = $this->member($org, OrganizationRole::Member);
        $lead->update(['assigned_to' => $member->id]);
        $this->actingAs($member)->getJson($url)->assertOk()->assertJsonPath('permissions.view', true)->assertJsonCount(0, 'documents');
        $lead->update(['assigned_to' => $owner->id]);
        $this->actingAs($member)->getJson($url)->assertNotFound();
    }
}
