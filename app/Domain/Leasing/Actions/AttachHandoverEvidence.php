<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Models\HandoverInspectionItem;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AttachHandoverEvidence
{
    public function __construct(private readonly RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $organization, User $actor, HandoverInspectionItem $item, UploadedFile $file): Document
    {
        $path = $file->store("organizations/{$organization->id}/handover-inspections/{$item->id}", 'local');
        abort_if($path === false, 500, 'The inspection photo could not be stored.');

        try {
            return DB::transaction(function () use ($organization, $actor, $item, $file, $path): Document {
                $locked = HandoverInspectionItem::where('organization_id', $organization->id)
                    ->with('handover:id,status')->lockForUpdate()->findOrFail($item->id);
                abort_unless($locked->handover?->status === 'planned', 422, 'Evidence cannot be added after handover completion.');
                $document = $locked->documents()->create([
                    'organization_id' => $organization->id,
                    'uploaded_by' => $actor->id,
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
                $this->audit->handle($organization, $actor, 'transactions.handover.evidence_attached', $document, ['inspection_item_id' => $locked->id]);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }
}
