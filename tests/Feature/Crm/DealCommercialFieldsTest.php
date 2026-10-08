<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealCommercialFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_status_and_commission_fields_are_versioned_and_organization_configurable(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($owner);
        $pipeline = $this->postJson('/crm/deals/pipelines', ['name' => 'Sales'])->assertSuccessful()->json('pipeline.id');
        $this->getJson('/crm/deals/configuration')->assertOk()->assertJsonPath('dealStatusOptions.0.code', 'submitted');

        $deal = $this->postJson('/crm/deals', ['title' => 'Villa 1', 'category' => 'secondary', 'pipeline_id' => $pipeline, 'deal_status' => 'submitted', 'scenario' => 'co_broker', 'gross_commission' => '15000.00', 'co_broker_share' => 30, 'agent_share' => 40])->assertCreated()->assertJsonPath('deal.gross_commission', '15000.00')->json('deal');
        $this->putJson('/crm/deals/'.$deal['id'], ['expected_version' => $deal['version'], 'deal_status' => 'approved'])->assertOk()->assertJsonPath('deal.deal_status', 'approved');
        $this->putJson('/crm/deals/'.$deal['id'], ['expected_version' => $deal['version'] + 1, 'deal_status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('deal_status');
        $this->putJson('/crm/deals/'.$deal['id'], ['expected_version' => $deal['version'] + 1, 'co_broker_share' => 70, 'agent_share' => 40])->assertUnprocessable()->assertJsonValidationErrors('agent_share');
        $this->putJson('/crm/deals/'.$deal['id'], ['expected_version' => $deal['version'], 'scenario' => 'direct'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $this->postJson('/crm/settings/options', ['list_key' => 'deal_statuses', 'code' => 'legal_review', 'name' => 'Legal Review', 'position' => 14, 'active' => true])->assertOk()->assertJsonPath('option.code', 'legal_review');
        $this->putJson('/crm/deals/'.$deal['id'], ['expected_version' => $deal['version'] + 1, 'deal_status' => 'legal_review'])->assertOk()->assertJsonPath('deal.deal_status', 'legal_review');
        $this->getJson('/crm/deals/'.$deal['id'])->assertOk()->assertJsonFragment(['code' => 'legal_review', 'name' => 'Legal Review', 'active' => true]);
    }
}
