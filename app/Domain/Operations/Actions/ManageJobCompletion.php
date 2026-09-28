<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageJobCompletion
{
    public function __construct(private JobCardAccess $access, private RecordOrganizationAuditLog $audit, private ManageJobSla $sla) {}

    public function finish(Organization $organization, User $actor, MaintenanceRequest $job): void
    {
        DB::transaction(function () use ($organization, $actor, $job): void {
            $job = $this->locked($organization, $actor, $job);
            if ($job->status === 'completed' || $job->submitted_at !== null) {
                return;
            }
            $this->require($job->status !== 'cancelled', __('Cancelled jobs must be reopened before recording completion.'));
            $this->require($job->status !== 'on_hold' || $this->sla->latest($job) === null, __('A manager must resume this SLA hold before submitting completion.'));
            $this->checklist($organization, $job);
            if ($job->requires_manager_confirmation) {
                $before = $job->status;
                $job->update(['status' => 'in_progress', 'submitted_at' => now(), 'submitted_by' => $actor->id]);
                $this->audit->handle($organization, $actor, 'operations.job.submitted', $job, ['before' => $before, 'status' => $job->status]);
            } else {
                $this->complete($organization, $actor, $job);
            }
        });
    }

    public function confirm(Organization $organization, User $actor, MaintenanceRequest $job): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        DB::transaction(function () use ($organization, $actor, $job): void {
            $job = $this->locked($organization, $actor, $job);
            $this->require($job->requires_manager_confirmation, __('This job does not require manager confirmation.'));
            if ($job->status === 'completed' && $job->confirmed_at !== null) {
                return;
            }
            $this->require($job->status !== 'cancelled' && $job->submitted_at !== null, __('The job must be submitted before confirmation.'));
            $this->complete($organization, $actor, $job);
        });
    }

    /** @param array<string, mixed> $input */
    public function updateStatus(Organization $organization, User $actor, MaintenanceRequest $job, array $input): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        DB::transaction(function () use ($organization, $actor, $job, $input): void {
            $job = $this->locked($organization, $actor, $job);
            $status = (string) $input['status'];
            $this->require(in_array($status, ['open', 'in_progress', 'on_hold', 'completed', 'cancelled'], true), __('Invalid job status.'));
            $assignee = array_key_exists('assigned_to', $input) ? $input['assigned_to'] : $job->assigned_to;
            if ($assignee !== null) {
                $this->require($organization->users()->whereKey($assignee)->exists(), __('Assignee must belong to this organization.'), 'assigned_to');
            }
            $before = ['status' => $job->status, 'assigned_to' => $job->assigned_to];
            $reopen = (in_array($job->status, ['completed', 'cancelled'], true) || $job->submitted_at !== null) && in_array($status, ['open', 'in_progress', 'on_hold'], true);
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reopen || ($status === 'cancelled' && $job->status !== 'cancelled')) {
                $this->require($reason !== '' && mb_strlen($reason) <= 2000, __('A reason of up to 2,000 characters is required.'), 'reason');
            }
            if ($job->submitted_at !== null && ! $reopen && $assignee != $job->assigned_to) {
                $this->require(false, __('Reopen the submitted job before changing its assignment.'), 'assigned_to');
            }
            if ($status === 'completed' && $job->status !== 'completed') {
                $this->require($job->status !== 'cancelled', __('Reopen the cancelled job before completing it.'));
                $job->assigned_to = $assignee;
                $this->complete($organization, $actor, $job);

                return;
            }
            $changes = ['status' => $status, 'assigned_to' => $assignee];
            if ($reopen || ($status === 'cancelled' && $job->status !== 'cancelled')) {
                $changes += ['completed_at' => null, 'completed_by' => null, 'submitted_at' => null, 'submitted_by' => null, 'confirmed_at' => null, 'confirmed_by' => null];
            }
            $job->update($changes);
            $this->sla->transition($job, $before['status'], $reopen, $reason);
            $this->audit->handle($organization, $actor, $reopen ? 'operations.job.reopened' : ($status === 'cancelled' && $before['status'] !== 'cancelled' ? 'operations.job.cancelled' : 'operations.maintenance.status_updated'), $job, ['before' => $before, 'status' => $status, 'assigned_to' => $assignee, 'reason' => $reason]);
        });
    }

    private function locked(Organization $organization, User $actor, MaintenanceRequest $job): MaintenanceRequest
    {
        $job = MaintenanceRequest::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($job->id);
        $this->access->authorizeView($organization, $actor, $job);

        return $job;
    }

    private function checklist(Organization $organization, MaintenanceRequest $job): void
    {
        $this->require(! $job->jobTasks()->where('organization_id', $organization->id)->where('is_required', true)->whereNull('completed_at')->exists(), __('Complete all required checklist items first.'), 'checklist');
    }

    private function complete(Organization $organization, User $actor, MaintenanceRequest $job): void
    {
        $this->checklist($organization, $job);
        if ($job->requires_manager_confirmation) {
            Gate::forUser($actor)->authorize('manageOperations', $organization);
            $this->require($job->submitted_at !== null, __('This job requires submission and manager confirmation.'));
        }
        $before = $job->status;
        $job->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $actor->id, 'confirmed_at' => $job->requires_manager_confirmation ? now() : null, 'confirmed_by' => $job->requires_manager_confirmation ? $actor->id : null]);
        $this->sla->transition($job, $before, false, '');
        $this->audit->handle($organization, $actor, $job->requires_manager_confirmation ? 'operations.job.confirmed' : 'operations.job.completed', $job, ['before' => $before, 'status' => 'completed', 'assigned_to' => $job->assigned_to, 'submitted_by' => $job->submitted_by]);
    }

    private function require(bool $condition, string $message, string $field = 'status'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => __($message)]);
        }
    }
}
