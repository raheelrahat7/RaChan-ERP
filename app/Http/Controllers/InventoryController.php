<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\RealEstate\Queries\BuildingSkyline;
use App\Models\Building;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $this->organization($request);
        $this->authorize('viewInventory', $organization);

        return Inertia::render('inventory/Index', [
            'properties' => Property::where('organization_id', $organization->id)->with('buildings:id,property_id,name')->latest()->get(['id', 'name', 'type', 'city']),
            'units' => Unit::where('organization_id', $organization->id)->with(['property:id,name', 'building:id,name'])->latest()->get()->map(fn (Unit $unit) => [...$unit->only('id', 'number', 'floor', 'type', 'area', 'area_unit', 'status', 'asking_price', 'currency'), 'property' => $unit->property?->only('id', 'name'), 'building' => $unit->building?->only('id', 'name')]),
            'canManageInventory' => $request->user()->can('manageInventory', $organization),
        ]);
    }

    public function showProperty(Request $request, Property $property, BuildingSkyline $skyline): Response
    {
        $organization = $this->organization($request);
        abort_unless($property->organization_id === $organization->id, 404);
        $this->authorize('viewInventory', $organization);

        return Inertia::render('inventory/Property', [
            'property' => $property->only('id', 'name', 'type', 'address_line_1', 'city', 'description'),
            'buildings' => $property->buildings()->with('units:id,building_id,number,floor,type,status')->get(),
            'skylines' => $skyline->forProperty($organization, $property, $request->user()->can('viewFinance', $organization)),
            'units' => $property->units()->whereNull('building_id')->get(['id', 'number', 'floor', 'type', 'status']),
            'documents' => $property->documents()->whereIn('id', app(DocumentAccess::class)->query($organization, $request->user())->select('id'))->latest()->get(['id', 'name', 'mime_type', 'size']),
            'owners' => $property->owners()->orderBy('name')->get(['owners.id', 'name', 'email', 'phone']),
            'availableOwners' => Owner::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'canManageInventory' => $request->user()->can('manageInventory', $organization),
        ]);
    }

    public function skyline(Request $request, Building $building, BuildingSkyline $skyline): JsonResponse
    {
        $organization = $this->organization($request);
        abort_unless($building->organization_id === $organization->id, 404);
        $this->authorize('viewInventory', $organization);
        $property = Property::where('organization_id', $organization->id)->findOrFail($building->property_id);
        $record = collect($skyline->forProperty($organization, $property, $request->user()->can('viewFinance', $organization)))->firstWhere('building.id', $building->id);
        abort_unless($record !== null, 404);

        return response()->json($record);
    }

    public function showUnit(Request $request, Unit $unit): Response
    {
        $organization = $this->organization($request);
        abort_unless($unit->organization_id === $organization->id, 404);
        $this->authorize('viewInventory', $organization);

        return Inertia::render('inventory/Unit', [
            'unit' => $unit->load(['property:id,name', 'building:id,name'])->only('id', 'number', 'floor', 'type', 'status', 'area', 'area_unit', 'asking_price', 'currency', 'property', 'building'),
            'documents' => $unit->documents()->whereIn('id', app(DocumentAccess::class)->query($organization, $request->user())->select('id'))->latest()->get(['id', 'name', 'mime_type', 'size']),
            'canManageInventory' => $request->user()->can('manageInventory', $organization),
        ]);
    }

    public function storeProperty(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageInventory', $organization);
        $property = Property::create(['organization_id' => $organization->id, ...$request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('properties', 'name')->where('organization_id', $organization->id)],
            'type' => ['required', 'in:residential,commercial,mixed_use,land'],
            'city' => ['nullable', 'string', 'max:100'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
        ], ['name.unique' => 'A property with this name already exists in your organization. Select it when adding a unit, or choose a different property name.'])]);
        $audit->handle($organization, $request->user(), 'inventory.property.created', $property);

        return back();
    }

    public function storeUnit(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageInventory', $organization);
        $input = $request->validate(['property_id' => ['required', 'integer'], 'building_id' => ['nullable', 'integer'], 'floor' => ['nullable', 'string', 'max:8', 'regex:/^(?:G|P[1-9][0-9]?|[1-9][0-9]{0,2})$/'], 'number' => ['required', 'string', 'max:100'], 'type' => ['required', 'in:apartment,office,retail,warehouse,plot,other'], 'status' => ['required', 'in:available,reserved,leased,sold,unavailable'], 'area' => ['nullable', 'numeric', 'min:0'], 'asking_price' => ['nullable', 'numeric', 'min:0']]);
        $property = Property::where('organization_id', $organization->id)->findOrFail((int) $input['property_id']);
        $building = ($input['building_id'] ?? null) ? Building::where('organization_id', $organization->id)->where('property_id', $property->id)->findOrFail((int) $input['building_id']) : null;
        $this->validateFloor($building, $input['floor'] ?? null);
        $unit = Unit::create(['organization_id' => $organization->id, ...$input]);
        $audit->handle($organization, $request->user(), 'inventory.unit.created', $unit);

        return back();
    }

    public function updateUnitFloor(Request $request, Unit $unit, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($unit->organization_id === $organization->id, 404);
        $this->authorize('manageInventory', $organization);
        $input = $request->validate(['floor' => ['nullable', 'string', 'max:8', 'regex:/^(?:G|P[1-9][0-9]?|[1-9][0-9]{0,2})$/']]);
        $building = $unit->building_id === null ? null : Building::where('organization_id', $organization->id)->findOrFail($unit->building_id);
        $this->validateFloor($building, $input['floor'] ?? null);
        $before = $unit->floor;
        $unit->update(['floor' => $input['floor'] ?? null]);
        $audit->handle($organization, $request->user(), 'inventory.unit.floor_updated', $unit, ['before' => $before, 'after' => $unit->floor]);

        return back();
    }

    public function storeBuilding(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageInventory', $organization);
        $input = $request->validate([
            'property_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'floors' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);
        Property::where('organization_id', $organization->id)->findOrFail((int) $input['property_id']);
        $building = Building::create(['organization_id' => $organization->id, ...$input]);
        $audit->handle($organization, $request->user(), 'inventory.building.created', $building);

        return back();
    }

    public function updateUnitStatus(Request $request, Unit $unit, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($unit->organization_id === $organization->id, 404);
        $this->authorize('manageInventory', $organization);
        $status = $request->validate(['status' => ['required', 'in:available,reserved,leased,sold,unavailable']])['status'];
        $unit->update(['status' => $status]);
        $audit->handle($organization, $request->user(), 'inventory.unit.status_updated', $unit, ['status' => $status]);

        return back();
    }

    public function assignOwner(Request $request, Property $property, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($property->organization_id === $organization->id, 404);
        $this->authorize('manageInventory', $organization);
        $input = $request->validate([
            'owner_id' => ['required', 'integer'],
            'ownership_share' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);
        Owner::where('organization_id', $organization->id)->findOrFail((int) $input['owner_id']);
        $otherShares = $property->owners()->whereKeyNot($input['owner_id'])->sum('property_owner.ownership_share');
        abort_if($otherShares + (float) $input['ownership_share'] > 100, 422, 'Combined ownership cannot exceed 100%.');
        $property->owners()->syncWithoutDetaching([$input['owner_id'] => ['ownership_share' => $input['ownership_share']]]);
        $audit->handle($organization, $request->user(), 'inventory.property.owner_assigned', $property, $input);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }

    private function validateFloor(?Building $building, ?string $floor): void
    {
        if ($floor === null) {
            return;
        }
        if ($building === null || (ctype_digit($floor) && $building->floors !== null && (int) $floor > $building->floors)) {
            throw ValidationException::withMessages(['floor' => 'Choose a floor within the selected building.']);
        }
    }
}
