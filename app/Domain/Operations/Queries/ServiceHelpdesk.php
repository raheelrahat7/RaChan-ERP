<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Actions\ManageJobSla;
use App\Domain\Operations\Services\JobCardAccess;
use App\Domain\Operations\Services\JobSlaClock;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;

class ServiceHelpdesk
{
    public function __construct(private JobCardAccess $access, private ManageJobSla $sla, private JobSlaClock $clock) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function for(Organization $organization, User $actor, array $filters): array
    {
        $jobs = $this->access->scope(MaintenanceRequest::query(), $organization, $actor)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('reference', 'like', '%'.$search.'%')->orWhere('title', 'like', '%'.$search.'%')))
            ->latest('id')->paginate(25)->withQueryString();
        $jobs->through(function (MaintenanceRequest $job): array {
            $cycle = $this->sla->latest($job);

            return [...$job->only(['id', 'reference', 'title', 'priority', 'status']), 'sla' => $cycle === null ? null : $this->clock->summary($cycle)];
        });

        return ['jobs' => $jobs, 'filters' => $filters];
    }
}
