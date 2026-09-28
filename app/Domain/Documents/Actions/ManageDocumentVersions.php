<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageDocumentVersions
{
    public function __construct(private DocumentAccess $access, private RecordOrganizationAuditLog $audit) {}

    public function root(Organization $org, Document $document): Document
    {
        $root = Document::where('organization_id', $org->id)->findOrFail($document->root_document_id ?? $document->id);
        abort_unless($root->root_document_id === null && $root->documentable_type === $document->documentable_type && $root->documentable_id === $document->documentable_id, 404);

        return $root;
    }

    public function add(Organization $org, User $actor, Document $document, UploadedFile $file, string $reason, string $key): Document
    {
        $this->access->authorize($org, $actor, $document);
        Validator::make(['file' => $file, 'reason' => $reason, 'version_key' => $key], ['file' => ['required', 'file', 'max:10240', 'mimes:'.$this->access->mimes($document)], 'reason' => ['required', 'string', 'max:2000'], 'version_key' => ['required', 'uuid']])->validate();
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Explain this new version.')]);
        }
        $hash = hash_file('sha256', $file->getPathname());
        abort_if($hash === false, 500, __('The file could not be hashed.'));
        $path = null;
        try {
            return DB::transaction(function () use ($org, $actor, $document, $file, $reason, $key, $hash, &$path): Document {
                $this->access->authorize($org, $actor, $document, true, true);
                Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $root = $this->root($org, $document);
                $existing = Document::where('organization_id', $org->id)->where('version_key', $key)->first();
                if ($existing !== null) {
                    if ($existing->root_document_id !== $root->id || $existing->content_hash !== $hash || $existing->version_reason !== $reason || $existing->name !== $file->getClientOriginalName()) {
                        throw ValidationException::withMessages(['version_key' => __('This upload key already has different document details.')]);
                    }

                    return $existing;
                }
                abort_if($root->archived_at !== null, 422, __('Restore the document before adding another version.'));
                $number = (int) Document::where('organization_id', $org->id)->where(fn ($q) => $q->whereKey($root->id)->orWhere('root_document_id', $root->id))->max('version_number') + 1;
                $path = $file->store("organizations/{$org->id}/document-versions/{$root->id}", 'local');
                abort_if($path === false, 500, __('The version could not be stored.'));
                $version = Document::create(['organization_id' => $org->id, 'uploaded_by' => $actor->id, 'documentable_type' => $root->documentable_type, 'documentable_id' => $root->documentable_id, 'name' => $file->getClientOriginalName(), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'root_document_id' => $root->id, 'version_number' => $number, 'version_reason' => $reason, 'version_key' => $key, 'content_hash' => $hash]);
                $this->audit->handle($org, $actor, 'documents.version.created', $version, ['root_document_id' => $root->id, 'version' => $number, 'reason' => $reason]);

                return $version;
            });
        } catch (\Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            } throw $exception;
        }
    }

    public function archive(Organization $org, User $actor, Document $document, string $reason, bool $restore = false): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Record a document lifecycle reason.')]);
        }
        DB::transaction(function () use ($org, $actor, $document, $reason, $restore): void {
            $this->access->authorize($org, $actor, $document, true, true);
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $root = $this->root($org, $document);
            if (($root->archived_at === null) === $restore) {
                return;
            }
            $root->update(['archived_at' => $restore ? null : now(), 'archived_by' => $restore ? null : $actor->id, 'archive_reason' => $restore ? null : $reason]);
            $this->audit->handle($org, $actor, $restore ? 'documents.restored' : 'documents.archived', $root, ['reason' => $reason]);
        });
    }
}
