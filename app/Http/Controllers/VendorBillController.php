<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Actions\PostMappedFinanceJournal;
use App\Domain\Finance\Models\VendorCreditNote;
use App\Domain\Finance\Services\VendorRefundCapacity;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\MaintenanceVendor;
use App\Models\Property;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VendorBillController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);

        return Inertia::render('finance/VendorBills', [
            'bills' => VendorBill::where('organization_id', $organization->id)->with(['vendor:id,name', 'property:id,name', 'creditNotes.requester:id,name', 'creditNotes.approver:id,name'])->withSum('payments', 'amount')->latest()->get()->map(function (VendorBill $bill): array {
                $credits = $bill->creditNotes->where('status', 'posted')->whereNull('reversed_on')->sum('amount');
                $paid = (float) ($bill->payments_sum_amount ?? 0);
                $returned = app(VendorRefundCapacity::class)->received($bill) / 100;

                return [...$bill->only('id', 'reference', 'description', 'status', 'accounting_treatment', 'vat_treatment', 'vat_rate', 'vat_amount', 'input_vat_recoverable', 'total', 'currency', 'due_on'), 'vendor' => $bill->vendor?->only('id', 'name'), 'property' => $bill->property?->only('id', 'name'), 'paid_amount' => $paid, 'credited_amount' => number_format((float) $credits, 2, '.', ''), 'balance' => max(0, (float) $bill->total - $paid - (float) $credits), 'vendor_cash_returned' => number_format($returned, 2, '.', ''), 'supplier_credit_balance' => max(0, $paid + (float) $credits - (float) $bill->total - $returned), 'creditable_amount' => number_format(max(0, (float) $bill->total - (float) $credits), 2, '.', ''), 'credit_notes' => $bill->creditNotes];
            }),
            'vendors' => MaintenanceVendor::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'properties' => Property::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('manageFinance', $organization),
            'canApproveSupplierCredits' => $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner),
            'vatEnabled' => $organization->vat_enabled,
        ]);
    }

    public function store(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['vendor_id' => ['required', 'integer'], 'property_id' => ['nullable', 'integer'], 'description' => ['required', 'string', 'max:255'], 'bill_date' => ['required', 'date'], 'due_on' => ['nullable', 'date'], 'total' => ['required', 'numeric', 'min:0.01'], 'accounting_treatment' => ['required', 'in:operating_expense,capital_asset'], 'vat_treatment' => [Rule::requiredIf($organization->vat_enabled), 'nullable', 'in:standard,zero_rated,exempt,out_of_scope'], 'input_vat_recoverable' => [Rule::requiredIf($organization->vat_enabled && ($request->input('vat_treatment') === 'standard')), 'nullable', 'boolean']]);
        MaintenanceVendor::where('organization_id', $organization->id)->findOrFail((int) $input['vendor_id']);
        if ($input['property_id'] ?? null) {
            Property::where('organization_id', $organization->id)->findOrFail((int) $input['property_id']);
        }
        $rate = $organization->vat_enabled && $input['vat_treatment'] === 'standard' ? 5 : 0;
        $vat = $rate ? round((float) $input['total'] * 5 / 105, 2) : 0;
        $bill = VendorBill::create(['organization_id' => $organization->id, 'reference' => 'BIL-'.Str::upper(Str::random(8)), ...$input, 'vat_rate' => $organization->vat_enabled ? $rate : null, 'vat_amount' => $organization->vat_enabled ? $vat : null, 'input_vat_recoverable' => $rate ? (bool) $input['input_vat_recoverable'] : false]);
        $audit->handle($organization, $request->user(), 'finance.vendor_bill.created', $bill);

        return back();
    }

    public function updateTreatment(Request $request, VendorBill $bill, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $bill->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        abort_unless($bill->status === 'draft', 422);
        $input = $request->validate(['accounting_treatment' => ['required', 'in:operating_expense,capital_asset'], 'vat_treatment' => [Rule::requiredIf($organization->vat_enabled), 'nullable', 'in:standard,zero_rated,exempt,out_of_scope'], 'input_vat_recoverable' => [Rule::requiredIf($organization->vat_enabled && $request->input('vat_treatment') === 'standard'), 'nullable', 'boolean']]);
        $rate = $organization->vat_enabled && $input['vat_treatment'] === 'standard' ? 5 : 0;
        $vat = $rate ? round((float) $bill->total * 5 / 105, 2) : 0;
        $bill->update([...$input, 'vat_rate' => $organization->vat_enabled ? $rate : null, 'vat_amount' => $organization->vat_enabled ? $vat : null, 'input_vat_recoverable' => $rate ? (bool) $input['input_vat_recoverable'] : false]);
        $audit->handle($organization, $request->user(), 'finance.vendor_bill.treatment_updated', $bill, $input);

        return back();
    }

    public function post(Request $request, VendorBill $bill, RecordOrganizationAuditLog $audit, PostMappedFinanceJournal $mapped): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $bill->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        abort_unless($bill->status === 'draft', 422);
        abort_unless(in_array($bill->accounting_treatment, ['operating_expense', 'capital_asset'], true), 422, 'Select an accounting treatment before posting.');
        abort_if($organization->vat_enabled && ! in_array($bill->vat_treatment, ['standard', 'zero_rated', 'exempt', 'out_of_scope'], true), 422, 'Select a VAT treatment before posting.');
        DB::transaction(function () use ($mapped, $organization, $bill, $audit, $request): void {
            $locked = VendorBill::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($bill->id);
            abort_unless($locked->status === 'draft' && in_array($locked->accounting_treatment, ['operating_expense', 'capital_asset'], true), 422);
            abort_if($organization->vat_enabled && ! in_array($locked->vat_treatment, ['standard', 'zero_rated', 'exempt', 'out_of_scope'], true), 422);
            $locked->update(['status' => 'posted']);
            $mapped->vendorBill($organization, $request->user(), $locked, $locked->total, now()->toDateString(), $locked->accounting_treatment);
            $audit->handle($organization, $request->user(), 'finance.vendor_bill.posted', $locked);
        });

        return back();
    }

    public function pay(Request $request, VendorBill $bill, RecordOrganizationAuditLog $audit, AccountingLedger $ledger, PostMappedFinanceJournal $mapped): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $bill->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        abort_unless(in_array($bill->status, ['posted', 'partial'], true), 422);
        DB::transaction(function () use ($ledger, $organization, $bill, $request, $audit, $mapped): void {
            $locked = VendorBill::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($bill->id);
            abort_unless(in_array($locked->status, ['posted', 'partial'], true), 422);
            $ledger->assertLegacyDateOpen($organization->id, now()->toDateString());
            $credits = VendorCreditNote::where('organization_id', $organization->id)->where('vendor_bill_id', $locked->id)->where('status', 'posted')->whereNull('reversed_on')->sum('amount');
            $remaining = max(0, (float) $locked->total - (float) $locked->payments()->sum('amount') - (float) $credits);
            $amount = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:'.$remaining]])['amount'];
            $payment = VendorBillPayment::create(['organization_id' => $organization->id, 'vendor_bill_id' => $locked->id, 'reference' => 'VPM-'.Str::upper(Str::random(8)), 'amount' => $amount, 'paid_on' => now()->toDateString()]);
            if ($mapped->hasMappedSource($organization, $locked, 'vendor_bill.posted')) {
                $mapped->vendorPayment($organization, $request->user(), $payment, $payment->amount, now()->toDateString());
            } else {
                JournalEntry::create(['organization_id' => $organization->id, 'reference' => 'JRN-'.Str::upper(Str::random(8)), 'event' => 'vendor_bill.paid', 'subject_type' => $payment->getMorphClass(), 'subject_id' => $payment->id, 'posted_on' => now()->toDateString(), 'debit_total' => $payment->amount, 'credit_total' => $payment->amount]);
            }
            $locked->update(['status' => (float) $amount >= $remaining ? 'paid' : 'partial']);
            $audit->handle($organization, $request->user(), 'finance.vendor_bill.payment_recorded', $locked, ['amount' => $amount]);
        });

        return back();
    }
}
