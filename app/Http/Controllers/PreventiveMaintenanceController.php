<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Actions\ManagePreventiveMaintenance;
use App\Models\MaintenanceVendor;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PreventiveMaintenanceController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewOperations', $organization);

        return Inertia::render('operations/PreventiveMaintenance', [
            'plans' => PreventiveMaintenancePlan::where('organization_id', $organization->id)->when(! $request->user()->can('manageOperations', $organization), fn ($query) => $query->whereRaw('1 = 0'))->with(['property:id,name', 'vendor:id,name', 'occurrences' => fn ($query) => $query->where('organization_id', $organization->id)->select(['id', 'preventive_maintenance_plan_id', 'reference', 'preventive_due_on', 'status'])->orderByDesc('preventive_due_on')->limit(20)])->orderBy('next_due_on')->get(),
            'properties' => Property::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'vendors' => MaintenanceVendor::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'today' => CarbonImmutable::now($organization->timezone)->toDateString(),
            'canManage' => $request->user()->can('manageOperations', $organization),
        ]);
    }

    public function store(Request $request, ManagePreventiveMaintenance $maintenance): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageOperations', $organization);
        $input = $request->validate(['property_id' => ['required', 'integer'], 'unit_id' => ['nullable', 'integer'], 'vendor_id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:255'], 'frequency_days' => ['required', 'integer', 'min:1', 'max:3650'], 'next_due_on' => ['required', 'date']]);
        $maintenance->create($organization, $request->user(), $input);

        return back();
    }

    public function automation(Request $request, PreventiveMaintenancePlan $plan, ManagePreventiveMaintenance $maintenance): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $plan->organization_id === $organization->id, 404);
        $this->authorize('manageOperations', $organization);
        $input = $request->validate(['auto_generate_enabled' => ['required', 'boolean'], 'requires_manager_confirmation' => ['required', 'boolean'], 'is_active' => ['required', 'boolean']]);
        $maintenance->configureAutomation($organization, $request->user(), $plan, $input);

        return back();
    }

    public function generate(Request $request, PreventiveMaintenancePlan $plan, ManagePreventiveMaintenance $maintenance): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $plan->organization_id === $organization->id, 404);
        $this->authorize('manageOperations', $organization);
        $input = $request->validate(['due_on' => ['required', 'date_format:Y-m-d'], 'requires_manager_confirmation' => ['sometimes', 'boolean']]);
        $maintenance->generate($organization, $request->user(), $plan, $input['due_on'], (bool) ($input['requires_manager_confirmation'] ?? false));

        return back();
    }
}
