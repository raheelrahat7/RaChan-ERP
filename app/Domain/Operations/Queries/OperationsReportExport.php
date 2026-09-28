<?php

namespace App\Domain\Operations\Queries;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;

class OperationsReportExport
{
    public function __construct(private OperationsReport $report) {}

    /** @param resource $output
     * @param  array<string, mixed>  $filters
     */
    public function write($output, Organization $organization, User $actor, array $filters, CarbonImmutable $at): void
    {
        $summary = $this->report->summary($organization, $actor, $filters, $at);
        $this->writeRow($output, ['Operations report', 'Generated at UTC', $at->toIso8601String(), 'Timezone', $organization->timezone]);
        $this->writeRow($output, ['Creation dates select a job cohort; status and aging are current. Costs are separate by currency. Preventive results cover generated jobs due by today.']);
        foreach ($filters as $key => $value) {
            $this->writeRow($output, ['Filter', $key, $value ?? 'All']);
        }
        $this->writeRow($output, ['Section', 'Metric', 'Value']);
        foreach (['statuses', 'aging', 'preventive', 'sla'] as $section) {
            foreach ($summary[$section] as $metric => $value) {
                $this->writeRow($output, [$section, $metric, $value]);
            }
        }
        $this->writeRow($output, []);
        $this->writeRow($output, ['Assignee', 'Active', 'Completed', 'On hold']);
        foreach ($summary['workload'] as $row) {
            $this->writeRow($output, [$row['assignee'], $row['active'], $row['completed'], $row['on_hold']]);
        }
        $this->writeRow($output, []);
        $this->writeRow($output, ['Currency', 'Estimated', 'Manual actual', 'Labor', 'Material', 'Recorded job-card total', 'Missing estimates', 'Missing actuals']);
        foreach ($summary['costs'] as $row) {
            $this->writeRow($output, array_values($row));
        }
        $this->writeRow($output, []);
        $this->writeRow($output, ['Reference', 'Title', 'Property', 'Vendor', 'Assignee', 'Priority', 'Status', 'Created at UTC', 'Completed at UTC', 'Active age days', 'Aging bucket', 'Currency', 'Estimated', 'Manual actual', 'Labor', 'Material', 'Recorded job-card total', 'Preventive due date', 'Preventive result']);
        foreach ($this->report->rows($organization, $actor, $filters, $at) as $row) {
            $this->writeRow($output, [$row['reference'], $row['title'], $row['property'], $row['vendor'], $row['assignee'], $row['priority'], $row['status'], $row['created_at'], $row['completed_at']?->toIso8601String(), $row['age_days'], $row['aging_bucket'], $row['currency'], $row['estimated_cost'], $row['actual_cost'], $row['labor_cost'], $row['material_cost'], $row['recorded_cost'], $row['preventive_due_on'], $row['preventive_result']]);
        }
        $this->writeRow($output, []);
        $this->writeRow($output, ['Job reference', 'SLA cycle', 'Outcome', 'Started at', 'Acknowledged at', 'Closed at', 'Response target seconds', 'Response working seconds', 'Response breached', 'Resolution target seconds', 'Resolution working seconds', 'Resolution breached']);
        foreach ($this->report->rows($organization, $actor, $filters, $at) as $row) {
            foreach ($row['sla_cycles'] as $cycle) {
                $this->writeRow($output, [$row['reference'], $cycle['cycle_number'], $cycle['outcome'] ?? 'active', $cycle['started_at'], $cycle['acknowledged_at'], $cycle['closed_at'], $cycle['response_seconds'], $cycle['response_elapsed_seconds'], $cycle['response_breached'] ? 'Yes' : 'No', $cycle['resolution_seconds'], $cycle['resolution_elapsed_seconds'], $cycle['resolution_breached'] ? 'Yes' : 'No']);
            }
        }
    }

    /** @param resource $output
     * @param  list<mixed>  $values
     */
    private function writeRow($output, array $values): void
    {
        fputcsv($output, array_map(function ($value): string {
            $value = (string) ($value ?? '');

            return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
        }, $values), ',', '"', '');
    }
}
