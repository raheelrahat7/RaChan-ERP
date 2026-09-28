<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Actions\ManageAmcCoverage;
use App\Domain\Operations\Models\AmcContract;
use App\Domain\Operations\Models\AmcServiceVisit;
use App\Domain\Operations\Models\OperationsEquipment;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class AmcOverview
{
    public function __construct(private ManageAmcCoverage $coverage) {}

    /** @return array<string,mixed> */
    public function for(Organization $org, User $actor): array
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $today = CarbonImmutable::now($org->timezone)->format('Y-m-d');

        return [
            'properties' => Property::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name']),
            'equipment' => OperationsEquipment::where('organization_id', $org->id)->orderBy('reference')->get(),
            'vendors' => MaintenanceVendor::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name']),
            'contracts' => AmcContract::where('organization_id', $org->id)->with(['properties' => fn ($q) => $q->where('properties.organization_id', $org->id), 'equipment' => fn ($q) => $q->where('operations_equipment.organization_id', $org->id)])->latest('id')->paginate(20)->withQueryString()->through(function (AmcContract $contract) use ($org, $today): array {
                $used = $this->coverage->usage($org, $contract);
                $status = $contract->cancelled_at !== null ? 'cancelled' : ($today < $contract->starts_on->format('Y-m-d') ? 'scheduled' : ($today > $contract->ends_on->format('Y-m-d') ? 'expired' : 'active'));

                return [...$contract->toArray(), 'starts_on' => $contract->starts_on->format('Y-m-d'), 'ends_on' => $contract->ends_on->format('Y-m-d'), 'used' => $used, 'over_limit' => $contract->service_limit !== null && $used > $contract->service_limit, 'coverage_status' => $status];
            }),
            'visits' => AmcServiceVisit::where('organization_id', $org->id)->latest('id')->limit(50)->get()->map(fn (AmcServiceVisit $visit): array => [...$visit->toArray(), 'service_on' => $visit->service_on->format('Y-m-d')]),
        ];
    }
}
