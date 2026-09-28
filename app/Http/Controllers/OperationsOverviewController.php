<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Queries\OperationsOverview;
use App\Http\Requests\Operations\OverviewRequest;
use App\Models\MaintenanceVendor;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsOverviewController extends Controller
{
    public function index(OverviewRequest $request, OperationsOverview $overview): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $filters = $request->filters();
        $at = CarbonImmutable::now('UTC');

        return Inertia::render('operations/Overview', [
            'filters' => $filters->toArray(),
            'generatedAt' => $at->toIso8601String(),
            'timezone' => $organization->timezone,
            'today' => $at->setTimezone($organization->timezone)->toDateString(),
            'throughDate' => $at->setTimezone($organization->timezone)->addDays(7)->toDateString(),
            'summary' => $overview->summary($organization, $filters, $at, $request->user()),
            'workload' => $overview->workload($organization, $filters, $at, $request->user()),
            'backlog' => $overview->backlog($organization, $filters, $at, $request->user())->paginate(20, ['*'], 'requests_page')->withQueryString(),
            'plans' => $overview->plans($organization, $filters, $at, $request->user())->paginate(20, ['*'], 'plans_page')->withQueryString(),
            'properties' => Property::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'vendors' => MaintenanceVendor::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(OverviewRequest $request, OperationsOverview $overview): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $filters = $request->filters();
        $at = CarbonImmutable::now('UTC');
        $summary = $overview->summary($organization, $filters, $at, $request->user());
        $workload = $overview->workload($organization, $filters, $at, $request->user());

        return response()->streamDownload(function () use ($organization, $filters, $at, $summary, $workload, $overview, $request): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new RuntimeException('Unable to open CSV output stream.');
            }
            try {
                fputcsv($output, ['Operations overview', 'Generated at UTC', $at->toIso8601String()]);
                fputcsv($output, ['Property ID', $filters->propertyId ?? 'All', 'Vendor ID', $filters->vendorId ?? 'All']);
                fputcsv($output, ['Timezone', $organization->timezone, 'Preventive plans through', $at->setTimezone($organization->timezone)->addDays(7)->toDateString()]);
                fputcsv($output, ['Metric', 'Count']);
                foreach ($summary as $key => $count) {
                    fputcsv($output, [$key, $count]);
                }
                fputcsv($output, []);
                fputcsv($output, ['Assignee', 'Active', 'Overdue', 'On hold']);
                foreach ($workload as $row) {
                    fputcsv($output, [$this->safeCell($row['assignee']), $row['active'], $row['overdue'], $row['on_hold']]);
                }
                fputcsv($output, []);
                fputcsv($output, ['Reference', 'Title', 'Property', 'Vendor', 'Assignee', 'Priority', 'Status', 'Due at UTC', 'Overdue']);
                foreach ($overview->backlog($organization, $filters, $at, $request->user())->lazy() as $row) {
                    fputcsv($output, array_map($this->safeCell(...), [$row->reference, $row->title, $row->property, $row->vendor, $row->assignee, $row->priority, $row->status, $row->due_at, $row->overdue ? 'Yes' : 'No']));
                }
                fputcsv($output, []);
                fputcsv($output, ['Preventive plan', 'Property', 'Vendor', 'Next due on']);
                foreach ($overview->plans($organization, $filters, $at, $request->user())->lazy() as $row) {
                    fputcsv($output, array_map($this->safeCell(...), [$row->title, $row->property, $row->vendor, $row->next_due_on]));
                }
            } finally {
                fclose($output);
            }
        }, 'operations-overview.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
