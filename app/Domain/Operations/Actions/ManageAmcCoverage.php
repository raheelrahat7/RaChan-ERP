<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Operations\Models\AmcContract;
use App\Domain\Operations\Models\AmcServiceVisit;
use App\Domain\Operations\Models\OperationsEquipment;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageAmcCoverage
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function equipment(Organization $org, User $actor, array $input): OperationsEquipment
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, ['property_id' => ['required', 'integer', Rule::exists('properties', 'id')->where('organization_id', $org->id)], 'reference' => ['required', 'string', 'max:255'], 'name' => ['required', 'string', 'max:255'], 'serial_number' => ['nullable', 'string', 'max:255']])->validate();

        return DB::transaction(function () use ($org, $actor, $values): OperationsEquipment {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $values['reference'] = trim($values['reference']);
            $values['name'] = trim($values['name']);
            $this->require($values['reference'] !== '' && $values['name'] !== '', 'Reference and name are required.', 'reference');
            $this->require(! OperationsEquipment::where('organization_id', $org->id)->where('reference', $values['reference'])->exists(), 'This equipment reference is already used.', 'reference');
            $equipment = OperationsEquipment::create([...$values, 'organization_id' => $org->id]);
            $this->audit->handle($org, $actor, 'operations.equipment.created', $equipment);

            return $equipment;
        });
    }

    /** @param array<string,mixed> $input */
    public function contract(Organization $org, User $actor, array $input): AmcContract
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, [
            'reference' => ['required', 'string', 'max:255'], 'title' => ['required', 'string', 'max:255'], 'vendor_id' => ['nullable', 'integer', Rule::exists('maintenance_vendors', 'id')->where('organization_id', $org->id)],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'service_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'terms' => ['nullable', 'string', 'max:10000'],
            'property_ids' => ['present', 'array', 'max:500'], 'property_ids.*' => ['integer', 'distinct', Rule::exists('properties', 'id')->where('organization_id', $org->id)],
            'equipment_ids' => ['present', 'array', 'max:500'], 'equipment_ids.*' => ['integer', 'distinct', Rule::exists('operations_equipment', 'id')->where('organization_id', $org->id)]])->validate();

        return DB::transaction(function () use ($org, $actor, $values): AmcContract {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $this->require(count($values['property_ids']) + count($values['equipment_ids']) > 0, 'Select at least one property or equipment item.', 'property_ids');
            $values['reference'] = trim($values['reference']);
            $values['title'] = trim($values['title']);
            $this->require($values['reference'] !== '' && $values['title'] !== '', 'Reference and title are required.', 'reference');
            $this->require(! AmcContract::where('organization_id', $org->id)->where('reference', $values['reference'])->exists(), 'This contract reference is already used.', 'reference');
            $properties = $values['property_ids'];
            $equipment = $values['equipment_ids'];
            unset($values['property_ids'],$values['equipment_ids']);
            $contract = AmcContract::create([...$values, 'organization_id' => $org->id, 'created_by' => $actor->id]);
            $contract->properties()->attach($properties);
            $contract->equipment()->attach($equipment);
            $this->audit->handle($org, $actor, 'operations.amc.created', $contract, ['property_ids' => $properties, 'equipment_ids' => $equipment]);

            return $contract;
        });
    }

    /** @param array<string,mixed> $input */
    public function visit(Organization $org, User $actor, AmcContract $contract, array $input): AmcServiceVisit
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, ['maintenance_request_id' => ['required', 'integer'], 'operations_equipment_id' => ['nullable', 'integer'], 'service_on' => ['required', 'date_format:Y-m-d'], 'override_reason' => ['nullable', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($org, $actor, $contract, $values): AmcServiceVisit {
            $job = MaintenanceRequest::where('organization_id', $org->id)->lockForUpdate()->findOrFail((int) $values['maintenance_request_id']);
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $contract = AmcContract::where('organization_id', $org->id)->findOrFail($contract->id);
            $equipmentId = isset($values['operations_equipment_id']) ? (int) $values['operations_equipment_id'] : null;
            $reason = trim((string) ($values['override_reason'] ?? ''));
            $existing = AmcServiceVisit::where('organization_id', $org->id)->where('maintenance_request_id', $job->id)->first();
            if ($existing !== null) {
                $this->require($existing->amc_contract_id === $contract->id && $existing->operations_equipment_id === $equipmentId && $existing->service_on->format('Y-m-d') === $values['service_on'] && $existing->override_reason === ($reason === '' ? null : $reason), 'This job already has different AMC coverage.', 'maintenance_request_id');

                return $existing;
            }
            $this->require($contract->cancelled_at === null, 'This contract is cancelled.', 'amc_contract_id');
            $this->require($job->status !== 'cancelled', 'Cancelled jobs cannot reserve coverage.', 'maintenance_request_id');
            $this->require($values['service_on'] >= $contract->starts_on->format('Y-m-d') && $values['service_on'] <= $contract->ends_on->format('Y-m-d'), 'Service date is outside contract coverage.', 'service_on');
            $propertyCovered = $contract->properties()->where('properties.organization_id', $org->id)->whereKey($job->property_id)->exists();
            $equipmentCovered = false;
            if ($equipmentId !== null) {
                $equipment = OperationsEquipment::where('organization_id', $org->id)->findOrFail($equipmentId);
                $this->require($equipment->property_id === $job->property_id, 'Equipment must belong to the job property.', 'operations_equipment_id');
                $equipmentCovered = $contract->equipment()->where('operations_equipment.organization_id', $org->id)->whereKey($equipmentId)->exists();
            }
            $this->require($propertyCovered || $equipmentCovered, 'This property/equipment is outside contract coverage.', 'maintenance_request_id');
            $used = $this->usage($org, $contract);
            $exceeded = $contract->service_limit !== null && $used >= $contract->service_limit;
            $this->require(! $exceeded || $reason !== '', 'The service limit is reached. Record a manager override reason.', 'override_reason');
            $visit = AmcServiceVisit::create([...$values, 'operations_equipment_id' => $equipmentId, 'override_reason' => $reason === '' ? null : $reason, 'organization_id' => $org->id, 'amc_contract_id' => $contract->id, 'recorded_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'operations.amc.visit_reserved', $visit, ['contract_id' => $contract->id, 'job_id' => $job->id, 'limit_exceeded' => $exceeded, 'override_reason' => $reason]);

            return $visit;
        });
    }

    public function usage(Organization $org, AmcContract $contract): int
    {
        return AmcServiceVisit::where('organization_id', $org->id)->where('amc_contract_id', $contract->id)->whereIn('maintenance_request_id', MaintenanceRequest::where('organization_id', $org->id)->where('status', '!=', 'cancelled')->select('id'))->count();
    }

    public function cancel(Organization $org, User $actor, AmcContract $contract, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        $this->require($reason !== '', 'Record a cancellation reason.', 'reason');
        DB::transaction(function () use ($org, $actor, $contract, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $contract = AmcContract::where('organization_id', $org->id)->findOrFail($contract->id);
            if ($contract->cancelled_at !== null) {
                return;
            }
            $contract->update(['cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit->handle($org, $actor, 'operations.amc.cancelled', $contract, ['reason' => $reason]);
        });
    }

    private function require(bool $condition, string $message, string $field): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
