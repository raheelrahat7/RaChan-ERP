<?php

namespace App\Http\Controllers;

use App\Domain\RealEstate\Actions\ManageOffPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OffPlanController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $projects = DB::table('offplan_projects as project')->join('offplan_developers as developer', 'developer.id', '=', 'project.developer_id')
            ->where('project.organization_id', $org->id)->select('project.*', 'developer.name as developer_name')->orderBy('project.name')->paginate(30);

        return Inertia::render('real-estate/OffPlan', [
            'projects' => $projects,
            'developers' => DB::table('offplan_developers')->where('organization_id', $org->id)->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('manageCrm', $org),
        ]);
    }

    public function show(Request $request, int $project): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $record = DB::table('offplan_projects')->where('organization_id', $org->id)->where('id', $project)->first();
        abort_unless($record !== null, 404);

        return Inertia::render('real-estate/OffPlanProject', [
            'project' => $record,
            'units' => DB::table('offplan_units')->where('organization_id', $org->id)->where('project_id', $project)->orderBy('number')->paginate(50),
            'milestones' => DB::table('offplan_payment_milestones')->where('organization_id', $org->id)->where('project_id', $project)->orderBy('sequence')->get(),
            'deals' => DB::table('offplan_deals')->where('organization_id', $org->id)->where('project_id', $project)
                ->when(! $request->user()->can('manageCrm', $org), fn ($query) => $query->whereRaw('1 = 0'))
                ->orderByDesc('id')->limit(50)->get(),
            'canManage' => $request->user()->can('manageCrm', $org),
        ]);
    }

    public function storeProject(Request $request, ManageOffPlan $offplan): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $input = $request->validate([
            'developer_id' => ['required', 'integer'], 'cost_centre_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'],
            'emirate' => ['required', 'string', 'max:30'], 'location' => ['nullable', 'string', 'max:255'],
            'completion_on' => ['nullable', 'date_format:Y-m-d'], 'commission_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
        ]);
        $offplan->project($org, $request->user(), $input);

        return back();
    }

    public function storeUnit(Request $request, int $project, ManageOffPlan $offplan): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $input = $request->validate(['number' => ['required', 'string', 'max:50'], 'type' => ['nullable', 'string', 'max:40'],
            'area_sqft' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'], 'price_aed' => ['required', 'numeric', 'gt:0', 'decimal:0,2']]);
        $offplan->unit($org, $request->user(), $project, $input);

        return back();
    }

    public function storeMilestone(Request $request, int $project, ManageOffPlan $offplan): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $input = $request->validate(['sequence' => ['required', 'integer', 'between:1,999'], 'label' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'gt:0', 'lte:100', 'decimal:0,2'], 'due_on' => ['nullable', 'date_format:Y-m-d']]);
        $offplan->milestone($org, $request->user(), $project, $input);

        return back();
    }

    public function storeDeal(Request $request, ManageOffPlan $offplan): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $input = $request->validate(['unit_id' => ['required', 'integer'], 'lead_id' => ['required', 'integer'],
            'reference' => ['required', 'string', 'max:50'], 'price_aed' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:3000']]);
        $offplan->deal($org, $request->user(), $input);

        return back();
    }

    public function status(Request $request, int $deal, ManageOffPlan $offplan): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $input = $request->validate(['status' => ['required', 'in:reserved,contracted,cancelled'], 'contracted_on' => ['nullable', 'date_format:Y-m-d']]);
        $offplan->status($org, $request->user(), $deal, $input['status'], $input['contracted_on'] ?? null);

        return back();
    }
}
