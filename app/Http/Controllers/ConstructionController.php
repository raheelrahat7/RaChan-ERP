<?php

namespace App\Http\Controllers;

use App\Domain\Construction\Actions\ManageConstruction;
use App\Domain\Construction\Models\BoqItem;
use App\Domain\Construction\Models\BoqProgress;
use App\Domain\Construction\Models\ConstructionProject;
use App\Domain\Construction\Models\ContractorClaim;
use App\Domain\Construction\Queries\ConstructionOverview;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConstructionController extends Controller
{
    public function index(Request $request, ConstructionOverview $overview): Response
    {
        return Inertia::render('construction/Index', $overview->index($this->org($request), $request->user()));
    }

    public function show(Request $request, ConstructionProject $project, ConstructionOverview $overview): Response
    {
        return Inertia::render('construction/Project', $overview->project($this->org($request), $request->user(), $project));
    }

    public function store(Request $request, ManageConstruction $construction): RedirectResponse
    {
        $project = $construction->project($this->org($request), $request->user(), $request->only(['property_id', 'reference', 'title', 'budget']));

        return redirect()->route('construction.show', $project);
    }

    public function item(Request $request, ConstructionProject $project, ManageConstruction $construction): RedirectResponse
    {
        $construction->item($this->org($request), $request->user(), $project, $request->only(['reference', 'description', 'unit', 'quantity', 'unit_rate']));

        return back();
    }

    public function progress(Request $request, BoqItem $item, ManageConstruction $construction): RedirectResponse
    {
        $construction->progress($this->org($request), $request->user(), $item, $request->only(['quantity', 'note', 'operation_key']));

        return back();
    }

    public function voidProgress(Request $request, BoqProgress $progress, ManageConstruction $construction): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $construction->voidProgress($this->org($request), $request->user(), $progress, $input['reason']);

        return back();
    }

    public function claim(Request $request, ConstructionProject $project, ManageConstruction $construction): RedirectResponse
    {
        $construction->claim($this->org($request), $request->user(), $project, $request->only(['vendor_id', 'claimed_on', 'reason', 'operation_key', 'lines']));

        return back();
    }

    public function approve(Request $request, ContractorClaim $claim, ManageConstruction $construction): RedirectResponse
    {
        $construction->decide($this->org($request), $request->user(), $claim, true);

        return back();
    }

    public function reject(Request $request, ContractorClaim $claim, ManageConstruction $construction): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $construction->decide($this->org($request), $request->user(), $claim, false, $input['reason']);

        return back();
    }

    public function bill(Request $request, ContractorClaim $claim, ManageConstruction $construction): RedirectResponse
    {
        $construction->bill($this->org($request), $request->user(), $claim, $request->only(['bill_date', 'due_on', 'accounting_treatment', 'vat_treatment', 'input_vat_recoverable']));

        return back();
    }

    public function status(Request $request, ConstructionProject $project, ManageConstruction $construction): RedirectResponse
    {
        $input = $request->validate(['status' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000']]);
        $construction->status($this->org($request), $request->user(), $project, $input['status'], $input['reason']);

        return back();
    }

    private function org(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }
}
