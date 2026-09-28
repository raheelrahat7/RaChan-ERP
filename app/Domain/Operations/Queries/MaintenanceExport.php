<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Services\JobCardAccess;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;

class MaintenanceExport
{
    public function __construct(private JobCardAccess $access) {}

    /** @return array<int, list<mixed>> */
    public function rows(Organization $organization, User $actor): array
    {
        return $this->access->scope(MaintenanceRequest::query(), $organization, $actor)->orderBy('id')->get(['reference', 'title', 'priority', 'status', 'due_at'])
            ->map(fn (MaintenanceRequest $item): array => [$item->reference, $item->title, $item->priority, $item->status, $item->due_at?->toDateTimeString()])->values()->all();
    }
}
