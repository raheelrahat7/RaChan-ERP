<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Services\LeadVisibility;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BrokerPerformance
{
    public function __construct(private LeadVisibility $visibility) {}

    /** @return list<array<string,mixed>> */
    public function for(Organization $org, User $actor): array
    {
        $brokers = DB::table('brokers')->where('organization_id', $org->id)->orderBy('name')->limit(100)->get(['id', 'name']);
        $visibleIds = $this->visibility->restricted($org, $actor) ? $this->visibility->assigneeIds($org, $actor) : null;

        $canViewCommission = $actor->can('viewFinance', $org);

        return array_values($brokers->map(function ($broker) use ($org, $visibleIds, $canViewCommission): array {
            $listings = DB::table('listings')->where('organization_id', $org->id)->where('broker_id', $broker->id)->select('id');
            $leads = DB::table('crm_leads')->where('organization_id', $org->id)->whereIn('listing_id', $listings)
                ->when($visibleIds !== null, fn ($query) => $query->whereIn('assigned_to', $visibleIds));
            $commission = DB::table('commission_transactions')->where('organization_id', $org->id)->where('broker_id', $broker->id)->where('currency', 'AED');

            return ['broker_id' => (int) $broker->id, 'name' => $broker->name, 'team' => null,
                'leads' => (clone $leads)->count(), 'converted' => (clone $leads)->where('status', 'converted')->count(),
                'deals' => $visibleIds === null ? (clone $commission)->count() : null,
                'commission_aed' => $visibleIds === null && $canViewCommission ? round((float) $commission->sum('commission_amount'), 2) : null];
        })->all());
    }
}
