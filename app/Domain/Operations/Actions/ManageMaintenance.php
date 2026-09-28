<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ManageMaintenance
{
    public function __construct(private RecordOrganizationAuditLog $audit, private ManageJobCompletion $completion) {}

    /** @param array<string, mixed> $input */
    public function create(Organization $organization, User $actor, array $input): MaintenanceRequest
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);

        return DB::transaction(function () use ($organization, $actor, $input): MaintenanceRequest {
            $this->validateLinks($organization, (int) $input['property_id'], isset($input['unit_id']) ? (int) $input['unit_id'] : null, isset($input['vendor_id']) ? (int) $input['vendor_id'] : null);
            $item = MaintenanceRequest::create([
                ...Arr::only($input, ['property_id', 'unit_id', 'vendor_id', 'title', 'priority', 'due_at', 'estimated_cost', 'requires_manager_confirmation']),
                'organization_id' => $organization->id,
                'reference' => 'MNT-'.Str::upper(Str::random(8)),
            ]);
            $this->audit->handle($organization, $actor, 'operations.maintenance.created', $item, ['requires_manager_confirmation' => (bool) ($input['requires_manager_confirmation'] ?? false)]);

            return $item;
        });
    }

    /** @param array<string, mixed> $input */
    public function updateStatus(Organization $organization, User $actor, MaintenanceRequest $item, array $input): void
    {
        $this->completion->updateStatus($organization, $actor, $item, $input);
    }

    /** @param array<string, mixed> $input */
    public function updateWorkOrder(Organization $organization, User $actor, MaintenanceRequest $item, array $input): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        DB::transaction(function () use ($organization, $actor, $item, $input): void {
            $item = MaintenanceRequest::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($item->id);
            abort_if(in_array($item->status, ['completed', 'cancelled'], true) || $item->submitted_at !== null, 422, 'Reopen the job before changing its work order.');
            if (isset($input['vendor_id'])) {
                MaintenanceVendor::where('organization_id', $organization->id)->findOrFail((int) $input['vendor_id']);
            }
            $changes = Arr::only($input, ['vendor_id', 'estimated_cost', 'actual_cost']);
            $item->update($changes);
            $this->audit->handle($organization, $actor, 'operations.maintenance.work_order_updated', $item, $changes);
        });
    }

    /** @param array<string, mixed> $input */
    public function createVendor(Organization $organization, User $actor, array $input): MaintenanceVendor
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);

        return DB::transaction(function () use ($organization, $actor, $input): MaintenanceVendor {
            $vendor = MaintenanceVendor::create([...Arr::only($input, ['name', 'trade', 'email', 'phone']), 'organization_id' => $organization->id]);
            $this->audit->handle($organization, $actor, 'operations.vendor.created', $vendor);

            return $vendor;
        });
    }

    public function validateLinks(Organization $organization, int $propertyId, ?int $unitId, ?int $vendorId): void
    {
        Property::where('organization_id', $organization->id)->findOrFail($propertyId);
        if ($unitId !== null) {
            Unit::where('organization_id', $organization->id)->where('property_id', $propertyId)->findOrFail($unitId);
        }
        if ($vendorId !== null) {
            MaintenanceVendor::where('organization_id', $organization->id)->findOrFail($vendorId);
        }
    }
}
