<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\SignatureRequest;
use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Integrations\Data\ProviderPacket;
use App\Domain\Integrations\Services\LocalOutboundProvider;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageSignatureRequests
{
    public function __construct(private DocumentAccess $access, private ManageDocumentVersions $versions, private LocalOutboundProvider $provider, private RecordOrganizationAuditLog $audit) {}

    private function owner(Organization $org, User $actor): void
    {
        abort_unless($actor->hasVerifiedEmail() && $actor->hasOrganizationRole($org, OrganizationRole::Owner), 403);
    }

    /** @param array<string,mixed> $input */
    public function prepare(Organization $org, User $actor, Document $document, array $input): SignatureRequest
    {
        $this->owner($org, $actor);
        $data = Validator::make($input, ['signers' => ['required', 'array', 'min:1', 'max:10'], 'signers.*' => ['required', 'email', 'distinct', 'max:255'], 'reason' => ['required', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid']])->validate();
        $signers = array_map(fn ($email): string => mb_strtolower(trim($email)), $data['signers']);
        if (count(array_unique($signers)) !== count($signers)) {
            throw ValidationException::withMessages(['signers' => 'Each signer must be different.']);
        }$reason = trim($data['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Explain the signature request.']);
        }

        return DB::transaction(function () use ($org, $actor, $document, $data, $signers, $reason): SignatureRequest {
            $this->access->authorize($org, $actor, $document, true, true);
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $document = Document::where('organization_id', $org->id)->findOrFail($document->id);
            $root = $this->versions->root($org, $document);
            abort_if($root->archived_at !== null, 422, 'Restore the document before preparing a signature request.');
            abort_unless(Storage::disk('local')->exists($document->path) && Storage::disk('local')->mimeType($document->path) === 'application/pdf', 422, 'Signature requests require an available PDF document.');
            $hash = hash('sha256', Storage::disk('local')->get($document->path));
            $number = (int) $document->version_number;
            $existing = SignatureRequest::where('organization_id', $org->id)->where('operation_key', $data['operation_key'])->first();
            if ($existing !== null) {
                if ($existing->document_id !== $document->id || $existing->content_hash !== $hash || $existing->signers !== $signers || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['operation_key' => 'This key already belongs to different request details.']);
                }

                return $existing;
            }
            $this->provider->validate(new ProviderPacket('signature', $org->id, $data['operation_key'], ['document_id' => $document->id, 'version_number' => $number, 'sha256' => $hash, 'signers' => $signers]));
            $request = SignatureRequest::create(['organization_id' => $org->id, 'document_id' => $document->id, 'requested_by' => $actor->id, 'operation_key' => $data['operation_key'], 'version_number' => $number, 'content_hash' => $hash, 'signers' => $signers, 'reason' => $reason, 'status' => 'local_prepared']);
            $this->audit->handle($org, $actor, 'documents.signature.local_prepared', $request, ['document_id' => $document->id, 'version_number' => $number, 'content_hash' => $hash, 'signer_count' => count($signers)]);

            return $request;
        });
    }

    public function cancel(Organization $org, User $actor, int $id, string $reason): void
    {
        $this->owner($org, $actor);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Explain the cancellation.']);
        }
        DB::transaction(function () use ($org, $actor, $id, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $request = SignatureRequest::where('organization_id', $org->id)->lockForUpdate()->findOrFail($id);
            $document = Document::where('organization_id', $org->id)->findOrFail($request->document_id);
            $this->access->authorize($org, $actor, $document);
            if ($request->status === 'cancelled') {
                return;
            }$request->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason]);
            $this->audit->handle($org, $actor, 'documents.signature.cancelled', $request, ['reason' => $reason]);
        });
    }

    /** @return array<string,mixed> */
    public function packet(Organization $org, User $actor, int $id): array
    {
        $this->owner($org, $actor);
        $request = SignatureRequest::where('organization_id', $org->id)->findOrFail($id);
        abort_unless($request->status === 'local_prepared', 422);
        $document = Document::where('organization_id', $org->id)->findOrFail($request->document_id);
        $this->access->authorize($org, $actor, $document);
        abort_if($this->versions->root($org, $document)->archived_at !== null, 422, 'Archived documents cannot be submitted for signing.');
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        abort_unless(hash('sha256', Storage::disk('local')->get($document->path)) === $request->content_hash, 422, 'The document bytes have changed. Prepare a new request.');

        return $this->provider->validate(new ProviderPacket('signature', $org->id, $request->operation_key, ['document_id' => $document->id, 'version_number' => $request->version_number, 'sha256' => $request->content_hash, 'signers' => $request->signers]));
    }
}
