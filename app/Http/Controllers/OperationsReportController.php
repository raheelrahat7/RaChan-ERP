<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Actions\ManageSavedReportFilters;
use App\Domain\Operations\Models\SavedReportFilter;
use App\Domain\Operations\Queries\OperationsReport;
use App\Domain\Operations\Queries\OperationsReportExport;
use App\Http\Requests\Operations\ReportRequest;
use App\Models\MaintenanceVendor;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsReportController extends Controller
{
    public function index(ReportRequest $request, OperationsReport $report): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $at = CarbonImmutable::now('UTC');
        $filters = $request->filters();

        return Inertia::render('operations/Reports', [
            'savedFilters' => SavedReportFilter::where('organization_id', $organization->id)->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'filters']),
            'filters' => $filters,
            'generatedAt' => $at->toIso8601String(),
            'timezone' => $organization->timezone,
            'summary' => $report->summary($organization, $request->user(), $filters, $at),
            'jobs' => $report->page($organization, $request->user(), $filters, $at),
            'properties' => Property::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'vendors' => MaintenanceVendor::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'members' => $organization->users()->when(! $request->user()->can('manageOperations', $organization), fn ($query) => $query->whereKey($request->user()->id))->orderBy('name')->get(['users.id', 'users.name']),
        ]);
    }

    public function saveFilter(Request $request, ManageSavedReportFilters $filters): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $filters->save($organization, $request->user(), $request->only(['name', 'filters']));

        return back();
    }

    public function removeFilter(Request $request, SavedReportFilter $filter, ManageSavedReportFilters $filters): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $filters->remove($organization, $request->user(), $filter);

        return back();
    }

    public function export(ReportRequest $request, OperationsReportExport $export): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $filters = $request->filters();
        $at = CarbonImmutable::now('UTC');

        return response()->streamDownload(function () use ($export, $organization, $request, $filters, $at): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new RuntimeException('Unable to open CSV output.');
            }
            try {
                $export->write($output, $organization, $request->user(), $filters, $at);
            } finally {
                fclose($output);
            }
        }, 'operations-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
