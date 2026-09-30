<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\RealEstate\Services\SecondaryDealLink;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Reservation;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function index(Request $request, LeadVisibility $visibility): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewTransactions', $organization);

        return Inertia::render('transactions/Reservations', [
            'selectedListingId' => $request->integer('listing_id') ?: null,
            'units' => Unit::where('organization_id', $organization->id)->where('status', 'available')->get(['id', 'number', 'property_id']),
            'contacts' => CrmContact::where('organization_id', $organization->id)->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'listings' => Listing::where('organization_id', $organization->id)->where('status', 'active')->where(fn ($query) => $query->where('market_segment', 'secondary')->orWhere('purpose', 'rent'))->whereHas('unit', fn ($query) => $query->where('status', 'available'))->orderBy('reference')->get(['id', 'unit_id', 'reference', 'purpose']),
            'leads' => $request->user()->can('viewCrm', $organization) ? $visibility->scope(CrmLead::where('organization_id', $organization->id)->whereNotNull('listing_id'), $organization, $request->user())->get(['id', 'listing_id', 'first_name', 'last_name']) : [],
            'reservations' => Reservation::where('organization_id', $organization->id)->with(['unit:id,number', 'contact:id,first_name,last_name', 'listing:id,reference,purpose', 'lead:id,assigned_to,first_name,last_name'])->latest()->get()->map(fn (Reservation $reservation) => [...$reservation->only('id', 'reference', 'status', 'expires_at'), 'unit' => $reservation->unit?->only('id', 'number'), 'contact' => $reservation->contact ? $reservation->contact->only('id', 'first_name', 'last_name') : null, 'listing' => $reservation->listing?->only('id', 'reference', 'purpose'), 'lead' => $reservation->lead && $request->user()->can('viewCrm', $organization) && $visibility->canSeeLead($organization, $request->user(), $reservation->lead->assigned_to) ? $reservation->lead->only('id', 'first_name', 'last_name') : null]),
            'canManageTransactions' => $request->user()->can('manageTransactions', $organization),
            'canManageInventory' => $request->user()->can('manageInventory', $organization),
            'canManageCrm' => $request->user()->can('manageCrm', $organization),
        ]);
    }

    public function store(Request $request, RecordOrganizationAuditLog $audit, SecondaryDealLink $dealLink): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageTransactions', $organization);
        $input = $request->validate(['unit_id' => ['required', 'integer'], 'listing_id' => ['nullable', 'integer'], 'lead_id' => ['nullable', 'integer'], 'contact_id' => ['nullable', 'integer'], 'expires_at' => ['required', 'date', 'after:now'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $unit = Unit::where('organization_id', $organization->id)->where('status', 'available')->findOrFail((int) $input['unit_id']);
        $links = $dealLink->validateReservation($organization, $request->user(), $unit, isset($input['listing_id']) ? (int) $input['listing_id'] : null, isset($input['lead_id']) ? (int) $input['lead_id'] : null);
        if ($input['contact_id'] ?? null) {
            CrmContact::where('organization_id', $organization->id)->findOrFail((int) $input['contact_id']);
        }
        $reservation = Reservation::create(['organization_id' => $organization->id, 'created_by' => $request->user()->id, 'reference' => 'RSV-'.Str::upper(Str::random(8)), ...$input, ...$links]);
        $unit->update(['status' => 'reserved']);
        $audit->handle($organization, $request->user(), 'transactions.reservation.created', $reservation, ['unit_id' => $unit->id]);

        return back();
    }
}
