<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;

class LeadSummaryExport
{
    public function __construct(private LeadVisibility $visibility) {}

    /** @return array<int, list<mixed>> */
    public function rows(Organization $organization, User $actor): array
    {
        return $this->visibility->scope(CrmLead::where('organization_id', $organization->id), $organization, $actor)
            ->orderBy('id')->get(['first_name', 'last_name', 'company', 'status', 'source'])
            ->map(fn (CrmLead $item): array => [$item->first_name, $item->last_name, $item->company, $item->status, $item->source])->values()->all();
    }
}
