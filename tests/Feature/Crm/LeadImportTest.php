<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\LeadImportBatch;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadImportTest extends TestCase
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

    public function test_preview_mapping_and_commit_report_duplicates_and_invalid_rows(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Dealer Category', 'key' => 'dealer_category', 'type' => 'text'])->assertRedirect();
        $csv = "First,Last,Email,Dealer\nSara,Ali,sara@example.test,Direct\nSara,Ali,sara@example.test,Dealer\nBad,Email,not-an-email,Other\n";
        $this->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', $csv)])->assertRedirect();
        $batch = LeadImportBatch::sole();
        $this->assertCount(3, $batch->rows);
        $pipeline = $org->leads()->first()?->pipeline_id ?? Pipeline::where('organization_id', $org->id)->firstOrFail()->id;
        $this->post(route('crm.leads.import.commit', $batch), ['pipeline_id' => $pipeline, 'duplicate_mode' => 'skip', 'mapping' => ['First' => 'first_name', 'Last' => 'last_name', 'Email' => 'email', 'Dealer' => 'custom:dealer_category']])->assertRedirect();
        $batch->refresh();
        $this->assertEquals(['created' => 1, 'skipped_duplicates' => 1, 'failed' => 1], $batch->summary);
        $this->assertDatabaseHas('crm_custom_field_values', ['search_text' => 'Direct']);
        $this->assertCount(1, $batch->errors);
        $this->post(route('crm.leads.import.commit', $batch), ['pipeline_id' => $pipeline, 'duplicate_mode' => 'skip', 'mapping' => ['First' => 'first_name', 'Last' => 'last_name']])->assertRedirect();
        $this->assertDatabaseCount('crm_leads', 1);
    }

    public function test_other_organization_cannot_commit_a_private_preview(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner)->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', "First,Last\nSara,Ali\n")])->assertRedirect();
        $other = Organization::factory()->create();
        $outsider = $this->member($other, OrganizationRole::Owner);
        $this->actingAs($outsider)->post(route('crm.leads.import.commit', LeadImportBatch::sole()), ['pipeline_id' => 1, 'duplicate_mode' => 'skip', 'mapping' => ['First' => 'first_name', 'Last' => 'last_name']])->assertNotFound();
    }

    public function test_import_can_require_a_mapped_field_without_making_it_globally_required(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), [
            'name' => 'Dealer category', 'key' => 'dealer_category', 'type' => 'text', 'required' => false,
            'view_roles' => ['owner', 'administrator'], 'edit_roles' => ['owner', 'administrator'],
        ])->assertRedirect();
        $this->assertFalse(CustomField::sole()->required);
        $this->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', "First,Last,Dealer\nSara,Ali,\nAmina,Khan,Direct\n")])->assertRedirect();
        $batch = LeadImportBatch::sole();
        $pipeline = Pipeline::where('organization_id', $org->id)->firstOrFail();
        $this->post(route('crm.leads.import.commit', $batch), [
            'pipeline_id' => $pipeline->id, 'duplicate_mode' => 'allow',
            'mapping' => ['First' => 'first_name', 'Last' => 'last_name', 'Dealer' => 'custom:dealer_category'],
            'required_targets' => ['custom:dealer_category'],
        ])->assertRedirect();
        $summary = $batch->refresh()->summary;
        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, $summary['skipped_duplicates']);
        $this->assertSame(1, $summary['failed']);
        $this->assertDatabaseHas('crm_custom_field_values', ['search_text' => 'Direct']);
        $this->assertDatabaseCount('crm_leads', 1);
    }

    public function test_export_includes_only_selected_permitted_columns_and_escapes_formulas(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $viewer = $this->member($org, OrganizationRole::Viewer);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Dealer category', 'key' => 'dealer_category', 'type' => 'text'])->assertRedirect();
        $pipeline = Pipeline::where('organization_id', $org->id)->firstOrFail();
        $lead = CrmLead::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'current_stage_id' => $pipeline->stages()->firstOrFail()->id,
            'first_name' => '=SUM(1,1)', 'last_name' => 'Ali', 'email' => 'private@example.test', 'phone' => '5551234']);
        DB::table('crm_custom_field_values')->insert(['organization_id' => $org->id, 'lead_id' => $lead->id, 'field_id' => CustomField::sole()->id,
            'value' => json_encode('Wholesale'), 'search_text' => 'Wholesale', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->get(route('crm.leads.export.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('crm.leads.export.download', ['columns' => ['first_name', 'last_name']]))->assertOk()
            ->assertStreamedContent("\"First name\",\"Last name\"\n\"'=SUM(1,1)\",Ali\n");
        DB::table('crm_activities')->insert(['organization_id' => $org->id, 'subject_type' => CrmLead::class, 'subject_id' => $lead->id, 'type' => 'call', 'notes' => 'Discussed viewing', 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('crm.leads.export.download', ['columns' => ['email', 'phone', 'activity_notes']]))->assertOk()
            ->assertStreamedContent("Email,\"Phone number\",\"Activity notes (latest 20)\"\nprivate@example.test,5551234,\"Discussed viewing\"\n");
        $this->get(route('crm.leads.export.download', ['columns' => ['custom:dealer_category']]))->assertOk()
            ->assertStreamedContent("\"Dealer category\"\nWholesale\n");
        $this->get(route('crm.leads.export.download', ['columns' => ['custom:other_org_field']]))->assertSessionHasErrors('columns');
        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id, 'email' => 'private@example.test']);
    }

    public function test_only_owner_can_delegate_lead_and_activity_exports_and_delegate_is_visibility_scoped(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $other = $this->member($org, OrganizationRole::Member);
        $pipeline = Pipeline::where('organization_id', $org->id)->firstOrFail();
        $stage = $pipeline->stages()->firstOrFail();
        $ownLead = CrmLead::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'current_stage_id' => $stage->id, 'first_name' => 'Visible', 'last_name' => 'Lead', 'assigned_to' => $agent->id]);
        $foreignLead = CrmLead::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'current_stage_id' => $stage->id, 'first_name' => 'Hidden', 'last_name' => 'Lead', 'assigned_to' => $other->id]);
        DB::table('crm_activities')->insert([
            ['organization_id' => $org->id, 'subject_type' => CrmLead::class, 'subject_id' => $ownLead->id, 'type' => 'note', 'notes' => 'Own comment', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $org->id, 'subject_type' => CrmLead::class, 'subject_id' => $foreignLead->id, 'type' => 'note', 'notes' => 'Private comment', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAs($agent)->get(route('crm.leads.export.download', ['columns' => ['first_name']]))->assertForbidden();
        $this->get(route('crm.leads.export.activities'))->assertForbidden();
        $this->put(route('crm.leads.export.grants'), ['user_id' => $agent->id, 'allowed' => true])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.leads.export.grants'), ['user_id' => $agent->id, 'allowed' => true])->assertRedirect();
        $this->actingAs($agent)->get(route('crm.leads.export.download', ['columns' => ['first_name']]))->assertOk()->assertStreamedContent("\"First name\"\nVisible\n");
        $activityCsv = $this->get(route('crm.leads.export.activities'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Own comment', $activityCsv);
        $this->assertStringNotContainsString('Private comment', $activityCsv);
        $this->get(route('crm.leads.export.activities', ['lead_id' => $foreignLead->id]))->assertNotFound();
        $this->assertStringContainsString('Own comment', $this->get(route('crm.leads.export.activities', ['lead_id' => $ownLead->id]))->assertOk()->streamedContent());
        $this->assertStringContainsString('Private comment', $this->actingAs($owner)->get(route('crm.leads.export.activities', ['lead_id' => $foreignLead->id]))->assertOk()->streamedContent());
        $this->actingAs($owner)->put(route('crm.leads.export.grants'), ['user_id' => $agent->id, 'allowed' => false])->assertRedirect();
        $this->actingAs($agent)->get(route('crm.leads.export.activities'))->assertForbidden();
    }
}
