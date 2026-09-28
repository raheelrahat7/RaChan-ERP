<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Services\JobCardAccess;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\User;

class OperationalAlerts
{
    public function __construct(private JobCardAccess $access) {}

    /** @return array<string, int> */
    public function counts(Organization $organization, ?User $actor): array
    {
        $jobs = $actor === null ? MaintenanceRequest::where('organization_id', $organization->id) : $this->access->scope(MaintenanceRequest::query(), $organization, $actor);

        return [
            'overdue_maintenance' => $jobs->whereIn('status', ['open', 'in_progress'])->where('due_at', '<', now())->count(),
            'due_preventive_plans' => $actor !== null && ! $this->access->manages($organization, $actor) ? 0 : PreventiveMaintenancePlan::where('organization_id', $organization->id)->where('is_active', true)->where('next_due_on', '<=', now($organization->timezone)->toDateString())->count(),
        ];
    }
}
