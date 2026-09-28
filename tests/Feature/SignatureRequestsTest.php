<?php

namespace Tests\Feature;

use App\Domain\Documents\Actions\ManageSignatureRequests;
use App\Domain\Documents\Models\SignatureRequest;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SignatureRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepared_requests_preserve_the_exact_version_hash_and_never_send_or_sign(): void
    {
        Storage::fake('local');
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Sample', 'type' => 'residential']);
        Storage::disk('local')->put('plan.pdf', "%PDF-1.4\noriginal");
        $document = $property->documents()->create(['organization_id' => $org->id, 'uploaded_by' => $owner->id, 'name' => 'Plan.pdf', 'path' => 'plan.pdf', 'mime_type' => 'application/pdf', 'size' => 20]);
        $input = ['document_id' => $document->id, 'signers' => ['sample@example.test'], 'reason' => 'Contract review', 'operation_key' => (string) Str::uuid()];
        $this->actingAs($owner)->post(route('signatures.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('signatures.store'), $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('signature_requests', 1);
        $request = SignatureRequest::sole();
        $this->assertSame('local_prepared', $request->status);
        $this->assertSame(hash('sha256', "%PDF-1.4\noriginal"), $request->content_hash);
        $this->get(route('signatures.packet', $request->id))->assertOk()->assertJsonPath('status', 'local_validated')->assertJsonPath('packet.payload.document_id', $document->id)->assertJsonMissingPath('signed_at');
        $document->update(['archived_at' => now()]);
        $this->get(route('signatures.packet', $request->id))->assertStatus(422);
        $document->update(['archived_at' => null]);
        Storage::disk('local')->put('plan.pdf', "%PDF-1.4\nchanged");
        $this->get(route('signatures.packet', $request->id))->assertStatus(422);
        $this->post(route('signatures.cancel', $request->id), ['reason' => 'New version required'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame('New version required', $request->fresh()->cancellation_reason);
        Storage::disk('local')->assertExists('plan.pdf');
    }

    public function test_nonowners_and_foreign_organizations_cannot_prepare_or_read_signature_requests(): void
    {
        Storage::fake('local');
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $foreign = User::factory()->create(['current_organization_id' => $other->id]);
        $org->users()->attach([$owner->id => ['role' => OrganizationRole::Owner->value], $manager->id => ['role' => OrganizationRole::Manager->value]]);
        $other->users()->attach($foreign, ['role' => OrganizationRole::Owner->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Private', 'type' => 'residential']);
        Storage::disk('local')->put('doc.pdf', "%PDF-1.4\ncontract");
        $document = $property->documents()->create(['organization_id' => $org->id, 'uploaded_by' => $owner->id, 'name' => 'Contract.pdf', 'path' => 'doc.pdf', 'mime_type' => 'application/pdf', 'size' => 20]);
        $input = ['signers' => ['sample@example.test'], 'reason' => 'Review', 'operation_key' => (string) Str::uuid()];
        $request = app(ManageSignatureRequests::class)->prepare($org, $owner, $document, $input);
        $this->actingAs($manager)->get(route('signatures.packet', $request->id))->assertForbidden();
        $this->post(route('signatures.store'), ['document_id' => $document->id, ...$input])->assertForbidden();
        $this->actingAs($foreign)->get(route('signatures.packet', $request->id))->assertNotFound();
        $this->post(route('signatures.cancel', $request->id), ['reason' => 'Not allowed'])->assertNotFound();
    }
}
