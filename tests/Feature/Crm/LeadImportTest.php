<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\LeadImportBatch;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
