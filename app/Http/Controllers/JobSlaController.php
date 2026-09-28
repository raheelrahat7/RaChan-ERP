<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Actions\ManageJobSla;
use App\Domain\Operations\Queries\ServiceHelpdesk;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JobSlaController extends Controller
{
    public function index(Request $request, ServiceHelpdesk $helpdesk): Response
    {
        $filters = $request->validate(['status' => ['nullable', 'in:open,in_progress,on_hold,completed,cancelled'], 'search' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('operations/Helpdesk', $helpdesk->for($this->organization($request), $request->user(), $filters));
    }

    public function enable(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobSla $sla): RedirectResponse
    {
        $input = $request->validate(['days' => ['required', 'string', 'max:30'], 'start' => ['required', 'date_format:H:i'], 'end' => ['required', 'date_format:H:i'], 'holidays' => ['nullable', 'string', 'max:5000'], 'response_minutes' => ['required', 'integer', 'min:1', 'max:525600'], 'resolution_minutes' => ['required', 'integer', 'min:1', 'max:525600']]);
        $sla->enable($this->organization($request), $request->user(), $maintenanceRequest, $input);

        return back();
    }

    public function acknowledge(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobSla $sla): RedirectResponse
    {
        $sla->acknowledge($this->organization($request), $request->user(), $maintenanceRequest);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }
}
