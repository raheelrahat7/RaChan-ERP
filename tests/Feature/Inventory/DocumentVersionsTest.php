<?php

namespace Tests\Feature\Inventory;

use App\Domain\Documents\Actions\ManageDocumentVersions;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Document;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentVersionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->organization = Organization::factory()->create();
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Private', 'type' => 'residential']);
        $this->actingAs($this->manager)->post(route('inventory.properties.documents.store', $property), ['file' => $this->pdf('original')])->assertRedirect()->assertSessionHasNoErrors();
        $this->document = Document::sole();
    }

    private function pdf(string $text): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\n".$text);
    }

    public function test_versions_preserve_original_bytes_and_upload_retries_and_archive_history(): void
    {
        $input = ['file' => $this->pdf('second'), 'reason' => 'Updated plan', 'version_key' => (string) Str::uuid()];
        $this->post(route('documents.versions.store', $this->document), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('documents.versions.store', $this->document), [...$input, 'file' => $this->pdf('second')])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('documents.versions.store', $this->document), [...$input, 'file' => $this->pdf('different')])->assertSessionHasErrors('version_key');
        $this->assertDatabaseCount('documents', 2);
        $this->assertSame("%PDF-1.4\noriginal", Storage::disk('local')->get($this->document->path));
        $this->get(route('documents.versions', $this->document))->assertInertia(fn (Assert $page) => $page->has('versions', 2)->where('versions.0.version_number', 2)->where('canEdit', true));
        $this->post(route('documents.archive', $this->document), ['reason' => 'Superseded'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('documents.versions.store', $this->document), ['file' => $this->pdf('third'), 'reason' => 'New plan', 'version_key' => (string) Str::uuid()])->assertStatus(422);
        $this->get(route('documents.download', $this->document))->assertOk()->assertDownload('plan.pdf');
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->post(route('documents.restore', $this->document), ['reason' => 'Review reopened'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->document->fresh()->archived_at);
    }

    public function test_previews_check_actual_content_and_scope_and_emit_restrictive_headers(): void
    {
        $this->get(route('documents.preview', $this->document))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
        Storage::disk('local')->put($this->document->path, '<html><script>alert(1)</script></html>');
        $this->document->update(['mime_type' => 'image/png']);
        $this->get(route('documents.preview', $this->document))->assertStatus(415);
        $other = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($user, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($user)->get(route('documents.versions', $this->document))->assertNotFound();
        $this->post(route('documents.archive', $this->document), ['reason' => 'Unauthorized'])->assertNotFound();
    }

    public function test_closed_job_evidence_cannot_be_versioned_or_archived_through_generic_routes(): void
    {
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Job property', 'type' => 'residential']);
        $job = MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $property->id, 'reference' => 'JOB', 'title' => 'Closed', 'assigned_to' => $this->manager->id, 'status' => 'completed']);
        Storage::disk('local')->put('evidence.pdf', "%PDF-1.4\nproof");
        $document = $job->documents()->create(['organization_id' => $this->organization->id, 'uploaded_by' => $this->manager->id, 'name' => 'Evidence.pdf', 'path' => 'evidence.pdf', 'mime_type' => 'application/pdf', 'size' => 20]);
        $this->post(route('documents.versions.store', $document), ['file' => $this->pdf('changed'), 'reason' => 'Change', 'version_key' => (string) Str::uuid()])->assertStatus(422);
        $this->post(route('documents.archive', $document), ['reason' => 'Hide proof'])->assertStatus(422);
        $this->get(route('documents.versions', $document))->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
        $this->get(route('documents.download', $document))->assertOk();
    }

    public function test_audit_failure_removes_only_the_failed_new_file(): void
    {
        $audit = \Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(ManageDocumentVersions::class)->add($this->organization, $this->manager, $this->document, $this->pdf('new'), 'Revision', (string) Str::uuid());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Storage::disk('local')->assertExists($this->document->path);
    }
}
