<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Models\HandoverInspectionItem;
use App\Models\HandoverChecklist;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordHandoverInspection
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array{area: string, condition: string, notes?: string|null} $details */
    public function handle(Organization $organization, User $actor, HandoverChecklist $handover, array $details): HandoverInspectionItem
    {
        return DB::transaction(function () use ($organization, $actor, $handover, $details): HandoverInspectionItem {
            $locked = HandoverChecklist::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($handover->id);
            abort_unless($locked->status === 'planned', 422, 'Completed handovers cannot be edited.');

            $item = $locked->inspectionItems()->create([
                'organization_id' => $organization->id,
                'recorded_by' => $actor->id,
                ...$details,
            ]);
            $this->audit->handle($organization, $actor, 'transactions.handover.inspection_recorded', $item, ['handover_id' => $locked->id]);

            return $item;
        });
    }
}
