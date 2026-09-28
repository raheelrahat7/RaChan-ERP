<?php

namespace App\Domain\Operations\Services;

use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class JobCardAccess
{
    public function manages(Organization $organization, User $actor): bool
    {
        return $actor->can('manageOperations', $organization);
    }

    public function canView(Organization $organization, User $actor, MaintenanceRequest $job): bool
    {
        return $job->organization_id === $organization->id
            && $actor->can('viewOperations', $organization)
            && ($this->manages($organization, $actor) || $job->assigned_to === $actor->id);
    }

    public function authorizeView(Organization $organization, User $actor, MaintenanceRequest $job): void
    {
        abort_unless($this->canView($organization, $actor, $job), 404);
    }

    /** @param Builder<MaintenanceRequest> $query
     * @return Builder<MaintenanceRequest>
     */
    public function scope(Builder $query, Organization $organization, User $actor): Builder
    {
        return $query->where('organization_id', $organization->id)
            ->when(! $actor->can('viewOperations', $organization), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when(! $this->manages($organization, $actor), fn (Builder $query) => $query->where('assigned_to', $actor->id));
    }
}
