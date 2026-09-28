<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Models\JobSlaCycle;
use App\Domain\Operations\Services\JobCardAccess;
use App\Domain\Operations\Services\JobCostAmount;
use App\Domain\Operations\Services\JobSlaClock;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class OperationsReport
{
    public function __construct(private JobCardAccess $access, private JobCostAmount $amounts, private JobSlaClock $clock) {}

    /** @param array<string, mixed> $filters
     * @return Builder<MaintenanceRequest>
     */
    private function query(Organization $organization, User $actor, array $filters): Builder
    {
        $query = $this->access->scope(MaintenanceRequest::query(), $organization, $actor);
        foreach (['property_id', 'vendor_id', 'assigned_to', 'status', 'priority'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['created_from'], $organization->timezone)->startOfDay()->utc());
        }
        if (isset($filters['created_to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['created_to'], $organization->timezone)->addDay()->startOfDay()->utc());
        }

        return $query->with([
            'jobCostLines' => fn ($query) => $query->where('organization_id', $organization->id)->whereNull('voided_at'),
            'slaCycles' => fn ($query) => $query->where('organization_id', $organization->id)->orderBy('cycle_number'),
        ]);
    }

    /** @return array<string, array<int, string>> */
    private function labels(Organization $organization): array
    {
        return [
            'assignees' => $organization->users()->pluck('users.name', 'users.id')->all(),
            'properties' => Property::where('organization_id', $organization->id)->pluck('name', 'id')->all(),
            'vendors' => MaintenanceVendor::where('organization_id', $organization->id)->pluck('name', 'id')->all(),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return iterable<array<string, mixed>>
     */
    public function rows(Organization $organization, User $actor, array $filters, CarbonImmutable $at): iterable
    {
        $labels = $this->labels($organization);
        foreach ($this->query($organization, $actor, $filters)->orderBy('id')->lazyById(200) as $job) {
            yield $this->row($job, $organization, $at, $labels);
        }
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(Organization $organization, User $actor, array $filters, CarbonImmutable $at): LengthAwarePaginator
    {
        $labels = $this->labels($organization);

        return $this->query($organization, $actor, $filters)->orderByDesc('id')->paginate(25)->withQueryString()
            ->through(fn (MaintenanceRequest $job): array => $this->row($job, $organization, $at, $labels));
    }

    /** @param array<string, array<int, string>> $labels
     * @return array<string, mixed>
     */
    private function row(MaintenanceRequest $job, Organization $organization, CarbonImmutable $at, array $labels): array
    {
        $active = in_array($job->status, ['open', 'in_progress', 'on_hold'], true);
        $age = $active ? max(0, (int) floor($job->created_at->diffInSeconds($at, false) / 86400)) : null;
        $labor = 0;
        $material = 0;
        foreach ($job->jobCostLines as $line) {
            if ($line->category === 'labor') {
                $labor += $this->amounts->hundredths($line->amount);
            } else {
                $material += $this->amounts->hundredths($line->amount);
            }
        }
        $preventive = null;
        $dueOn = $job->preventive_due_on?->format('Y-m-d');
        if ($job->preventive_maintenance_plan_id !== null && $dueOn !== null && $dueOn <= $at->setTimezone($organization->timezone)->toDateString()) {
            $deadline = CarbonImmutable::parse($dueOn, $organization->timezone)->addDay()->startOfDay()->utc();
            $preventive = match (true) {
                $job->status === 'cancelled' => 'cancelled',
                $job->status === 'completed' && $job->completed_at === null => 'completion_date_unknown',
                $job->status === 'completed' => CarbonImmutable::parse($job->completed_at)->lt($deadline) ? 'completed_on_time' : 'completed_late',
                $at->lt($deadline) => 'due_today',
                default => 'outstanding_overdue',
            };
        }

        return [
            ...$job->only(['id', 'reference', 'title', 'status', 'priority', 'currency', 'assigned_to', 'preventive_due_on']),
            'preventive_due_on' => $dueOn,
            'property' => $labels['properties'][$job->property_id] ?? 'Unavailable property',
            'vendor' => $job->vendor_id === null ? 'No vendor' : ($labels['vendors'][$job->vendor_id] ?? 'Unavailable vendor'),
            'assignee' => $job->assigned_to === null ? 'Unassigned' : ($labels['assignees'][$job->assigned_to] ?? 'Unavailable member'),
            'created_at' => $job->created_at->toIso8601String(),
            'completed_at' => $job->completed_at,
            'age_days' => $age,
            'aging_bucket' => $age === null ? null : match (true) {
                $age <= 2 => '0–2 days', $age <= 7 => '3–7 days', $age <= 30 => '8–30 days', default => '31+ days'
            },
            'estimated_cost' => $job->estimated_cost,
            'actual_cost' => $job->actual_cost,
            'labor_cost' => $this->amounts->format($labor),
            'material_cost' => $this->amounts->format($material),
            'recorded_cost' => $this->amounts->format($labor + $material),
            'preventive_result' => $preventive,
            'sla_cycles' => $job->slaCycles->map(fn (JobSlaCycle $cycle): array => $this->clock->summary($cycle, $at))->all(),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(Organization $organization, User $actor, array $filters, CarbonImmutable $at): array
    {
        $statuses = array_fill_keys(['open', 'in_progress', 'on_hold', 'completed', 'cancelled'], 0);
        $aging = array_fill_keys(['0–2 days', '3–7 days', '8–30 days', '31+ days'], 0);
        $costs = [];
        $workload = [];
        $preventive = array_fill_keys(['due_today', 'outstanding_overdue', 'completed_on_time', 'completed_late', 'completion_date_unknown', 'cancelled'], 0);
        $sla = array_fill_keys(['active_cycles', 'completed_cycles', 'cancelled_cycles', 'active_response_breaches', 'active_resolution_breaches', 'completed_response_breaches', 'completed_resolution_breaches'], 0);
        foreach ($this->rows($organization, $actor, $filters, $at) as $row) {
            $statuses[$row['status']]++;
            if ($row['aging_bucket'] !== null) {
                $aging[$row['aging_bucket']]++;
            }
            $key = $row['assigned_to'] ?? 'unassigned';
            $workload[$key] ??= ['assignee_id' => $row['assigned_to'], 'assignee' => $row['assignee'], 'active' => 0, 'completed' => 0, 'on_hold' => 0];
            $workload[$key]['active'] += $row['age_days'] === null ? 0 : 1;
            $workload[$key]['completed'] += $row['status'] === 'completed' ? 1 : 0;
            $workload[$key]['on_hold'] += $row['status'] === 'on_hold' ? 1 : 0;
            $currency = $row['currency'];
            $costs[$currency] ??= ['currency' => $currency, 'estimated_cost' => 0, 'actual_cost' => 0, 'labor_cost' => 0, 'material_cost' => 0, 'recorded_cost' => 0, 'missing_estimates' => 0, 'missing_actuals' => 0];
            foreach (['estimated_cost', 'actual_cost', 'labor_cost', 'material_cost', 'recorded_cost'] as $field) {
                $costs[$currency][$field] += $row[$field] === null ? 0 : $this->amounts->hundredths($row[$field]);
            }
            $costs[$currency]['missing_estimates'] += $row['estimated_cost'] === null ? 1 : 0;
            $costs[$currency]['missing_actuals'] += $row['actual_cost'] === null ? 1 : 0;
            if ($row['preventive_result'] !== null) {
                $preventive[$row['preventive_result']]++;
            }
            foreach ($row['sla_cycles'] as $cycle) {
                $state = $cycle['outcome'] === 'cancelled' ? 'cancelled' : ($cycle['closed_at'] === null ? 'active' : 'completed');
                $sla[$state.'_cycles']++;
                if ($state !== 'cancelled') {
                    foreach (['response', 'resolution'] as $kind) {
                        $sla[$state.'_'.$kind.'_breaches'] += $cycle[$kind.'_breached'] ? 1 : 0;
                    }
                }
            }
        }
        ksort($costs);
        foreach ($costs as &$cost) {
            foreach (['estimated_cost', 'actual_cost', 'labor_cost', 'material_cost', 'recorded_cost'] as $field) {
                $cost[$field] = $this->amounts->format((int) $cost[$field]);
            }
        }
        unset($cost);
        $completed = $preventive['completed_on_time'] + $preventive['completed_late'];

        return ['statuses' => $statuses, 'aging' => $aging, 'workload' => array_values($workload), 'costs' => array_values($costs), 'preventive' => [...$preventive, 'completed_on_time_percent' => $completed === 0 ? null : round($preventive['completed_on_time'] / $completed * 100, 1)], 'sla' => $sla];
    }
}
