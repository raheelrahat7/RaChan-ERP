<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Leasing\Actions\CreateLeaseServiceCharge;
use App\Domain\Leasing\Actions\CreateSecurityDeposit;
use App\Domain\Leasing\Actions\ManageDepositSettlement;
use App\Domain\Leasing\Actions\ManageLeaseCheque;
use App\Domain\Leasing\Actions\ManageLeaseEjari;
use App\Domain\Leasing\Models\LeaseCheque;
use App\Domain\Leasing\Models\LeaseDepositDeduction;
use App\Domain\Leasing\Models\LeaseDepositSettlement;
use App\Domain\Leasing\Models\LeaseEjariRegistration;
use App\Domain\Leasing\Models\LeaseSecurityDeposit;
use App\Domain\Leasing\Models\LeaseServiceCharge;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeaseComplianceController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewTransactions', $org);
        $leases = Lease::where('organization_id', $org->id)->with('tenant:id,name')->orderByDesc('id')->get(['id', 'reference', 'tenant_id', 'status']);
        $deposits = LeaseSecurityDeposit::where('organization_id', $org->id)->with(['lease:id,reference', 'invoice:id,reference,status,total'])->get()->map(fn ($d) => [...$d->only('id', 'lease_id', 'invoice_id', 'required_amount', 'due_on', 'notes'), 'lease' => $d->lease, 'invoice' => $d->invoice, 'collected_amount' => number_format((float) $d->invoice->payments()->sum('amount'), 2, '.', '')]);

        $cheques = LeaseCheque::where('organization_id', $org->id)->with(['lease:id,reference', 'replacementOf:id,cheque_number'])->orderBy('due_on')->get();
        $serviceCharges = LeaseServiceCharge::where('organization_id', $org->id)->with(['lease:id,reference', 'invoice:id,reference,status,total'])->orderByDesc('period_starts_on')->get()->map(fn ($charge) => [...$charge->toArray(), 'collected_amount' => number_format((float) $charge->invoice->payments()->sum('amount'), 2, '.', '')]);
        $ejariRegistrations = LeaseEjariRegistration::where('organization_id', $org->id)->with(['lease:id,reference', 'renewalOf:id,ejari_number'])->orderByDesc('applied_on')->get();
        $depositSettlements = LeaseDepositSettlement::where('organization_id', $org->id)->with(['deposit.lease:id,reference', 'deductions.invoice:id,reference'])->latest()->get();
        $reversedIds = JournalEntry::where('organization_id', $org->id)->whereNotNull('reversal_of_id')->pluck('reversal_of_id');
        foreach ($depositSettlements as $settlement) {
            $settlement->setAttribute('refund_reversed', $reversedIds->contains($settlement->refund_journal_entry_id));
            foreach ($settlement->deductions as $deduction) {
                $deduction->setAttribute('offset_reversed', $reversedIds->contains($deduction->offset_journal_entry_id));
                $deduction->setAttribute('recovery_reversed', $reversedIds->contains($deduction->recovery_journal_entry_id));
                $deduction->setAttribute('forfeiture_reversed', $reversedIds->contains($deduction->forfeiture_journal_entry_id));
            }
        }
        $outstandingInvoices = Invoice::where('organization_id', $org->id)->where('accounting_treatment', 'revenue')->whereIn('status', ['posted', 'partial'])->withSum('payments', 'amount')->get()->map(fn (Invoice $invoice) => ['id' => $invoice->id, 'reference' => $invoice->reference, 'balance' => number_format(app(InvoiceBalance::class)->outstandingCents($invoice) / 100, 2, '.', '')])->filter(fn (array $invoice) => (float) $invoice['balance'] > 0)->values();

        return Inertia::render('transactions/LeaseCompliance', ['leases' => $leases, 'deposits' => $deposits, 'cheques' => $cheques, 'serviceCharges' => $serviceCharges, 'ejariRegistrations' => $ejariRegistrations, 'depositSettlements' => $depositSettlements, 'outstandingInvoices' => $outstandingInvoices, 'vatEnabled' => $org->vat_enabled, 'canManage' => $request->user()->can('manageTransactions', $org), 'canApproveSettlement' => $request->user()->hasOrganizationRole($org, OrganizationRole::Owner), 'canPostRefund' => $request->user()->can('manageFinance', $org)]);
    }

    public function storeDeposit(Request $request, Lease $lease, CreateSecurityDeposit $create): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $lease->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $input = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'due_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $create->handle($org, $request->user(), $lease, $input);

        return back();
    }

    public function storeCheque(Request $request, Lease $lease, ManageLeaseCheque $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $lease->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $input = $request->validate(['cheque_number' => ['required', 'string', 'max:100', Rule::unique('lease_cheques')->where('organization_id', $org->id)], 'bank_name' => ['required', 'string', 'max:255'], 'payer_name' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'min:0.01'], 'due_on' => ['required', 'date'], 'replacement_of_id' => ['nullable', 'integer']]);
        $replacement = isset($input['replacement_of_id']) ? LeaseCheque::where('organization_id', $org->id)->findOrFail((int) $input['replacement_of_id']) : null;
        $manage->create($org, $request->user(), $lease, Arr::except($input, ['replacement_of_id']), $replacement);

        return back();
    }

    public function updateCheque(Request $request, LeaseCheque $cheque, string $action, ManageLeaseCheque $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $cheque->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        abort_unless(in_array($action, ['deposit', 'clear', 'bounce'], true), 404);
        $input = $request->validate(['occurred_on' => ['required', 'date'], 'reason' => [Rule::requiredIf($action === 'bounce'), 'nullable', 'string', 'max:2000']]);
        $manage->transition($org, $request->user(), $cheque, $action, $input);

        return back();
    }

    public function storeServiceCharge(Request $request, Lease $lease, CreateLeaseServiceCharge $create): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $lease->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $input = $request->validate(['category' => ['required', 'in:service_charge,utilities,maintenance_recovery,other'], 'period_starts_on' => ['required', 'date'], 'period_ends_on' => ['required', 'date', 'after_or_equal:period_starts_on'], 'net_amount' => ['required', 'numeric', 'min:0.01'], 'vat_treatment' => [Rule::requiredIf($org->vat_enabled), 'nullable', 'in:standard,zero_rated,exempt,out_of_scope'], 'due_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $create->handle($org, $request->user(), $lease, $input);

        return back();
    }

    public function storeEjari(Request $request, Lease $lease, ManageLeaseEjari $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $lease->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $input = $request->validate(['applied_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $manage->apply($org, $request->user(), $lease, $input);

        return back();
    }

    public function registerEjari(Request $request, LeaseEjariRegistration $registration, ManageLeaseEjari $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $registration->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $input = $request->validate(['ejari_number' => ['required', 'string', 'max:100', Rule::unique('lease_ejari_registrations')->where('organization_id', $org->id)], 'registered_on' => ['required', 'date'], 'expires_on' => ['required', 'date', 'after:registered_on']]);
        $manage->register($org, $request->user(), $registration, $input);

        return back();
    }

    public function renewEjari(Request $request, LeaseEjariRegistration $registration, ManageLeaseEjari $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $registration->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $input = $request->validate(['applied_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $manage->apply($org, $request->user(), $registration->lease, $input, $registration);

        return back();
    }

    public function storeDepositSettlement(Request $request, LeaseSecurityDeposit $deposit, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $deposit->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $input = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $manage->create($org, $request->user(), $deposit, $input);

        return back();
    }

    public function storeDepositDeduction(Request $request, LeaseDepositSettlement $settlement, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $settlement->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $recovery = $request->input('category') !== 'rent_arrears';
        $input = $request->validate(['category' => ['required', 'in:damage,cleaning,utilities,rent_arrears,forfeiture,other'], 'description' => ['required', 'string', 'max:500'], 'amount' => ['required', 'numeric', 'min:0.01'], 'evidence' => [Rule::requiredIf($request->input('category') === 'forfeiture'), 'nullable', 'string', 'max:2000'], 'invoice_id' => [Rule::requiredIf(! $recovery), 'nullable', 'integer'], 'vat_treatment' => [Rule::requiredIf($org->vat_enabled && $recovery), 'nullable', 'in:standard,zero_rated,exempt,out_of_scope']]);
        $manage->addDeduction($org, $request->user(), $settlement, $input);

        return back();
    }

    public function submitDepositSettlement(Request $request, LeaseDepositSettlement $settlement, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $settlement->organization_id === $org->id, 404);
        $this->authorize('manageTransactions', $org);
        $manage->submit($org, $request->user(), $settlement);

        return back();
    }

    public function approveDepositSettlement(Request $request, LeaseDepositSettlement $settlement, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $settlement->organization_id === $org->id, 404);
        abort_unless($request->user()->hasOrganizationRole($org, OrganizationRole::Owner), 403);
        $manage->approve($org, $request->user(), $settlement);

        return back();
    }

    public function postDepositRefund(Request $request, LeaseDepositSettlement $settlement, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $settlement->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        $date = $request->validate(['posted_on' => ['required', 'date']])['posted_on'];
        $manage->postRefund($org, $request->user(), $settlement, $date);

        return back();
    }

    public function postRentOffset(Request $request, LeaseDepositDeduction $deduction, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $deduction->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        $date = $request->validate(['posted_on' => ['required', 'date']])['posted_on'];
        $manage->postRentOffset($org, $request->user(), $deduction, $date);

        return back();
    }

    public function postRecovery(Request $request, LeaseDepositDeduction $deduction, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $deduction->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        $date = $request->validate(['posted_on' => ['required', 'date']])['posted_on'];
        $manage->postRecovery($org, $request->user(), $deduction, $date);

        return back();
    }

    public function postForfeiture(Request $request, LeaseDepositDeduction $deduction, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $deduction->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        $date = $request->validate(['posted_on' => ['required', 'date']])['posted_on'];
        $manage->postForfeiture($org, $request->user(), $deduction, $date);

        return back();
    }

    public function reverseRentOffset(Request $request, LeaseDepositDeduction $deduction, ManageDepositSettlement $manage): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $deduction->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        $date = $request->validate(['posted_on' => ['required', 'date']])['posted_on'];
        $manage->reverseRentOffset($org, $request->user(), $deduction, $date);

        return back();
    }
}
