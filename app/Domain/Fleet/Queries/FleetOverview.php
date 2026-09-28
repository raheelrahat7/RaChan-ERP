<?php

namespace App\Domain\Fleet\Queries;

use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Fleet\Models\FleetAssignment;
use App\Domain\Fleet\Models\FleetService;
use App\Domain\Fleet\Models\FleetVehicle;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class FleetOverview
{
    /** @return array<string,mixed> */
    public function index(Organization $org, User $actor): array
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);

        return ['vehicles' => FleetVehicle::where('organization_id', $org->id)->latest('id')->paginate(25)->withQueryString(), 'properties' => Property::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name']), 'assets' => FixedAsset::where('organization_id', $org->id)->orderBy('reference')->get(['id', 'reference', 'name'])];
    }

    /** @return array<string,mixed> */
    public function vehicle(Organization $org, User $actor, FleetVehicle $vehicle): array
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $vehicle = FleetVehicle::where('organization_id', $org->id)->findOrFail($vehicle->id);
        $today = CarbonImmutable::now($org->timezone)->format('Y-m-d');

        return ['vehicle' => $vehicle, 'members' => $org->users()->orderBy('name')->get(['users.id', 'users.name']), 'vendors' => MaintenanceVendor::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name']),
            'assignments' => FleetAssignment::where('organization_id', $org->id)->where('fleet_vehicle_id', $vehicle->id)->latest('id')->paginate(20, ['*'], 'assignment_page')->withQueryString(),
            'services' => FleetService::where('organization_id', $org->id)->where('fleet_vehicle_id', $vehicle->id)->latest('id')->paginate(20, ['*'], 'service_page')->withQueryString()->through(fn (FleetService $service): array => [...$service->toArray(), 'due_on' => $service->due_on->format('Y-m-d'), 'overdue' => $service->status === 'planned' && $service->due_on->format('Y-m-d') < $today]),
        ];
    }
}
