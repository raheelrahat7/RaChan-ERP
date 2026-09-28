<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SendFollowUpReminders
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $org, ?Carbon $at = null): int
    {
        $days = $org->crm_follow_up_reminder_days;
        if ($days === null) {
            return 0;
        }
        $localNow = ($at ?? now())->copy()->setTimezone($org->timezone);
        if ($localNow->hour !== 8) {
            return 0;
        }
        $target = $localNow->copy()->addDays($days);
        $from = $target->copy()->startOfDay()->utc();
        $to = $target->copy()->endOfDay()->utc();
        $members = $org->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $created = 0;
        $activities = CrmActivity::where('organization_id', $org->id)->whereNull('completed_at')->whereNotNull('due_at')
            ->whereBetween('due_at', [$from, $to])
            ->whereHasMorph('subject', [CrmLead::class], fn ($query) => $query->where('organization_id', $org->id)->whereNull('converted_at')->whereNotNull('assigned_to'))
            ->with('subject')->get();
        foreach ($activities as $activity) {
            $lead = $activity->subject;
            if (! $lead instanceof CrmLead || ! in_array((int) $lead->assigned_to, $members, true)) {
                continue;
            }
            $eventKey = 'crm_follow_up_reminder:'.$activity->id.':'.$target->toDateString();
            $inserted = OrganizationNotification::query()->insertOrIgnore([
                'organization_id' => $org->id,
                'user_id' => $lead->assigned_to,
                'category' => 'crm_follow_up_reminder',
                'event_key' => $eventKey,
                'title' => Str::limit('Follow-up due in '.$days.' '.($days === 1 ? 'day' : 'days').' for '.$lead->first_name.' '.$lead->last_name, 255),
                'count' => 1,
                'href' => '/crm/leads',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted) {
                $notification = OrganizationNotification::where('organization_id', $org->id)->where('user_id', $lead->assigned_to)->where('event_key', $eventKey)->firstOrFail();
                $this->audit->handle($org, null, 'crm.follow_up.reminder_sent', $notification, ['activity_id' => $activity->id, 'lead_id' => $lead->id, 'recipient_id' => $lead->assigned_to, 'days_before' => $days]);
                $created++;
            }
        }

        return $created;
    }
}
