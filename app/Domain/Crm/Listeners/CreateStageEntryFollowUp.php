<?php

namespace App\Domain\Crm\Listeners;

use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class CreateStageEntryFollowUp
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function handle(LeadStageChanged $event): void
    {
        $history = $event->history;
        $snapshot = $history->snapshot ?? [];
        $days = $snapshot['follow_up_due_days'] ?? null;
        $recipientId = (int) ($snapshot['assigned_to'] ?? 0);
        if ($days === null || ! is_numeric($days) || (int) $days < 0 || (int) $days > 365 || $recipientId < 1) {
            return;
        }

        DB::transaction(function () use ($history, $snapshot, $days, $recipientId): void {
            $lead = CrmLead::where('organization_id', $history->organization_id)
                ->whereKey($history->lead_id)
                ->where('assigned_to', $recipientId)
                ->whereNull('converted_at')
                ->lockForUpdate()->first();
            if (! $lead) {
                return;
            }
            $org = Organization::findOrFail($history->organization_id);
            if (! $org->users()->where('users.id', $recipientId)->exists()) {
                return;
            }
            $created = CrmActivity::query()->insertOrIgnore([
                'organization_id' => $org->id,
                'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->id,
                'stage_history_id' => $history->id,
                'created_by' => null,
                'type' => 'task',
                'notes' => 'Follow up after entering '.($snapshot['to'] ?? 'CRM stage').'.',
                'due_at' => $history->changed_at->addDays((int) $days),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($created) {
                $task = CrmActivity::where('stage_history_id', $history->id)->firstOrFail();
                $this->audit->handle($org, null, 'crm.stage_follow_up.created', $task, ['lead_id' => $lead->id, 'stage_history_id' => $history->id, 'recipient_id' => $recipientId, 'due_days' => (int) $days]);
            }
        });
    }
}
