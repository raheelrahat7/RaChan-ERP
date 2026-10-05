<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\DealAccessRule;
use App\Domain\Crm\Models\DealPipeline;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DealAccess
{
    public const ACTIONS = ['read', 'add', 'edit', 'move', 'transfer', 'assign', 'export', 'amount'];

    public const SCOPES = ['none', 'own', 'team', 'subdepartment', 'department', 'organization'];

    public function administrator(Organization $org, User $actor): bool
    {
        return $actor->hasOrganizationRole($org, OrganizationRole::Owner) || $actor->hasOrganizationRole($org, OrganizationRole::Administrator);
    }

    /** @return list<string> */
    public function scopes(Organization $org, User $actor, DealPipeline $pipeline, string $action): array
    {
        if ($pipeline->organization_id !== $org->id || ! $actor->hasOrganizationPermission($org, OrganizationPermission::ViewCrm)) {
            return [];
        }
        if ($this->administrator($org, $actor)) {
            return ['organization'];
        }
        $rules = DealAccessRule::where('organization_id', $org->id)->where('pipeline_id', $pipeline->id)->get();
        if ($rules->isEmpty() && ! $pipeline->access_configured) {
            return in_array($action, ['read', 'amount'], true) || ($action !== 'export' && app(CrmEditPermission::class)->granted($org, $actor)) ? ['own'] : [];
        }
        $role = $actor->organizations()->whereKey($org->id)->value('organization_user.role');
        $placements = $this->placements($org, $actor);
        $scopes = $rules->filter(fn ($rule) => match ($rule->principal_type) {
            'role' => $rule->principal_id === $role,
            'user' => $rule->principal_id === (string) $actor->id,
            'team' => $placements->contains('team_id', (int) $rule->principal_id),
            'subdepartment' => $placements->contains('subdepartment_id', (int) $rule->principal_id),
            'department' => $placements->contains('department_id', (int) $rule->principal_id),
            default => false,
        })->map(fn ($rule): string => (string) ($rule->permissions[$action] ?? 'none'))->filter(fn ($scope) => $scope !== 'none')->unique()->values()->all();

        return array_values($scopes);
    }

    public function allows(Organization $org, User $actor, DealPipeline $pipeline, string $action, ?int $assignee = null): bool
    {
        $scopes = $this->scopes($org, $actor, $pipeline, $action);

        return in_array('organization', $scopes, true) || ($assignee !== null && in_array($assignee, $this->assignees($org, $actor, $scopes), true));
    }

    /** @param list<string> $scopes
     * @return list<int>
     */
    public function assignees(Organization $org, User $actor, array $scopes): array
    {
        if (in_array('organization', $scopes, true)) {
            return array_values($org->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all());
        }
        $own = in_array('own', $scopes, true) ? [$actor->id] : [];
        $placements = $this->placements($org, $actor);
        if (! array_intersect($scopes, ['team', 'subdepartment', 'department'])) {
            return $own;
        }
        $ids = $this->membershipQuery($org)->where(function ($query) use ($scopes, $placements): void {
            $query->whereRaw('1 = 0');
            foreach (['team' => 'team.id', 'subdepartment' => 'subdepartment.id', 'department' => 'subdepartment.department_id'] as $scope => $column) {
                if (in_array($scope, $scopes, true)) {
                    $query->orWhereIn($column, $placements->pluck($scope.'_id'));
                }
            }
        })->pluck('membership.user_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique([...$own, ...$ids]));
    }

    /** @return array<string, mixed>|null */
    public function linkedLeadDeal(Organization $org, User $actor, CrmLead $lead): ?array
    {
        $deal = $lead->deal;

        return $deal && $this->allows($org, $actor, $deal->pipeline, 'read', $deal->assigned_to) ? $deal->only('id', 'title', 'pipeline_id', 'current_stage_id') : null;
    }

    /** @return Builder<Deal> */
    public function query(Organization $org, User $actor, string $action = 'read'): Builder
    {
        return Deal::where('organization_id', $org->id)->where(function ($query) use ($org, $actor, $action): void {
            $query->whereRaw('1 = 0');
            foreach (DealPipeline::where('organization_id', $org->id)->get() as $pipeline) {
                $scopes = $this->scopes($org, $actor, $pipeline, $action);
                if ($scopes === []) {
                    continue;
                }
                $query->orWhere(function ($branch) use ($pipeline, $scopes, $org, $actor): void {
                    $branch->where('pipeline_id', $pipeline->id);
                    if (! in_array('organization', $scopes, true)) {
                        $branch->whereIn('assigned_to', $this->assignees($org, $actor, $scopes));
                    }
                });
            }
        });
    }

    /** @return Collection<int, \stdClass> */
    private function placements(Organization $org, User $actor): Collection
    {
        return $this->membershipQuery($org)->where('membership.user_id', $actor->id)->get(['team.id as team_id', 'subdepartment.id as subdepartment_id', 'subdepartment.department_id']);
    }

    private function membershipQuery(Organization $org): \Illuminate\Database\Query\Builder
    {
        return DB::table('crm_team_memberships as membership')
            ->join('crm_teams as team', 'team.id', '=', 'membership.team_id')
            ->join('crm_subdepartments as subdepartment', 'subdepartment.id', '=', 'team.subdepartment_id')
            ->join('crm_departments as department', 'department.id', '=', 'subdepartment.department_id')
            ->join('organization_user as member', fn ($join) => $join->on('member.user_id', '=', 'membership.user_id')->on('member.organization_id', '=', 'membership.organization_id'))
            ->where('membership.organization_id', $org->id)->where('team.organization_id', $org->id)->where('subdepartment.organization_id', $org->id)->where('department.organization_id', $org->id)
            ->where('team.active', true)->where('subdepartment.active', true)->where('department.active', true);
    }
}
