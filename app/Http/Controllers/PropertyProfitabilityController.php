<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Models\VendorBill;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PropertyProfitabilityController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);

        $properties = Property::where('organization_id', $organization->id)->with('owners:id,name')->orderBy('name')->get();
        $propertyIds = $properties->pluck('id');
        $unitPropertyIds = Unit::where('organization_id', $organization->id)->whereIn('property_id', $propertyIds)->pluck('property_id', 'id');
        $activeRentByProperty = Lease::where('organization_id', $organization->id)->where('status', 'active')->whereIn('unit_id', $unitPropertyIds->keys())->get(['unit_id', 'rent_amount'])->groupBy(fn (Lease $lease) => (int) $unitPropertyIds[$lease->unit_id])->map(fn ($leases) => (float) $leases->sum('rent_amount'));
        $vendorBillsByProperty = VendorBill::where('organization_id', $organization->id)->whereIn('property_id', $propertyIds)->whereIn('status', ['posted', 'partial', 'paid'])->selectRaw('property_id, sum(total) as total')->groupBy('property_id')->pluck('total', 'property_id');
        $maintenanceByProperty = MaintenanceRequest::where('organization_id', $organization->id)->whereIn('property_id', $propertyIds)->selectRaw('property_id, sum(actual_cost) as total')->groupBy('property_id')->pluck('total', 'property_id');

        return Inertia::render('finance/PropertyProfitability', [
            'properties' => $properties->map(fn (Property $property) => [
                'id' => $property->id,
                'name' => $property->name,
                'city' => $property->city,
                'owners' => $property->owners->map(fn ($owner) => ['id' => $owner->id, 'name' => $owner->name, 'ownership_share' => $owner->pivot->getAttribute('ownership_share')]),
                'active_rent' => number_format($activeRentByProperty[$property->id] ?? 0, 2, '.', ''),
                'vendor_bill_commitments' => number_format($vendorBillsByProperty[$property->id] ?? 0, 2, '.', ''),
                'maintenance_actual' => number_format($maintenanceByProperty[$property->id] ?? 0, 2, '.', ''),
            ]),
        ]);
    }
}
