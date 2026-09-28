<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Operations\Models\JobSlaCycle;
use App\Domain\Operations\Services\BusinessHoursCalendar;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ManageJobSla
{
    public function __construct(private JobCardAccess $access, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function enable(Organization $organization, User $actor, MaintenanceRequest $job, array $input): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        DB::transaction(function () use ($organization, $actor, $job, $input): void {
            $job = $this->lock($organization, $actor, $job);
            $this->require(! in_array($job->status, ['completed', 'cancelled', 'on_hold'], true) && $job->submitted_at === null, __('Enable SLA tracking on an active, unsubmitted job.'));
            $this->require($this->latest($job) === null, __('This job already has an SLA policy. Existing commitments cannot be replaced.'));
            $days = [];
            foreach (explode(',', (string) $input['days']) as $day) {
                $this->require(preg_match('/\A[1-7]\z/', trim($day)) === 1, __('Working days must be comma-separated numbers from 1 (Monday) to 7 (Sunday).'));
                $days[(int) trim($day)] = ['start' => $input['start'], 'end' => $input['end']];
            }
            $holidays = array_values(array_filter(array_map('trim', explode(',', (string) ($input['holidays'] ?? '')))));
            try {
                $calendar = new BusinessHoursCalendar($organization->timezone, $days, $holidays);
                $calendar->deadline(now()->toImmutable(), (int) $input['resolution_minutes'] * 60);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['sla' => __($exception->getMessage())]);
            }
            $response = (int) $input['response_minutes'];
            $resolution = (int) $input['resolution_minutes'];
            $this->require($response >= 1 && $resolution >= $response && $resolution <= 525600, __('Targets must be positive, resolution at least response, and no more than 525,600 working minutes.'));
            $cycle = JobSlaCycle::create(['organization_id' => $organization->id, 'maintenance_request_id' => $job->id, 'cycle_number' => 1, 'timezone' => $organization->timezone, 'working_days' => $days, 'holidays' => $holidays, 'response_seconds' => $response * 60, 'resolution_seconds' => $resolution * 60, 'started_at' => now(), 'holds' => []]);
            $this->audit->handle($organization, $actor, 'operations.sla.enabled', $job, ['cycle_id' => $cycle->id, 'policy' => $cycle->only(['timezone', 'working_days', 'holidays', 'response_seconds', 'resolution_seconds'])]);
        });
    }

    public function acknowledge(Organization $organization, User $actor, MaintenanceRequest $job): void
    {
        DB::transaction(function () use ($organization, $actor, $job): void {
            $job = $this->lock($organization, $actor, $job);
            $cycle = $this->latest($job);
            $this->require($cycle !== null, __('This job has no SLA policy.'));
            if ($cycle->acknowledged_at !== null) {
                return;
            }
            $this->require($cycle->closed_at === null, __('The SLA cycle is closed.'));
            $cycle->update(['acknowledged_at' => now(), 'acknowledged_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'operations.sla.acknowledged', $job, ['cycle_id' => $cycle->id]);
        });
    }

    /** Called inside the job transaction while its row is locked. */
    public function transition(MaintenanceRequest $job, string $before, bool $reopen, string $reason): void
    {
        $cycle = $this->latest($job);
        if ($cycle === null) {
            return;
        }
        if ($reopen && $cycle->closed_at !== null) {
            $cycle = JobSlaCycle::create([...$cycle->only(['organization_id', 'maintenance_request_id', 'timezone', 'working_days', 'holidays', 'response_seconds', 'resolution_seconds']), 'cycle_number' => $cycle->cycle_number + 1, 'started_at' => now(), 'holds' => []]);
        }
        if ($cycle->closed_at !== null) {
            return;
        }
        if ($job->status === 'on_hold' && $cycle->held_at === null) {
            $this->require(trim($reason) !== '' && mb_strlen($reason) <= 2000, 'A manager-approved SLA hold requires a reason of up to 2,000 characters.');
            $cycle->update(['held_at' => now(), 'holds' => [...$cycle->holds, ['start' => now()->toIso8601String(), 'end' => now()->toIso8601String(), 'reason' => $reason]]]);
        }
        if ($job->status !== 'on_hold' && $cycle->held_at !== null) {
            $holds = $cycle->holds;
            $last = array_key_last($holds);
            if ($last !== null) {
                $holds[$last]['end'] = now()->toIso8601String();
            }
            $cycle->update(['held_at' => null, 'holds' => $holds]);
        }
        if (in_array($job->status, ['completed', 'cancelled'], true)) {
            $cycle->update(['closed_at' => now(), 'outcome' => $job->status]);
        }
    }

    public function latest(MaintenanceRequest $job): ?JobSlaCycle
    {
        return JobSlaCycle::where('organization_id', $job->organization_id)->where('maintenance_request_id', $job->id)->orderByDesc('cycle_number')->first();
    }

    private function lock(Organization $organization, User $actor, MaintenanceRequest $job): MaintenanceRequest
    {
        $job = MaintenanceRequest::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($job->id);
        $this->access->authorizeView($organization, $actor, $job);

        return $job;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['sla' => __($message)]);
        }
    }
}
