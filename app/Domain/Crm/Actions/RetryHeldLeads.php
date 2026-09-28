<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\AssignmentHold;
use App\Domain\Crm\Services\AssignLeadOnStageEntry;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class RetryHeldLeads
{
    public function __construct(private AssignLeadOnStageEntry $assign) {}

    public function handle(Organization $org): int
    {
        $assigned = 0;
        $ids = AssignmentHold::where('organization_id', $org->id)->whereNull('resolved_at')->orderBy('created_at')->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $assigned += DB::transaction(function () use ($org, $id): int {
                Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $hold = AssignmentHold::where('organization_id', $org->id)->whereNull('resolved_at')->find((int) $id);
                if (! $hold) {
                    return 0;
                }
                $lead = CrmLead::where('organization_id', $org->id)->lockForUpdate()->find($hold->lead_id);
                if (! $lead || $lead->assigned_to !== null || $lead->converted_at !== null || in_array($lead->stage->type, ['won', 'lost'], true)) {
                    $hold->update(['resolved_at' => now()]);

                    return 0;
                }
                $this->assign->handle($org, $lead, $lead->stage);

                return $lead->assigned_to !== null ? 1 : 0;
            });
        }

        return $assigned;
    }
}
