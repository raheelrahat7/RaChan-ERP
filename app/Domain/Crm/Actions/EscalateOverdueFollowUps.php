<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EscalateOverdueFollowUps
{
    public function __construct(private LeadVisibility $visibility, private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $org, ?Carbon $at = null): int
    {
        if (! $org->crm_follow_up_escalation_enabled) {
            return 0;
        }
        $localNow = ($at ?? now())->copy()->setTimezone($org->timezone);
        if ($localNow->hour !== 8) {
            return 0;
        }
        $threshold = $localNow->copy()->subDay()->utc();
        $members = $org->users()->get(['users.id']);
        $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();
        $recipients = $members->filter(fn ($member) => in_array($member->pivot->getAttribute('role'), ['owner', 'administrator', 'manager'], true));
        $created = 0;
        $activities = CrmActivity::where('organization_id', $org->id)->whereNull('completed_at')->whereNotNull('due_at')
            ->where('due_at', '<=', $threshold)
            ->whereHasMorph('subject', [CrmLead::class], fn ($query) => $query->where('organization_id', $org->id)->whereNull('converted_at')->whereNotNull('assigned_to'))
            ->with('subject')->get();
        foreach ($activities as $activity) {
            $lead = $activity->subject;
            if (! $lead instanceof CrmLead || ! in_array((int) $lead->assigned_to, $memberIds, true)) {
                continue;
            }
            $milestone = collect([14, 7, 1])->first(fn (int $days) => $activity->due_at->lessThanOrEqualTo($localNow->copy()->subDays($days)->utc()));
            if ($milestone === null) {
                continue;
            }
            foreach ($recipients as $recipient) {
                if ($recipient->id === $lead->assigned_to || ($recipient->pivot->getAttribute('role') === 'manager' && ! $this->visibility->canSeeLead($org, $recipient, $lead->assigned_to))) {
                    continue;
                }
                $eventKey = 'crm_follow_up_escalation:'.$activity->id.':'.$milestone;
                $inserted = OrganizationNotification::query()->insertOrIgnore([
                    'organization_id' => $org->id,
                    'user_id' => $recipient->id,
                    'category' => 'crm_follow_up_escalation',
                    'event_key' => $eventKey,
                    'title' => Str::limit('Follow-up overdue by '.match ($milestone) {
                        1 => '1 day', 7 => '1 week', 14 => '2 weeks',
                        default => throw new \LogicException('Unexpected follow-up escalation milestone.'),
                    }.' for '.$lead->first_name.' '.$lead->last_name, 255),
                    'count' => 1,
                    'href' => '/crm/leads',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted) {
                    $notification = OrganizationNotification::where('organization_id', $org->id)->where('user_id', $recipient->id)->where('event_key', $eventKey)->firstOrFail();
                    $this->audit->handle($org, null, 'crm.follow_up.escalation_sent', $notification, ['activity_id' => $activity->id, 'lead_id' => $lead->id, 'recipient_id' => $recipient->id, 'overdue_days' => $milestone]);
                    $created++;
                }
            }
        }

        return $created;
    }
}
