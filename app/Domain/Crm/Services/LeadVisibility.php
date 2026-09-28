<?php

namespace App\Domain\Crm\Services;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadVisibility
{
    public function restricted(Organization $organization, User $actor): bool
    {
        if ($actor->hasOrganizationRole($organization, OrganizationRole::Owner) || $actor->hasOrganizationRole($organization, OrganizationRole::Administrator)) {
            return false;
        }

        return ! DB::table('crm_visibility_grants')->where('organization_id', $organization->id)->where('user_id', $actor->id)->where('scope_type', 'organization')->where('scope_id', 0)->exists();
    }

    /** @return list<int> */
    public function assigneeIds(Organization $organization, User $actor): array
    {
        $ids = [$actor->id];
        if (! $this->restricted($organization, $actor)) {
            return array_values($organization->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all());
        }

        $grants = DB::table('crm_visibility_grants')->where('organization_id', $organization->id)->where('user_id', $actor->id)->get(['scope_type', 'scope_id']);
        $departments = $grants->where('scope_type', 'department')->pluck('scope_id')->all();
        $subdepartments = $grants->where('scope_type', 'subdepartment')->pluck('scope_id')->all();
        if ($departments || $subdepartments) {
            $scoped = DB::table('crm_team_memberships as membership')
                ->join('crm_teams as team', 'team.id', '=', 'membership.team_id')
                ->join('crm_subdepartments as subdepartment', 'subdepartment.id', '=', 'team.subdepartment_id')
                ->join('organization_user as member', fn ($join) => $join->on('member.user_id', '=', 'membership.user_id')->on('member.organization_id', '=', 'membership.organization_id'))
                ->where('membership.organization_id', $organization->id)
                ->where('team.organization_id', $organization->id)
                ->where('subdepartment.organization_id', $organization->id)
                ->where(fn ($query) => $query->whereIn('subdepartment.department_id', $departments)->orWhereIn('subdepartment.id', $subdepartments))
                ->pluck('membership.user_id')->all();
            $ids = [...$ids, ...$scoped];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, Organization $organization, User $actor): Builder
    {
        if ($this->restricted($organization, $actor)) {
            $query->whereIn('assigned_to', $this->assigneeIds($organization, $actor));
        }

        return $query;
    }

    public function canSeeLead(Organization $organization, User $actor, ?int $assigneeId): bool
    {
        return ! $this->restricted($organization, $actor) || ($assigneeId !== null && in_array($assigneeId, $this->assigneeIds($organization, $actor), true));
    }

    public function assigneeFilter(Organization $organization, User $actor, ?int $assigneeId): ?int
    {
        if ($assigneeId && ! $organization->users()->where('users.id', $assigneeId)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Select an organization member.']);
        }
        if ($assigneeId && ! $this->canSeeLead($organization, $actor, $assigneeId)) {
            throw ValidationException::withMessages(['assignee_id' => 'You cannot view that assignee.']);
        }

        return $assigneeId ?? ($this->restricted($organization, $actor) && count($this->assigneeIds($organization, $actor)) === 1 ? $actor->id : null);
    }
}
