<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Actions\ManageAmcCoverage;
use App\Domain\Operations\Models\AmcContract;
use App\Domain\Operations\Queries\AmcOverview;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AmcController extends Controller
{
    public function index(Request $request, AmcOverview $overview): Response
    {
        return Inertia::render('operations/Amc', $overview->for($this->organization($request), $request->user()));
    }

    public function equipment(Request $request, ManageAmcCoverage $coverage): RedirectResponse
    {
        $coverage->equipment($this->organization($request), $request->user(), $request->only(['property_id', 'reference', 'name', 'serial_number']));

        return back();
    }

    public function store(Request $request, ManageAmcCoverage $coverage): RedirectResponse
    {
        $coverage->contract($this->organization($request), $request->user(), $request->only(['vendor_id', 'reference', 'title', 'starts_on', 'ends_on', 'service_limit', 'terms', 'property_ids', 'equipment_ids']));

        return back();
    }

    public function visit(Request $request, AmcContract $contract, ManageAmcCoverage $coverage): RedirectResponse
    {
        $coverage->visit($this->organization($request), $request->user(), $contract, $request->only(['maintenance_request_id', 'operations_equipment_id', 'service_on', 'override_reason']));

        return back();
    }

    public function cancel(Request $request, AmcContract $contract, ManageAmcCoverage $coverage): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $coverage->cancel($this->organization($request), $request->user(), $contract, $input['reason']);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }
}
