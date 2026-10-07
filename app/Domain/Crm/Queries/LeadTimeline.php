<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\AuditLog;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class LeadTimeline
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function for(Organization $org, User $actor, CrmLead $lead, array $filters = []): array
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        abort_unless($lead->organization_id === $org->id && app(LeadVisibility::class)->canSeeLead($org, $actor, $lead->assigned_to), 404);
        if (! AuditLog::where('organization_id', $org->id)->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)->where('actor_id', $actor->id)->where('event', 'crm.lead.viewed')
            ->where('created_at', '>=', now()->subMinutes(15))->exists()) {
            $this->audit->handle($org, $actor, 'crm.lead.viewed', $lead);
        }
        $entries = $this->query($org, $lead, $filters)->with('actor:id,name')->latest()->orderByDesc('id')
            ->offset((max(1, (int) ($filters['history_page'] ?? 1)) - 1) * 100)->limit(100)->get();

        return array_values($entries->map(function (AuditLog $entry): array {
            $properties = $entry->properties ?? [];
            $detail = match ($entry->event) {
                'crm.lead.created' => 'Lead created',
                'crm.lead.imported' => 'Lead imported',
                'crm.lead.viewed' => 'Lead opened',
                'crm.lead.details_updated' => 'Changed: '.implode(', ', $properties['changed_fields'] ?? []),
                'crm.lead.requirements_updated' => 'Requirements changed: '.implode(', ', $properties['changed_fields'] ?? []),
                'crm.lead.custom_field_changed' => 'Custom field changed: '.($properties['field_key'] ?? ''),
                'crm.lead.assigned' => 'Assigned user #'.($properties['previous_assigned_to'] ?? 'none').' → #'.($properties['assigned_to'] ?? 'none'),
                'crm.lead.stage_changed' => ($properties['from'] ?? 'Initial stage').' → '.($properties['to'] ?? 'stage'),
                'crm.lead.transferred' => 'Transferred between pipelines',
                'crm.lead.converted' => 'Converted to contact',
                'crm.activity.created' => 'Activity added',
                'crm.activity.updated' => 'Activity changed: '.implode(', ', $properties['changed_fields'] ?? []).(in_array('due_at', $properties['changed_fields'] ?? [], true) ? ' ('.($properties['previous_due_at'] ?? 'unscheduled').' → '.($properties['due_at'] ?? 'unscheduled').')' : ''),
                'crm.follow_up.completed' => 'Follow-up completed',
                'crm.stage_follow_up.created' => 'Automatic follow-up created',
                default => str_replace(['crm.lead.', 'crm.'], '', $entry->event),
            };

            return ['id' => $entry->id, 'at' => $entry->created_at?->toIso8601String(), 'actor' => $entry->actor ? $entry->actor->name : 'System', 'event' => $entry->event, 'detail' => $detail];
        })->all());
    }

    /** @param array<string, mixed> $filters
     * @return array{total: int, page: int, per_page: int}
     */
    public function pagination(Organization $org, CrmLead $lead, array $filters = []): array
    {
        return ['total' => $this->query($org, $lead, $filters)->count(), 'page' => max(1, (int) ($filters['history_page'] ?? 1)), 'per_page' => 100];
    }

    /** @param array<string, mixed> $filters
     * @return Builder<AuditLog>
     */
    private function query(Organization $org, CrmLead $lead, array $filters): Builder
    {
        $activities = CrmActivity::where('organization_id', $org->id)->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->select('id');
        $query = AuditLog::where('organization_id', $org->id)
            ->where(function ($query) use ($lead, $activities): void {
                $query->where(fn ($direct) => $direct->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id))
                    ->orWhere(fn ($activity) => $activity->where('subject_type', (new CrmActivity)->getMorphClass())->whereIn('subject_id', $activities));
            });
        if (! empty($filters['history_event'])) {
            $query->where('event', $filters['history_event']);
        }
        if (! empty($filters['history_q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['history_q'])).'%';
            // Search event names and actor labels, never hidden custom values in audit JSON.
            $query->where(fn ($nested) => $nested->where('event', 'like', $term)->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', $term)));
        }

        return $query;
    }
}
