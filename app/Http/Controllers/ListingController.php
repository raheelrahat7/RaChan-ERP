<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\RealEstate\Actions\CreateListingWithInventory;
use App\Domain\RealEstate\Actions\RecordListingInquiry;
use App\Domain\RealEstate\Services\ListingMarket;
use App\Models\Broker;
use App\Models\Building;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ListingController extends Controller
{
    public function storeInquiry(Request $request, RecordListingInquiry $record): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageCrm', $organization);
        $input = $request->validate([
            'listing_id' => ['required', 'integer'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $listingId = (int) $input['listing_id'];
        unset($input['listing_id']);
        $record->handle($organization, $request->user(), $listingId, $input);

        return back();
    }

    public function index(Request $request, ListingMarket $market): Response
    {
        $segment = $request->validate(['market_segment' => ['nullable', 'in:primary,secondary']])['market_segment'] ?? null;

        return $this->renderListings($request, $segment, $market);
    }

    public function secondaryMarket(Request $request, ListingMarket $market): Response
    {
        return $this->renderListings($request, 'secondary', $market);
    }

    private function renderListings(Request $request, ?string $segment, ListingMarket $market): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewCrm', $organization);

        $listings = $market->forSegment($organization, $segment);

        return Inertia::render('real-estate/Listings', ['listings' => $listings->latest()->get()->map(fn (Listing $listing) => [...$listing->toArray(), 'public_url' => $listing->public_token ? route('public.listings.show', $listing->public_token) : null]), 'units' => Unit::where('organization_id', $organization->id)->where('status', 'available')->get(['id', 'number']), 'properties' => Property::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name', 'type', 'city']), 'buildings' => Building::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'property_id', 'name', 'floors']), 'brokers' => Broker::where('organization_id', $organization->id)->get(['id', 'name']), 'marketSegment' => $segment, 'canManage' => $request->user()->can('manageCrm', $organization), 'canManageTransactions' => $request->user()->can('manageTransactions', $organization), 'canManageInventory' => $request->user()->can('manageInventory', $organization)]);
    }

    public function store(Request $request, CreateListingWithInventory $create, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageCrm', $organization);
        $newUnit = $request->input('inventory_mode') === 'new_unit';
        if ($newUnit) {
            $this->authorize('manageInventory', $organization);
        }
        $input = $request->validate([
            'inventory_mode' => ['nullable', 'in:existing_unit,new_unit'],
            'unit_id' => [Rule::requiredIf(! $newUnit), 'nullable', 'integer'],
            'property_id' => ['nullable', 'integer'],
            'property_name' => [Rule::requiredIf($newUnit && ! $request->filled('property_id')), 'nullable', 'string', 'max:255'],
            'property_type' => [Rule::requiredIf($newUnit && ! $request->filled('property_id')), 'nullable', 'in:residential,commercial,mixed_use,land'],
            'property_city' => ['nullable', 'string', 'max:100'],
            'building_id' => ['nullable', 'integer', 'prohibits:building_name'],
            'building_name' => ['nullable', 'string', 'max:255', 'prohibits:building_id'],
            'building_floors' => ['nullable', 'integer', 'min:1', 'max:999'],
            'floor' => ['nullable', 'string', 'max:8', 'regex:/^(?:G|P[1-9][0-9]?|[1-9][0-9]{0,2})$/'],
            'unit_number' => [Rule::requiredIf($newUnit), 'nullable', 'string', 'max:100'],
            'unit_type' => [Rule::requiredIf($newUnit), 'nullable', 'in:apartment,office,retail,warehouse,plot,other'],
            'broker_id' => ['nullable', 'integer'],
            'purpose' => ['required', 'in:sale,rent'],
            'market_segment' => ['nullable', 'in:primary,secondary'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);
        $input['inventory_mode'] = $newUnit ? 'new_unit' : 'existing_unit';
        if ($input['broker_id'] ?? null) {
            Broker::where('organization_id', $organization->id)->findOrFail((int) $input['broker_id']);
        }
        $listing = $create->handle($organization, $input);
        $audit->handle($organization, $request->user(), 'listing.created', $listing, ['unit_id' => $listing->unit_id, 'inventory_mode' => $input['inventory_mode']]);

        return back();
    }

    public function updateStatus(Request $request, Listing $listing): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null && $listing->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        $status = $request->validate(['status' => ['required', 'in:draft,active,paused,closed']])['status'];
        if ($status === 'active') {
            Unit::where('organization_id', $organization->id)->where('status', 'available')->findOrFail($listing->unit_id);
        }
        $listing->update(['status' => $status, 'public_token' => $listing->public_token ?: Str::random(48)]);

        return back();
    }

    public function updateMarketSegment(Request $request, Listing $listing, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null && $listing->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        abort_unless($listing->purpose === 'sale', 422);
        $segment = $request->validate(['market_segment' => ['required', 'in:primary,secondary']])['market_segment'];
        $before = $listing->market_segment;
        $listing->update(['market_segment' => $segment]);
        $audit->handle($organization, $request->user(), 'listing.market_segment.updated', $listing, ['before' => $before, 'after' => $segment]);

        return back();
    }
}
