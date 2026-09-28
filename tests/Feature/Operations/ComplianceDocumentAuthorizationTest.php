<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\ComplianceDocument;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComplianceDocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_compliance_document_download_requires_own_organization_and_an_existing_file(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $outsider = User::factory()->create(['current_organization_id' => $otherOrganization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $otherOrganization->users()->attach($outsider, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Private', 'type' => 'residential']);

        $this->actingAs($manager)->post(route('compliance-documents.store'), [
            'subject_type' => 'property',
            'subject_id' => $property->id,
            'category' => 'insurance',
            'file' => UploadedFile::fake()->create('policy.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
        $document = ComplianceDocument::sole();

        $this->get(route('compliance-documents.download', $document))->assertOk()->assertDownload('policy.pdf');
        $this->actingAs($outsider)->get(route('compliance-documents.download', $document))->assertNotFound();
        $this->post(route('compliance-documents.store'), [
            'subject_type' => 'property',
            'subject_id' => $property->id,
            'category' => 'insurance',
            'file' => UploadedFile::fake()->create('other.pdf', 10, 'application/pdf'),
        ])->assertNotFound();

        Storage::disk('local')->delete($document->path);
        $this->actingAs($manager)->get(route('compliance-documents.download', $document))->assertNotFound();
    }
}
