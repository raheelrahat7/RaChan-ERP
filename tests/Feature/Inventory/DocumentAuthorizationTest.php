<?php

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_member_can_download_an_existing_private_document_but_not_a_missing_file(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Private', 'type' => 'residential']);

        $this->actingAs($manager)->post(route('inventory.properties.documents.store', $property), [
            'file' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
        $document = Document::sole();

        $this->get(route('documents.download', $document))
            ->assertOk()
            ->assertDownload('plan.pdf');

        Storage::disk('local')->delete($document->path);
        $this->get(route('documents.download', $document))->assertNotFound();
    }

    public function test_a_manager_cannot_upload_to_or_download_from_another_tenant_property(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $otherOrganization->id, 'name' => 'Private', 'type' => 'residential']);
        $document = Document::create(['organization_id' => $otherOrganization->id, 'documentable_type' => Property::class, 'documentable_id' => $property->id, 'name' => 'private.pdf', 'path' => 'private.pdf', 'mime_type' => 'application/pdf', 'size' => 1]);

        $this->actingAs($manager)->post(route('inventory.properties.documents.store', $property), ['file' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')])->assertNotFound();
        $this->actingAs($manager)->get(route('documents.download', $document))->assertNotFound();
    }
}
