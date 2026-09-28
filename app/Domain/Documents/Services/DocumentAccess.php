<?php

namespace App\Domain\Documents\Services;

use App\Domain\Leasing\Models\HandoverInspectionItem;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\Document;
use App\Models\HandoverChecklist;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DocumentAccess
{
    public function __construct(private JobCardAccess $jobs) {}

    public function authorize(Organization $org, User $actor, Document $document, bool $write = false, bool $lock = false): void
    {
        abort_unless($document->organization_id === $org->id, 404);
        $type = $document->documentable_type;
        if ($type === (new MaintenanceRequest)->getMorphClass()) {
            $query = MaintenanceRequest::where('organization_id', $org->id);
            if ($lock) {
                $query->lockForUpdate();
            } $job = $query->findOrFail($document->documentable_id);
            $this->jobs->authorizeView($org, $actor, $job);
            if ($write) {
                abort_if(in_array($job->status, ['completed', 'cancelled'], true) || $job->submitted_at !== null, 422, __('Submitted or closed job evidence is read-only.'));
            }
        } elseif ($type === (new HandoverInspectionItem)->getMorphClass()) {
            Gate::forUser($actor)->authorize($write ? 'manageTransactions' : 'viewTransactions', $org);
            $item = HandoverInspectionItem::where('organization_id', $org->id)->findOrFail($document->documentable_id);
            $query = HandoverChecklist::where('organization_id', $org->id);
            if ($lock) {
                $query->lockForUpdate();
            } $handover = $query->findOrFail($item->handover_checklist_id);
            if ($write) {
                abort_unless($handover->status === 'planned', 422, __('Completed handover evidence is read-only.'));
            }
        } elseif ($type === (new Property)->getMorphClass() || $type === (new Unit)->getMorphClass()) {
            Gate::forUser($actor)->authorize($write ? 'manageInventory' : 'viewInventory', $org);
            $query = $type === (new Property)->getMorphClass() ? Property::where('organization_id', $org->id) : Unit::where('organization_id', $org->id);
            if ($lock) {
                $query->lockForUpdate();
            } $query->findOrFail($document->documentable_id);
        } else {
            abort(404);
        }
    }

    public function mimes(Document $document): string
    {
        return $document->documentable_type === (new HandoverInspectionItem)->getMorphClass() ? 'jpg,jpeg,png,webp' : ($document->documentable_type === (new MaintenanceRequest)->getMorphClass() ? 'pdf,jpg,jpeg,png,webp' : 'pdf,jpg,jpeg,png,webp,doc,docx');
    }
}
