<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Actions\ManagePrivateReportSchedules;
use App\Domain\Operations\Models\PrivateReportDelivery;
use App\Domain\Operations\Models\PrivateReportSchedule;
use App\Domain\Operations\Models\SavedReportFilter;
use App\Domain\Operations\Services\PrivateReportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateReportsController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        Gate::authorize('viewOperations', $org);

        return Inertia::render('operations/ScheduledReports', ['timezone' => $org->timezone, 'schedules' => PrivateReportSchedule::where('organization_id', $org->id)->where('user_id', $request->user()->id)->latest()->get(), 'filters' => SavedReportFilter::where('organization_id', $org->id)->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'filters']), 'deliveries' => PrivateReportDelivery::where('organization_id', $org->id)->where('user_id', $request->user()->id)->latest()->paginate(20)->through(fn (PrivateReportDelivery $d): array => $d->only(['id', 'format', 'status', 'scheduled_for', 'generated_at', 'failure_reason']))]);
    }

    public function store(Request $request, ManagePrivateReportSchedules $schedules): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $schedules->create($org, $request->user(), $request->only(['name', 'format', 'frequency', 'local_time', 'weekday', 'filters']));

        return back();
    }

    public function toggle(Request $request, int $schedule, ManagePrivateReportSchedules $schedules): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $schedules->enabled($org, $request->user(), $schedule, (bool) $data['enabled']);

        return back();
    }

    public function download(Request $request, PrivateReportDelivery $delivery, PrivateReportAccess $access): StreamedResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $access->authorize($org, $request->user(), $delivery);
        abort_unless($delivery->path !== null && Storage::disk('local')->exists($delivery->path), 404);

        return Storage::disk('local')->download($delivery->path, 'operations-report-'.$delivery->id.'.'.$delivery->format, ['Content-Type' => $delivery->format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
