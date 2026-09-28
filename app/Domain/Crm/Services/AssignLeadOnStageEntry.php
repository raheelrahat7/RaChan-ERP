<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\AssignmentAgent;
use App\Domain\Crm\Models\AssignmentHold;
use App\Domain\Crm\Models\AssignmentRoute;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class AssignLeadOnStageEntry
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $org, CrmLead $lead, PipelineStage $stage): void
    {
        if ($lead->assigned_to !== null || $lead->converted_at !== null || in_array($stage->type, ['won', 'lost'], true)) {
            return;
        }

        Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
        $route = $this->route($org, $lead);
        $pool = $route
            ? AssignmentRoute::whereKey($route->id)->lockForUpdate()->firstOrFail()
            : PipelineStage::whereKey($stage->id)->lockForUpdate()->firstOrFail();
        $configured = $pool instanceof AssignmentRoute ? $this->routeMembers($org, $pool) : ($pool->assignment_member_ids ?? []);
        if (! $route && ! $configured) {
            return;
        }

        $members = $org->users()->whereIn('users.id', $configured)->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $today = now($org->timezone)->toDateString();
        $available = AssignmentAgent::where('organization_id', $org->id)->whereIn('user_id', $members)->whereDate('available_on', $today)->where('max_active_leads', '>', 0)->get()->keyBy('user_id');
        $eligible = [];
        foreach ($members as $memberId) {
            $settings = $available->get($memberId);
            if ($settings && $this->activeLeadCount($org, $memberId) < $settings->max_active_leads) {
                $eligible[] = $memberId;
            }
        }
        if (! $eligible) {
            $reason = ! $members ? 'no_members' : ($available->isEmpty() ? 'unavailable' : 'quota');
            $this->hold($org, $lead, $reason, $route ? $route->match_type.': '.$route->match_value : 'stage: '.$stage->name);

            return;
        }

        $last = array_search((int) ($pool instanceof AssignmentRoute ? $pool->last_user_id : $pool->assignment_last_user_id), $eligible, true);
        $next = $eligible[$last === false ? 0 : ($last + 1) % count($eligible)];
        $lead->update(['assigned_to' => $next]);
        $pool->update([$route ? 'last_user_id' : 'assignment_last_user_id' => $next]);
        $hold = AssignmentHold::where('organization_id', $org->id)->where('lead_id', $lead->id)->whereNull('resolved_at')->first();
        if ($hold) {
            $hold->update(['resolved_at' => now()]);
        }
        $this->audit->handle($org, null, 'crm.lead.auto_assigned', $lead, ['stage_id' => $stage->id, 'route_id' => $route?->id, 'assigned_to' => $next, 'resolved_hold_id' => $hold?->id]);
    }

    private function route(Organization $org, CrmLead $lead): ?AssignmentRoute
    {
        foreach (['meta_form_id', 'meta_form_name', 'meta_page_id', 'campaign_name', 'project_name'] as $field) {
            $value = trim((string) $lead->{$field});
            if ($value === '') {
                continue;
            }
            $type = match ($field) {
                'campaign_name' => 'campaign',
                'project_name' => 'project',
                default => $field,
            };
            $route = AssignmentRoute::where('organization_id', $org->id)->where('active', true)->where('match_type', $type)->where('match_value', mb_strtolower($value))->first();
            if ($route) {
                return $route;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function routeMembers(Organization $org, AssignmentRoute $route): array
    {
        if ($route->target_type === 'members') {
            return array_values(array_map('intval', $route->member_ids ?? []));
        }
        $query = DB::table('crm_team_memberships as membership')
            ->join('crm_teams as team', 'team.id', '=', 'membership.team_id')
            ->join('crm_subdepartments as sub', 'sub.id', '=', 'team.subdepartment_id')
            ->join('crm_departments as dept', 'dept.id', '=', 'sub.department_id')
            ->where('membership.organization_id', $org->id)
            ->where('team.organization_id', $org->id)
            ->where('sub.organization_id', $org->id)
            ->where('dept.organization_id', $org->id)
            ->where('team.active', true)->where('sub.active', true)->where('dept.active', true);
        $column = match ($route->target_type) {
            'department' => 'dept.id',
            'subdepartment' => 'sub.id',
            default => 'team.id',
        };

        return array_values($query->where($column, $route->target_id)->pluck('membership.user_id')->map(fn ($id) => (int) $id)->values()->all());
    }

    private function activeLeadCount(Organization $org, int $memberId): int
    {
        return CrmLead::where('crm_leads.organization_id', $org->id)
            ->where('assigned_to', $memberId)
            ->whereNull('converted_at')
            ->join('crm_pipeline_stages as stage', 'stage.id', '=', 'crm_leads.current_stage_id')
            ->whereNotIn('stage.type', ['won', 'lost'])
            ->count();
    }

    private function hold(Organization $org, CrmLead $lead, string $reason, string $routeLabel): void
    {
        $hold = AssignmentHold::where('organization_id', $org->id)->where('lead_id', $lead->id)->first();
        if ($hold && ! $hold->resolved_at) {
            $hold->update(['reason' => $reason, 'route_label' => $routeLabel]);

            return;
        }
        if ($hold) {
            $hold->update(['episode' => $hold->episode + 1, 'reason' => $reason, 'route_label' => $routeLabel, 'resolved_at' => null, 'created_at' => now()]);
        } else {
            $hold = AssignmentHold::create(['organization_id' => $org->id, 'lead_id' => $lead->id, 'episode' => 1, 'reason' => $reason, 'route_label' => $routeLabel]);
        }
        $eventKey = 'crm_assignment_hold:'.$hold->id.':'.$hold->episode;
        foreach ($org->users()->wherePivotIn('role', ['owner', 'administrator'])->get(['users.id']) as $admin) {
            OrganizationNotification::query()->insertOrIgnore([
                'organization_id' => $org->id,
                'user_id' => $admin->id,
                'category' => 'crm_assignment_hold',
                'event_key' => $eventKey,
                'title' => $reason === 'quota' ? 'Lead on hold: selected agents reached their quota' : 'Lead on hold: no selected agent is available',
                'count' => 1,
                'href' => '/crm/assignment',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->audit->handle($org, null, 'crm.lead.assignment_held', $lead, ['hold_id' => $hold->id, 'reason' => $reason, 'route' => $routeLabel]);
    }
}
