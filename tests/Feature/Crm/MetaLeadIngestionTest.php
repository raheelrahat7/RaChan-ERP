<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Jobs\ProcessMetaLead;
use App\Domain\Crm\Models\MetaImport;
use App\Domain\Crm\Models\MetaPage;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaLeadIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_webhook_queues_once_and_imports_partial_lead_with_form_routing(): void
    {
        config()->set('services.meta.app_secret', 'test-app-secret');
        config()->set('services.meta.verify_token', 'test-verify-token');
        config()->set('services.meta.graph_version', 'v23.0');
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $agent = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $org->users()->attach($agent, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($owner)->post(route('crm.assignment.meta-pages.store'), ['page_id' => '555', 'page_access_token' => 'page-token'])->assertRedirect();
        $page = MetaPage::where('page_id', '555')->sole();
        $this->assertNotSame('page-token', DB::table('crm_meta_pages')->where('id', $page->id)->value('page_access_token'));
        Http::fake(['https://graph.facebook.com/v23.0/555/subscribed_apps' => Http::response(['success' => true])]);
        $this->post(route('crm.assignment.meta-pages.subscribe', $page))->assertRedirect();
        $this->assertNotNull($page->fresh()->subscribed_at);
        $this->post(route('crm.assignment.quota'), ['user_id' => $agent->id, 'max_active_leads' => 5])->assertRedirect();
        $this->actingAs($agent)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $this->actingAs($owner)->post(route('crm.assignment.routes.store'), ['match_type' => 'meta_form_id', 'match_value' => '777', 'target_type' => 'members', 'member_ids' => [$agent->id], 'active' => true])->assertRedirect();
        $this->get(route('webhooks.meta.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'wrong', 'hub.challenge' => 'abc']))->assertForbidden();
        $this->get(route('webhooks.meta.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'test-verify-token', 'hub.challenge' => 'abc']))->assertOk()->assertSeeText('abc');
        $payload = json_encode(['object' => 'page', 'entry' => [['id' => '555', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '999', 'form_id' => '777', 'page_id' => '555']]]]]]);
        Bus::fake();
        $this->call('POST', route('webhooks.meta.receive'), [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], $payload)->assertForbidden();
        $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-app-secret');
        $this->call('POST', route('webhooks.meta.receive'), [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $payload)->assertOk();
        $this->call('POST', route('webhooks.meta.receive'), [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $payload)->assertOk();
        $this->assertDatabaseCount('crm_meta_imports', 1);
        Bus::assertDispatchedTimes(ProcessMetaLead::class, 1);
        Http::fake([
            'https://graph.facebook.com/v23.0/999*' => Http::response(['id' => '999', 'form_id' => '777', 'campaign_name' => 'Summer', 'field_data' => []]),
            'https://graph.facebook.com/v23.0/777*' => Http::response(['id' => '777', 'name' => 'Palm campaign form']),
        ]);
        $import = MetaImport::sole();
        (new ProcessMetaLead($import->id))->handle(app(ManageLeadPipeline::class));
        $lead = CrmLead::where('meta_lead_id', '999')->sole();
        $this->assertSame('Meta', $lead->first_name);
        $this->assertSame('Lead 999', $lead->last_name);
        $this->assertSame('Palm campaign form', $lead->meta_form_name);
        $this->assertSame($agent->id, $lead->assigned_to);
        $this->assertSame('processed', $import->fresh()->status);
        (new ProcessMetaLead($import->id))->handle(app(ManageLeadPipeline::class));
        $this->assertDatabaseCount('crm_leads', 1);
    }

    public function test_organizations_connect_multiple_pages_with_private_callbacks_and_department_routing(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $otherOwner = User::factory()->create(['current_organization_id' => $other->id]);
        $agent = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $org->users()->attach($agent, ['role' => OrganizationRole::Member->value]);
        $other->users()->attach($otherOwner, ['role' => OrganizationRole::Owner->value]);
        $department = DB::table('crm_departments')->insertGetId(['organization_id' => $org->id, 'name' => 'Sales', 'active' => true]);
        $sub = DB::table('crm_subdepartments')->insertGetId(['organization_id' => $org->id, 'department_id' => $department, 'name' => 'Direct', 'active' => true]);
        $team = DB::table('crm_teams')->insertGetId(['organization_id' => $org->id, 'subdepartment_id' => $sub, 'name' => 'North', 'active' => true]);
        DB::table('crm_team_memberships')->insert(['organization_id' => $org->id, 'user_id' => $agent->id, 'team_id' => $team]);
        $this->actingAs($owner)->post(route('crm.assignment.quota'), ['user_id' => $agent->id, 'max_active_leads' => 5])->assertRedirect();
        $this->actingAs($agent)->post(route('crm.assignment.check-in'), ['available' => true])->assertRedirect();
        $settings = ['page_access_token' => 'page-token', 'app_secret' => 'private-secret', 'verify_token' => 'private-verify', 'graph_version' => 'v23.0', 'department_id' => $department];
        $this->actingAs($owner)->post(route('crm.assignment.meta-pages.store'), ['page_id' => '555', ...$settings])->assertRedirect();
        $this->post(route('crm.assignment.meta-pages.store'), ['page_id' => '558', 'page_access_token' => 'only-token'])->assertSessionHasErrors('meta');
        $this->post(route('crm.assignment.meta-pages.store'), ['page_id' => '556', ...$settings])->assertRedirect();
        $page = MetaPage::where('page_id', '555')->sole();
        $this->assertNotSame('private-secret', DB::table('crm_meta_pages')->where('id', $page->id)->value('app_secret'));
        $this->assertNotSame('private-verify', DB::table('crm_meta_pages')->where('id', $page->id)->value('verify_token'));
        $this->assertDatabaseCount('crm_meta_pages', 2);
        $this->actingAs($otherOwner)->post(route('crm.assignment.meta-pages.store'), ['page_id' => '555', ...$settings])->assertSessionHasErrors('page_id');
        $this->post(route('crm.assignment.meta-pages.store'), ['page_id' => '557', ...$settings, 'department_id' => null])->assertRedirect();
        $this->get(route('webhooks.meta.page.verify', [$page->webhook_key, 'hub.mode' => 'subscribe', 'hub.verify_token' => 'private-verify', 'hub.challenge' => 'ready']))->assertOk()->assertSeeText('ready');
        $payload = json_encode(['object' => 'page', 'entry' => [['id' => '555', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '999', 'page_id' => '555']]]]]]);
        Bus::fake();
        $wrong = 'sha256='.hash_hmac('sha256', $payload, 'wrong-secret');
        $valid = 'sha256='.hash_hmac('sha256', $payload, 'private-secret');
        $url = route('webhooks.meta.page.receive', $page->webhook_key);
        $this->call('POST', $url, [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $wrong], $payload)->assertForbidden();
        $this->call('POST', $url, [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $valid], $payload)->assertOk();
        $this->assertSame($org->id, MetaImport::sole()->organization_id);
        $secondPayload = json_encode(['object' => 'page', 'entry' => [['id' => '556', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '998', 'page_id' => '556']]]]]]);
        $secondSignature = 'sha256='.hash_hmac('sha256', $secondPayload, 'private-secret');
        $this->call('POST', $url, [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $secondSignature], $secondPayload)->assertOk();
        $this->assertDatabaseCount('crm_meta_imports', 2);
        $foreignPayload = json_encode(['object' => 'page', 'entry' => [['id' => '557', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '997', 'page_id' => '557']]]]]]);
        $foreignSignature = 'sha256='.hash_hmac('sha256', $foreignPayload, 'private-secret');
        $this->call('POST', $url, [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $foreignSignature], $foreignPayload)->assertOk();
        $this->assertDatabaseCount('crm_meta_imports', 2);
        Http::fake(['https://graph.facebook.com/v23.0/999*' => Http::response(['id' => '999', 'field_data' => []])]);
        (new ProcessMetaLead(MetaImport::where('leadgen_id', '999')->sole()->id))->handle(app(ManageLeadPipeline::class));
        $lead = CrmLead::where('meta_lead_id', '999')->sole();
        $this->assertSame($org->id, $lead->organization_id);
        $this->assertSame($agent->id, $lead->assigned_to);
        $this->assertSame('555', $lead->meta_page_id);
        Http::fake(['https://graph.facebook.com/v23.0/555/subscribed_apps' => Http::response(['success' => true])]);
        $this->actingAs($owner)->post(route('crm.assignment.meta-pages.subscribe', $page))->assertRedirect();
        $this->assertNotNull($page->fresh()->subscribed_at);
        $this->actingAs($owner)->post(route('crm.assignment.meta-pages.store'), ['page_id' => '555', 'department_id' => null, 'graph_version' => 'v24.0'])->assertRedirect();
        $this->assertNotNull($page->fresh()->subscribed_at);
        $this->assertSame('page-token', $page->fresh()->page_access_token);
        $this->assertSame('private-secret', $page->fresh()->app_secret);
        $this->assertSame('v24.0', $page->fresh()->graph_version);
        $this->assertDatabaseMissing('crm_assignment_routes', ['organization_id' => $org->id, 'match_type' => 'meta_page_id', 'match_value' => '555']);
        $this->post(route('crm.assignment.meta-pages.store'), ['page_id' => '555', 'verify_token' => 'rotated-verify'])->assertRedirect();
        $this->assertNull($page->fresh()->subscribed_at);
    }

    public function test_failed_import_retry_is_queued_once_until_final_failure(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($owner)->post(route('crm.assignment.meta-pages.store'), [
            'page_id' => '600', 'page_access_token' => 'page-token', 'app_secret' => 'app-secret',
            'verify_token' => 'verify-token', 'graph_version' => 'v23.0',
        ])->assertRedirect();
        $page = MetaPage::where('page_id', '600')->sole();
        $import = MetaImport::create(['organization_id' => $org->id, 'meta_page_id' => $page->id, 'leadgen_id' => '601', 'status' => 'failed', 'error' => 'Temporary error']);
        Bus::fake();
        $this->post(route('crm.assignment.meta-imports.retry', $import))->assertRedirect();
        $this->post(route('crm.assignment.meta-imports.retry', $import))->assertRedirect();
        Bus::assertDispatchedTimes(ProcessMetaLead::class, 1);
        $this->assertSame('pending', $import->fresh()->status);
        $this->assertNull($import->fresh()->error);
        (new ProcessMetaLead($import->id))->failed(new \RuntimeException('Graph unavailable'));
        $this->assertSame('failed', $import->fresh()->status);
        $this->post(route('crm.assignment.meta-imports.retry', $import))->assertRedirect();
        Bus::assertDispatchedTimes(ProcessMetaLead::class, 2);
    }
}
