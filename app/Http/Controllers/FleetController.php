<?php

namespace App\Http\Controllers;

use App\Domain\Fleet\Actions\ManageFleet;
use App\Domain\Fleet\Models\FleetAssignment;
use App\Domain\Fleet\Models\FleetService;
use App\Domain\Fleet\Models\FleetVehicle;
use App\Domain\Fleet\Queries\FleetOverview;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FleetController extends Controller
{
    public function index(Request $request, FleetOverview $overview): Response
    {
        return Inertia::render('fleet/Index', $overview->index($this->org($request), $request->user()));
    }

    public function show(Request $request, FleetVehicle $vehicle, FleetOverview $overview): Response
    {
        return Inertia::render('fleet/Vehicle', $overview->vehicle($this->org($request), $request->user(), $vehicle));
    }

    public function store(Request $request, ManageFleet $fleet): RedirectResponse
    {
        $vehicle = $fleet->vehicle($this->org($request), $request->user(), $request->only(['property_id', 'fixed_asset_id', 'reference', 'plate', 'vin', 'make', 'model', 'year', 'odometer']));

        return redirect()->route('fleet.show', $vehicle);
    }

    public function assign(Request $request, FleetVehicle $vehicle, ManageFleet $fleet): RedirectResponse
    {
        $input = $request->validate(['user_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        $fleet->assign($this->org($request), $request->user(), $vehicle, (int) $input['user_id'], $input['reason']);

        return back();
    }

    public function returnVehicle(Request $request, FleetAssignment $assignment, ManageFleet $fleet): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $fleet->returnVehicle($this->org($request), $request->user(), $assignment, $input['reason']);

        return back();
    }

    public function service(Request $request, FleetVehicle $vehicle, ManageFleet $fleet): RedirectResponse
    {
        $fleet->service($this->org($request), $request->user(), $vehicle, $request->only(['vendor_id', 'maintenance_request_id', 'kind', 'description', 'due_on', 'operation_key']));

        return back();
    }

    public function complete(Request $request, FleetService $service, ManageFleet $fleet): RedirectResponse
    {
        $input = $request->validate(['odometer' => ['required', 'integer', 'min:0', 'max:999999999'], 'note' => ['required', 'string', 'max:2000']]);
        $fleet->complete($this->org($request), $request->user(), $service, (int) $input['odometer'], $input['note']);

        return back();
    }

    public function cancel(Request $request, FleetService $service, ManageFleet $fleet): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $fleet->cancel($this->org($request), $request->user(), $service, $input['reason']);

        return back();
    }

    public function status(Request $request, FleetVehicle $vehicle, ManageFleet $fleet): RedirectResponse
    {
        $input = $request->validate(['status' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000']]);
        $fleet->status($this->org($request), $request->user(), $vehicle, $input['status'], $input['reason']);

        return back();
    }

    private function org(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }
}
