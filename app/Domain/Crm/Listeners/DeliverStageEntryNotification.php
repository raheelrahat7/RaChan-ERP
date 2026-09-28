<?php

namespace App\Domain\Crm\Listeners;

use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliverStageEntryNotification
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function handle(LeadStageChanged $event): void
    {
        $history = $event->history;
        $snapshot = $history->snapshot ?? [];
        if (! ($snapshot['notify_assignee_on_entry'] ?? false)) {
            return;
        }
        $recipientId = (int) ($snapshot['assigned_to'] ?? 0);
        if ($recipientId < 1) {
            return;
        }

        DB::transaction(function () use ($history, $snapshot, $recipientId): void {
            $lead = CrmLead::where('organization_id', $history->organization_id)->whereKey($history->lead_id)->where('assigned_to', $recipientId)->lockForUpdate()->first();
            if (! $lead) {
                return;
            }
            $org = Organization::findOrFail($history->organization_id);
            if (! $org->users()->where('users.id', $recipientId)->exists()) {
                return;
            }
            $eventKey = 'crm_stage_entry:'.$history->id;
            $created = OrganizationNotification::query()->insertOrIgnore([
                'organization_id' => $org->id,
                'user_id' => $recipientId,
                'category' => 'crm_stage_entry',
                'event_key' => $eventKey,
                'title' => Str::limit('Lead '.$lead->first_name.' '.$lead->last_name.' entered '.($snapshot['to'] ?? 'a CRM stage'), 255),
                'count' => 1,
                'href' => '/crm/leads',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($created) {
                $notification = OrganizationNotification::where('organization_id', $org->id)->where('user_id', $recipientId)->where('event_key', $eventKey)->firstOrFail();
                $this->audit->handle($org, null, 'crm.stage_notification.created', $notification, ['lead_id' => $lead->id, 'stage_history_id' => $history->id, 'recipient_id' => $recipientId]);
            }
        });
    }
}
