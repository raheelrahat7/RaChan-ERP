<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
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
}
