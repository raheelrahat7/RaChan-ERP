<?php

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Models\FleetAssignment;
use App\Domain\Fleet\Models\FleetService;
use App\Domain\Fleet\Models\FleetVehicle;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageFleet
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function vehicle(Organization $org, User $actor, array $input): FleetVehicle
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, ['reference' => ['required', 'string', 'max:100'], 'plate' => ['required', 'string', 'max:100'], 'vin' => ['nullable', 'string', 'max:100'], 'make' => ['required', 'string', 'max:255'], 'model' => ['required', 'string', 'max:255'], 'year' => ['nullable', 'integer', 'min:1900', 'max:2100'], 'odometer' => ['required', 'integer', 'min:0', 'max:999999999'], 'property_id' => ['nullable', 'integer', Rule::exists('properties', 'id')->where('organization_id', $org->id)], 'fixed_asset_id' => ['nullable', 'integer', Rule::exists('fixed_assets', 'id')->where('organization_id', $org->id)]])->validate();

        return DB::transaction(function () use ($org, $actor, $values): FleetVehicle {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach (['reference', 'plate', 'vin', 'make', 'model'] as $field) {
                if (isset($values[$field])) {
                    $values[$field] = trim($values[$field]);
                }
            }
            foreach (['reference', 'plate', 'make', 'model'] as $field) {
                $this->check($values[$field] !== '', $field, 'This value is required.');
            }
            $values['vin'] = empty($values['vin']) ? null : strtoupper($values['vin']);
            $values['plate'] = strtoupper($values['plate']);
            foreach (['reference', 'plate', 'vin'] as $field) {
                if ($values[$field] !== null) {
                    $this->check(! FleetVehicle::where('organization_id', $org->id)->where($field, $values[$field])->exists(), $field, 'This vehicle identifier is already used.');
                }
            }
            $vehicle = FleetVehicle::create([...$values, 'organization_id' => $org->id]);
            $this->audit->handle($org, $actor, 'fleet.vehicle.created', $vehicle);

            return $vehicle;
        });
    }

    public function assign(Organization $org, User $actor, FleetVehicle $vehicle, int $userId, string $reason): FleetAssignment
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($org, $actor, $vehicle, $userId, $reason): FleetAssignment {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $vehicle = FleetVehicle::where('organization_id', $org->id)->findOrFail($vehicle->id);
            $org->users()->findOrFail($userId);
            $this->check($vehicle->status === 'active', 'vehicle', 'Only an active vehicle can be assigned.');
            $this->check(! FleetAssignment::where('organization_id', $org->id)->where('fleet_vehicle_id', $vehicle->id)->whereNull('ended_at')->exists(), 'vehicle', 'Return the current assignment before assigning this vehicle again.');
            $record = FleetAssignment::create(['organization_id' => $org->id, 'fleet_vehicle_id' => $vehicle->id, 'user_id' => $userId, 'recorded_by' => $actor->id, 'reason' => $reason, 'started_at' => now()]);
            $this->audit->handle($org, $actor, 'fleet.vehicle.assigned', $record);

            return $record;
        });
    }

    public function returnVehicle(Organization $org, User $actor, FleetAssignment $assignment, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $reason = $this->reason($reason);
        DB::transaction(function () use ($org, $actor, $assignment, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $assignment = FleetAssignment::where('organization_id', $org->id)->findOrFail($assignment->id);
            if ($assignment->ended_at !== null) {
                return;
            }
            $assignment->update(['ended_at' => now(), 'returned_by' => $actor->id, 'return_reason' => $reason]);
            $this->audit->handle($org, $actor, 'fleet.vehicle.returned', $assignment, ['reason' => $reason]);
        });
    }

    /** @param array<string,mixed> $input */
    public function service(Organization $org, User $actor, FleetVehicle $vehicle, array $input): FleetService
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, ['kind' => ['required', 'in:preventive,repair,inspection'], 'description' => ['required', 'string', 'max:255'], 'due_on' => ['required', 'date_format:Y-m-d'], 'operation_key' => ['required', 'uuid'], 'vendor_id' => ['nullable', 'integer', Rule::exists('maintenance_vendors', 'id')->where('organization_id', $org->id)], 'maintenance_request_id' => ['nullable', 'integer', Rule::exists('maintenance_requests', 'id')->where('organization_id', $org->id)]])->validate();

        return DB::transaction(function () use ($org, $actor, $vehicle, $values): FleetService {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $vehicle = FleetVehicle::where('organization_id', $org->id)->findOrFail($vehicle->id);
            $description = trim($values['description']);
            $this->check($description !== '', 'description', 'Describe the scheduled service.');
            $existing = FleetService::where('organization_id', $org->id)->where('operation_key', $values['operation_key'])->first();
            $vendorId = isset($values['vendor_id']) ? (int) $values['vendor_id'] : null;
            $jobId = isset($values['maintenance_request_id']) ? (int) $values['maintenance_request_id'] : null;
            if ($existing !== null) {
                $this->check($existing->fleet_vehicle_id === $vehicle->id && $existing->kind === $values['kind'] && $existing->description === $description && $existing->due_on->format('Y-m-d') === $values['due_on'] && $existing->vendor_id === $vendorId && $existing->maintenance_request_id === $jobId, 'operation_key', 'This service key already has different details.');

                return $existing;
            }
            $this->check($vehicle->status !== 'retired', 'vehicle', 'Retired vehicles cannot receive new service work.');
            if ($jobId !== null) {
                $job = MaintenanceRequest::where('organization_id', $org->id)->findOrFail($jobId);
                $this->check($vehicle->property_id === null || $vehicle->property_id === $job->property_id, 'maintenance_request_id', 'The linked job must belong to the vehicle property.');
                $this->check(! FleetService::where('organization_id', $org->id)->where('maintenance_request_id', $jobId)->exists(), 'maintenance_request_id', 'This job is already linked to a vehicle service.');
            }
            $service = FleetService::create([...$values, 'description' => $description, 'vendor_id' => $vendorId, 'maintenance_request_id' => $jobId, 'organization_id' => $org->id, 'fleet_vehicle_id' => $vehicle->id]);
            $this->audit->handle($org, $actor, 'fleet.service.scheduled', $service);

            return $service;
        });
    }

    public function complete(Organization $org, User $actor, FleetService $service, int $odometer, string $note): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $note = $this->reason($note);
        Validator::make(['odometer' => $odometer], ['odometer' => ['required', 'integer', 'min:0', 'max:999999999']])->validate();
        DB::transaction(function () use ($org, $actor, $service, $odometer, $note): void {
            // Match the job-first lock order used by existing job-card stock and lifecycle actions.
            $candidate = FleetService::where('organization_id', $org->id)->findOrFail($service->id);
            $job = $candidate->maintenance_request_id === null ? null : MaintenanceRequest::where('organization_id', $org->id)->lockForUpdate()->findOrFail($candidate->maintenance_request_id);
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $service = FleetService::where('organization_id', $org->id)->findOrFail($service->id);
            $vehicle = FleetVehicle::where('organization_id', $org->id)->findOrFail($service->fleet_vehicle_id);
            if ($service->status === 'completed' && $service->completed_odometer === $odometer && $service->completion_note === $note) {
                return;
            }
            $this->check($service->status === 'planned', 'service', 'Only planned service work can be completed.');
            $this->check($odometer >= $vehicle->odometer, 'odometer', 'Mileage cannot move backwards.');
            $this->check($job === null || $job->status === 'completed', 'maintenance_request_id', 'Complete the linked job through its normal approval workflow first.');
            $vehicle->update(['odometer' => $odometer]);
            $service->update(['status' => 'completed', 'completed_odometer' => $odometer, 'completion_note' => $note, 'completed_at' => now(), 'completed_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'fleet.service.completed', $service, ['odometer' => $odometer, 'note' => $note]);
        });
    }

    public function cancel(Organization $org, User $actor, FleetService $service, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $reason = $this->reason($reason);
        DB::transaction(function () use ($org, $actor, $service, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $service = FleetService::where('organization_id', $org->id)->findOrFail($service->id);
            $this->check($service->status === 'planned', 'service', 'Only planned service work can be cancelled.');
            $service->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);
            $this->audit->handle($org, $actor, 'fleet.service.cancelled', $service, ['reason' => $reason]);
        });
    }

    public function status(Organization $org, User $actor, FleetVehicle $vehicle, string $status, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        Validator::make(['status' => $status], ['status' => ['required', 'in:active,in_service,retired']])->validate();
        $reason = $this->reason($reason);
        DB::transaction(function () use ($org, $actor, $vehicle, $status, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $vehicle = FleetVehicle::where('organization_id', $org->id)->findOrFail($vehicle->id);
            if ($status === 'retired') {
                $this->check(! FleetAssignment::where('organization_id', $org->id)->where('fleet_vehicle_id', $vehicle->id)->whereNull('ended_at')->exists(), 'vehicle', 'Return the vehicle before retiring it.');
                $this->check(! FleetService::where('organization_id', $org->id)->where('fleet_vehicle_id', $vehicle->id)->where('status', 'planned')->exists(), 'vehicle', 'Complete or cancel planned service work before retiring the vehicle.');
            }
            $vehicle->update(['status' => $status]);
            $this->audit->handle($org, $actor, 'fleet.vehicle.status_changed', $vehicle, ['status' => $status, 'reason' => $reason]);
        });
    }

    private function reason(string $reason): string
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        $this->check($reason !== '', 'reason', 'Record a reason or service note.');

        return $reason;
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
