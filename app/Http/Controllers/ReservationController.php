<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmContact;
use App\Models\Reservation;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewTransactions', $organization);

        return Inertia::render('transactions/Reservations', [
            'units' => Unit::where('organization_id', $organization->id)->where('status', 'available')->get(['id', 'number', 'property_id']),
            'contacts' => CrmContact::where('organization_id', $organization->id)->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'reservations' => Reservation::where('organization_id', $organization->id)->with(['unit:id,number', 'contact:id,first_name,last_name'])->latest()->get()->map(fn (Reservation $reservation) => [...$reservation->only('id', 'reference', 'status', 'expires_at'), 'unit' => $reservation->unit?->only('id', 'number'), 'contact' => $reservation->contact ? $reservation->contact->only('id', 'first_name', 'last_name') : null]),
            'canManageTransactions' => $request->user()->can('manageTransactions', $organization),
        ]);
    }

    public function store(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageTransactions', $organization);
        $input = $request->validate(['unit_id' => ['required', 'integer'], 'contact_id' => ['nullable', 'integer'], 'expires_at' => ['required', 'date', 'after:now'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $unit = Unit::where('organization_id', $organization->id)->where('status', 'available')->findOrFail((int) $input['unit_id']);
        if ($input['contact_id'] ?? null) {
            CrmContact::where('organization_id', $organization->id)->findOrFail((int) $input['contact_id']);
        }
        $reservation = Reservation::create(['organization_id' => $organization->id, 'created_by' => $request->user()->id, 'reference' => 'RSV-'.Str::upper(Str::random(8)), ...$input]);
        $unit->update(['status' => 'reserved']);
        $audit->handle($organization, $request->user(), 'transactions.reservation.created', $reservation, ['unit_id' => $unit->id]);

        return back();
    }
}
