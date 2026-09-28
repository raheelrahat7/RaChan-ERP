<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Actions\ManageMaintenance;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVendor;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceRequestController extends Controller
{
    public function index(Request $request, JobCardAccess $access): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewOperations', $org);

        $filter = $request->string('filter')->toString();
        $requests = $access->scope(MaintenanceRequest::query(), $org, $request->user())
            ->when($filter === 'open', fn ($query) => $query->whereIn('status', ['open', 'in_progress', 'on_hold']))
            ->when($filter === 'overdue', fn ($query) => $query->whereIn('status', ['open', 'in_progress'])->where('due_at', '<', now()))
            ->when($filter === 'mine', fn ($query) => $query->where('assigned_to', $request->user()->id))
            ->when($filter === 'completed', fn ($query) => $query->where('status', 'completed'))
            ->latest()->get(['id', 'reference', 'title', 'priority', 'status', 'due_at', 'assigned_to', 'vendor_id', 'estimated_cost', 'actual_cost', 'currency', 'requires_manager_confirmation', 'submitted_at']);

        return Inertia::render('operations/Maintenance', ['requests' => $requests, 'filter' => $filter, 'properties' => Property::where('organization_id', $org->id)->get(['id', 'name']), 'vendors' => MaintenanceVendor::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name', 'trade']), 'members' => $org->users()->orderBy('name')->get(['users.id', 'name']), 'canManageOperations' => $request->user()->can('manageOperations', $org)]);
    }

    public function store(Request $request, ManageMaintenance $maintenance): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('manageOperations', $org);
        $input = $request->validate(['property_id' => ['required', 'integer'], 'unit_id' => ['nullable', 'integer'], 'vendor_id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:255'], 'priority' => ['required', 'in:low,medium,high,urgent'], 'due_at' => ['nullable', 'date'], 'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'requires_manager_confirmation' => ['sometimes', 'boolean']]);
        $maintenance->create($org, $request->user(), $input);

        return back();
    }

    public function updateStatus(Request $request, MaintenanceRequest $maintenanceRequest, ManageMaintenance $maintenance): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $maintenanceRequest->organization_id === $org->id, 404);
        $this->authorize('manageOperations', $org);
        $input = $request->validate(['status' => ['required', 'in:open,in_progress,on_hold,completed,cancelled'], 'assigned_to' => ['nullable', 'integer'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $maintenance->updateStatus($org, $request->user(), $maintenanceRequest, $input);

        return back();
    }

    public function storeVendor(Request $request, ManageMaintenance $maintenance): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('manageOperations', $org);
        $input = $request->validate(['name' => ['required', 'string', 'max:255'], 'trade' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:50']]);
        $maintenance->createVendor($org, $request->user(), $input);

        return back();
    }

    public function updateWorkOrder(Request $request, MaintenanceRequest $maintenanceRequest, ManageMaintenance $maintenance): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $maintenanceRequest->organization_id === $org->id, 404);
        $this->authorize('manageOperations', $org);
        $input = $request->validate(['vendor_id' => ['nullable', 'integer'], 'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'actual_cost' => ['nullable', 'numeric', 'min:0']]);
        $maintenance->updateWorkOrder($org, $request->user(), $maintenanceRequest, $input);

        return back();
    }
}
