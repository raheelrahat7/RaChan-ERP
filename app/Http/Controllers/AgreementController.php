<?php

namespace App\Http\Controllers;

use App\Domain\Brokerage\Actions\CalculateCommission;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Actions\ManageVacancy;
use App\Domain\RealEstate\Services\SecondaryDealLink;
use App\Models\Broker;
use App\Models\CommissionPlan;
use App\Models\Lease;
use App\Models\Reservation;
use App\Models\SalesContract;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AgreementController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewTransactions', $organization);

        return Inertia::render('transactions/Agreements', [
            'reservations' => Reservation::where('organization_id', $organization->id)->where('status', 'active')->with(['unit:id,number', 'listing:id,reference,purpose'])->get()->map(fn (Reservation $reservation) => ['id' => $reservation->id, 'reference' => $reservation->reference, 'unit' => $reservation->unit?->only('id', 'number'), 'listing' => $reservation->listing?->only('id', 'reference', 'purpose')]),
            'leases' => Lease::where('organization_id', $organization->id)->with('tenant:id,name')->latest()->get(['id', 'reference', 'status', 'unit_id', 'broker_id', 'tenant_id', 'starts_on', 'ends_on', 'rent_amount'])->map(fn (Lease $lease) => [
                ...$lease->only('id', 'reference', 'status', 'unit_id', 'broker_id', 'starts_on', 'ends_on', 'rent_amount'),
                'tenant' => $lease->tenant?->only('id', 'name'),
                'is_expiring' => $lease->status === 'active' && $lease->ends_on->between(now()->startOfDay(), now()->addDays(60)->endOfDay()),
            ]),
            'salesContracts' => SalesContract::where('organization_id', $organization->id)->latest()->get(['id', 'reference', 'status', 'unit_id', 'broker_id']),
            'brokers' => Broker::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'tenants' => Tenant::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'commissionPlans' => CommissionPlan::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name', 'basis', 'rate']),
            'canManageTransactions' => $request->user()->can('manageTransactions', $organization),
        ]);
    }

    public function storeLease(Request $request, RecordOrganizationAuditLog $audit, SecondaryDealLink $dealLink): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageTransactions', $organization);
        $input = $request->validate(['reservation_id' => ['required', 'integer'], 'tenant_id' => ['nullable', 'integer'], 'broker_id' => ['nullable', 'integer'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'], 'rent_amount' => ['nullable', 'numeric', 'min:0']]);
        $reservation = Reservation::where('organization_id', $organization->id)->where('status', 'active')->findOrFail((int) $input['reservation_id']);
        $dealLink->assertAgreementPurpose($reservation, 'rent');
        if ($input['broker_id'] ?? null) {
            Broker::where('organization_id', $organization->id)->findOrFail((int) $input['broker_id']);
        }
        if ($input['tenant_id'] ?? null) {
            Tenant::where('organization_id', $organization->id)->findOrFail((int) $input['tenant_id']);
        }
        $this->ensureNoActiveAgreement($organization->id, $reservation->unit_id);
        $lease = Lease::create(['organization_id' => $organization->id, 'unit_id' => $reservation->unit_id, 'contact_id' => $reservation->contact_id, 'reservation_id' => $reservation->id, 'reference' => 'LSE-'.Str::upper(Str::random(8)), ...Arr::except($input, 'reservation_id')]);
        $audit->handle($organization, $request->user(), 'transactions.lease.created', $lease);

        return back();
    }

    public function activateLease(Request $request, Lease $lease, RecordOrganizationAuditLog $audit, CalculateCommission $commission, ManageVacancy $vacancies): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $lease->organization_id === $organization->id, 404);
        $this->authorize('manageTransactions', $organization);
        abort_unless($lease->status === 'draft', 422);
        $this->ensureNoActiveAgreement($organization->id, $lease->unit_id, $lease->id);
        $lease->update(['status' => 'active']);
        Unit::find($lease->unit_id)?->update(['status' => 'leased']);
        $vacancies->resolveForLease($organization, $request->user(), $lease);
        if ($lease->broker_id && $request->integer('commission_plan_id')) {
            $plan = CommissionPlan::where('organization_id', $organization->id)->findOrFail($request->integer('commission_plan_id'));
            $commission->handle(Broker::where('organization_id', $organization->id)->findOrFail($lease->broker_id), $lease, (float) $lease->rent_amount, $plan);
        }
        $audit->handle($organization, $request->user(), 'transactions.lease.activated', $lease);

        return back();
    }

    public function renewLease(Request $request, Lease $lease, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $lease->organization_id === $organization->id, 404);
        $this->authorize('manageTransactions', $organization);
        abort_unless($lease->status === 'active', 422, 'Only active leases can be renewed.');
        $input = $request->validate([
            'ends_on' => ['required', 'date', 'after:'.$lease->ends_on->toDateString()],
            'rent_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $lease->update($input);
        $audit->handle($organization, $request->user(), 'transactions.lease.renewed', $lease, $input);

        return back();
    }

    public function storeSalesContract(Request $request, RecordOrganizationAuditLog $audit, SecondaryDealLink $dealLink): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageTransactions', $organization);
        $input = $request->validate(['reservation_id' => ['required', 'integer'], 'broker_id' => ['nullable', 'integer'], 'contracted_on' => ['required', 'date'], 'sale_price' => ['nullable', 'numeric', 'min:0']]);
        $reservation = Reservation::where('organization_id', $organization->id)->where('status', 'active')->findOrFail((int) $input['reservation_id']);
        $dealLink->assertAgreementPurpose($reservation, 'sale');
        if ($input['broker_id'] ?? null) {
            Broker::where('organization_id', $organization->id)->findOrFail((int) $input['broker_id']);
        }
        $this->ensureNoActiveAgreement($organization->id, $reservation->unit_id);
        $contract = SalesContract::create(['organization_id' => $organization->id, 'unit_id' => $reservation->unit_id, 'contact_id' => $reservation->contact_id, 'reservation_id' => $reservation->id, 'reference' => 'SAL-'.Str::upper(Str::random(8)), ...Arr::except($input, 'reservation_id')]);
        $audit->handle($organization, $request->user(), 'transactions.sales_contract.created', $contract);

        return back();
    }

    public function activateSalesContract(Request $request, SalesContract $salesContract, RecordOrganizationAuditLog $audit, CalculateCommission $commission): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $salesContract->organization_id === $organization->id, 404);
        $this->authorize('manageTransactions', $organization);
        abort_unless($salesContract->status === 'draft', 422);
        $this->ensureNoActiveAgreement($organization->id, $salesContract->unit_id);
        $salesContract->update(['status' => 'active']);
        Unit::find($salesContract->unit_id)?->update(['status' => 'sold']);
        if ($salesContract->broker_id && $request->integer('commission_plan_id')) {
            $plan = CommissionPlan::where('organization_id', $organization->id)->findOrFail($request->integer('commission_plan_id'));
            $commission->handle(Broker::where('organization_id', $organization->id)->findOrFail($salesContract->broker_id), $salesContract, (float) $salesContract->sale_price, $plan);
        }
        $audit->handle($organization, $request->user(), 'transactions.sales_contract.activated', $salesContract);

        return back();
    }

    private function ensureNoActiveAgreement(int $organizationId, int $unitId, ?int $excludingLeaseId = null): void
    {
        $leases = Lease::where('organization_id', $organizationId)->where('unit_id', $unitId)->where('status', 'active')->when($excludingLeaseId, fn ($query) => $query->whereKeyNot($excludingLeaseId))->exists();
        abort_if($leases || SalesContract::where('organization_id', $organizationId)->where('unit_id', $unitId)->where('status', 'active')->exists(), 422, 'The unit already has an active agreement.');
    }
}
