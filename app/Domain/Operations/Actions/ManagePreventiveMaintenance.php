<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagePreventiveMaintenance
{
    public function __construct(private ManageMaintenance $maintenance, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function create(Organization $organization, User $actor, array $input): PreventiveMaintenancePlan
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);

        return DB::transaction(function () use ($organization, $actor, $input): PreventiveMaintenancePlan {
            $this->maintenance->validateLinks($organization, (int) $input['property_id'], isset($input['unit_id']) ? (int) $input['unit_id'] : null, isset($input['vendor_id']) ? (int) $input['vendor_id'] : null);
            $plan = PreventiveMaintenancePlan::create([...Arr::only($input, ['property_id', 'unit_id', 'vendor_id', 'title', 'frequency_days', 'next_due_on']), 'organization_id' => $organization->id]);
            $this->audit->handle($organization, $actor, 'operations.preventive_plan.created', $plan);

            return $plan;
        });
    }

    public function generate(Organization $organization, User $actor, PreventiveMaintenancePlan $source, string $dueOn, bool $requiresManagerConfirmation = false): MaintenanceRequest
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);

        return DB::transaction(function () use ($organization, $actor, $source, $dueOn, $requiresManagerConfirmation): MaintenanceRequest {
            // Serialize generation on the plan, then re-read its current schedule.
            $plan = PreventiveMaintenancePlan::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($source->id);
            $existing = MaintenanceRequest::where('organization_id', $organization->id)
                ->where('preventive_maintenance_plan_id', $plan->id)->where('preventive_due_on', $dueOn)->first();
            if ($existing !== null) {
                return $existing;
            }
            $localToday = CarbonImmutable::now($organization->timezone)->toDateString();
            if (! $plan->is_active || $plan->next_due_on->toDateString() !== $dueOn || $dueOn > $localToday) {
                throw ValidationException::withMessages(['due_on' => 'This occurrence is no longer due. Refresh the plans and try again.']);
            }

            return $this->createOccurrence($organization, $actor, $plan, $dueOn, $requiresManagerConfirmation);
        });
    }

    /** @param array<string, mixed> $input */
    public function configureAutomation(Organization $organization, User $actor, PreventiveMaintenancePlan $source, array $input): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        DB::transaction(function () use ($organization, $actor, $source, $input): void {
            $plan = PreventiveMaintenancePlan::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($source->id);
            if ($input['auto_generate_enabled'] && $input['is_active']) {
                $this->maintenance->validateLinks($organization, $plan->property_id, $plan->unit_id, $plan->vendor_id);
            }
            $changes = Arr::only($input, ['auto_generate_enabled', 'requires_manager_confirmation', 'is_active']);
            $before = Arr::only($plan->getAttributes(), array_keys($changes));
            $plan->update([...$changes, 'automation_last_error' => null]);
            $this->audit->handle($organization, $actor, 'operations.preventive_plan.automation_configured', $plan, ['before' => $before, 'settings' => $changes]);
        });
    }

    public function generateAutomaticNext(Organization $organization, PreventiveMaintenancePlan $source): bool
    {
        return DB::transaction(function () use ($organization, $source): bool {
            $plan = PreventiveMaintenancePlan::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($source->id);
            if (! $plan->is_active || ! $plan->auto_generate_enabled || $plan->next_due_on->toDateString() > CarbonImmutable::now($organization->timezone)->toDateString()) {
                return false;
            }
            $dueOn = $plan->next_due_on->toDateString();
            if (MaintenanceRequest::where('organization_id', $organization->id)->where('preventive_maintenance_plan_id', $plan->id)->where('preventive_due_on', $dueOn)->exists()) {
                throw ValidationException::withMessages(['due_on' => 'The current schedule refers to an existing occurrence. Review the plan before continuing.']);
            }
            if ($plan->frequency_days < 1 || $plan->frequency_days > 3650) {
                throw ValidationException::withMessages(['frequency_days' => 'The plan frequency must be between 1 and 3,650 days.']);
            }
            $this->createOccurrence($organization, null, $plan, $dueOn, $plan->requires_manager_confirmation);
            $plan->update(['automation_last_run_at' => now(), 'automation_last_error' => null]);

            return true;
        });
    }

    private function createOccurrence(Organization $organization, ?User $actor, PreventiveMaintenancePlan $plan, string $dueOn, bool $requiresManagerConfirmation): MaintenanceRequest
    {
        $this->maintenance->validateLinks($organization, $plan->property_id, $plan->unit_id, $plan->vendor_id);
        $due = CarbonImmutable::parse($dueOn, $organization->timezone);
        $item = MaintenanceRequest::create([
            'organization_id' => $organization->id, 'property_id' => $plan->property_id,
            'unit_id' => $plan->unit_id, 'vendor_id' => $plan->vendor_id,
            'reference' => 'MNT-'.Str::upper(Str::random(8)), 'title' => $plan->title,
            'priority' => 'medium', 'due_at' => $due->endOfDay()->utc(),
            'preventive_maintenance_plan_id' => $plan->id, 'preventive_due_on' => $dueOn, 'requires_manager_confirmation' => $requiresManagerConfirmation,
        ]);
        $plan->update(['next_due_on' => $due->addDays($plan->frequency_days)->toDateString()]);
        $this->audit->handle($organization, $actor, 'operations.preventive_plan.generated', $item, ['plan_id' => $plan->id, 'due_on' => $dueOn, 'requires_manager_confirmation' => $requiresManagerConfirmation, 'source' => $actor === null ? 'automatic' : 'manual']);

        return $item;
    }
}
