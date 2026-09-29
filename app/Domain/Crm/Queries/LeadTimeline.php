<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\AuditLog;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;

class LeadTimeline
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @return list<array<string,mixed>> */
    public function for(Organization $org, User $actor, CrmLead $lead): array
    {
        abort_unless($lead->organization_id === $org->id, 404);
        if (! AuditLog::where('organization_id', $org->id)->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)->where('actor_id', $actor->id)->where('event', 'crm.lead.viewed')
            ->where('created_at', '>=', now()->subMinutes(15))->exists()) {
            $this->audit->handle($org, $actor, 'crm.lead.viewed', $lead);
        }
        $activityIds = CrmActivity::where('organization_id', $org->id)->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->pluck('id');
        $entries = AuditLog::where('organization_id', $org->id)
            ->where(function ($query) use ($lead, $activityIds): void {
                $query->where(fn ($direct) => $direct->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id))
                    ->orWhere(fn ($activity) => $activity->where('subject_type', CrmActivity::class)->whereIn('subject_id', $activityIds));
            })->with('actor:id,name')->latest()->limit(100)->get();

        return array_values($entries->map(function (AuditLog $entry): array {
            $properties = $entry->properties ?? [];
            $detail = match ($entry->event) {
                'crm.lead.created' => 'Lead created',
                'crm.lead.imported' => 'Lead imported',
                'crm.lead.viewed' => 'Lead opened',
                'crm.lead.details_updated' => 'Changed: '.implode(', ', $properties['changed_fields'] ?? []),
                'crm.lead.custom_field_changed' => 'Custom field changed: '.($properties['field_key'] ?? ''),
                'crm.lead.assigned' => 'Assigned user #'.($properties['previous_assigned_to'] ?? 'none').' → #'.($properties['assigned_to'] ?? 'none'),
                'crm.lead.stage_changed' => ($properties['from'] ?? 'Initial stage').' → '.($properties['to'] ?? 'stage'),
                'crm.lead.transferred' => 'Transferred between pipelines',
                'crm.lead.converted' => 'Converted to contact',
                'crm.activity.created' => 'Activity added',
                'crm.follow_up.completed' => 'Follow-up completed',
                'crm.stage_follow_up.created' => 'Automatic follow-up created',
                default => str_replace(['crm.lead.', 'crm.'], '', $entry->event),
            };

            return ['id' => $entry->id, 'at' => $entry->created_at?->toIso8601String(), 'actor' => $entry->actor ? $entry->actor->name : 'System', 'event' => $entry->event, 'detail' => $detail];
        })->all());
    }
}
