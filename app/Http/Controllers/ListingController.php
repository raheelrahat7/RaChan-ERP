<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\RealEstate\Actions\RecordListingInquiry;
use App\Models\Broker;
use App\Models\Listing;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewCrm', $organization);
        $segment = $request->validate(['market_segment' => ['nullable', 'in:primary,secondary']])['market_segment'] ?? null;

        return Inertia::render('real-estate/Listings', ['listings' => Listing::where('organization_id', $organization->id)->when($segment, fn ($query) => $query->where('market_segment', $segment))->latest()->get()->map(fn (Listing $listing) => [...$listing->toArray(), 'public_url' => $listing->public_token ? route('public.listings.show', $listing->public_token) : null]), 'units' => Unit::where('organization_id', $organization->id)->where('status', 'available')->get(['id', 'number']), 'brokers' => Broker::where('organization_id', $organization->id)->get(['id', 'name']), 'marketSegment' => $segment, 'canManage' => $request->user()->can('manageCrm', $organization)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageCrm', $organization);
        $input = $request->validate(['unit_id' => ['required', 'integer'], 'broker_id' => ['nullable', 'integer'], 'purpose' => ['required', 'in:sale,rent'], 'market_segment' => ['nullable', 'in:primary,secondary'], 'price' => ['required', 'numeric', 'min:0']]);
        if ($input['purpose'] === 'rent' && isset($input['market_segment'])) {
            throw ValidationException::withMessages(['market_segment' => 'Market segment applies to sale listings only.']);
        }
        Unit::where('organization_id', $organization->id)->where('status', 'available')->findOrFail((int) $input['unit_id']);
        if ($input['broker_id'] ?? null) {
            Broker::where('organization_id', $organization->id)->findOrFail((int) $input['broker_id']);
        }
        Listing::create(['organization_id' => $organization->id, 'reference' => 'LST-'.Str::upper(Str::random(8)), 'public_token' => Str::random(48), ...$input]);

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
