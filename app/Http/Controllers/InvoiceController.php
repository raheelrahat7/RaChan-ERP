<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Actions\PostMappedFinanceJournal;
use App\Domain\Finance\Actions\CreateInvoiceDraft;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, InvoiceBalance $balances): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewFinance', $org);

        return Inertia::render('finance/Invoices', [
            'invoices' => Invoice::where('organization_id', $org->id)->with(['creditNotes.refunds', 'refunds.creditNote:id,reference', 'refunds.requester:id,name', 'refunds.approver:id,name'])->withSum('payments', 'amount')->latest()->get()->map(fn (Invoice $invoice): array => $this->invoicePayload($invoice, $balances)),
            'canManageFinance' => $request->user()->can('manageFinance', $org),
            'canApproveCustomerRefunds' => $request->user()->hasOrganizationRole($org, OrganizationRole::Owner),
            'vatEnabled' => $org->vat_enabled,
        ]);
    }

    /** @return array<string, mixed> */
    private function invoicePayload(Invoice $invoice, InvoiceBalance $balances): array
    {
        return [
            ...$invoice->only('id', 'reference', 'status', 'accounting_treatment', 'vat_treatment', 'vat_rate', 'subtotal', 'total', 'currency', 'due_on'),
            'paid_amount' => $invoice->payments_sum_amount ?? '0.00',
            'balance' => $balances->outstandingCents($invoice) / 100,
            'creditable_amount' => number_format($balances->creditableCents($invoice) / 100, 2, '.', ''),
            'credited_amount' => number_format($balances->creditedCents($invoice) / 100, 2, '.', ''),
            'credit_notes' => $invoice->creditNotes->map(fn ($note): array => [
                'id' => $note->id,
                'reference' => $note->reference,
                'status' => $note->status,
                'amount' => $note->amount,
                'vat_amount' => $note->vat_amount,
                'reason' => $note->reason,
                'posted_on' => $note->posted_on?->toDateString(),
                'reversed_on' => $note->reversed_on?->toDateString(),
                'reversal_reason' => $note->reversal_reason,
                'refund_available' => number_format(max(0, (float) $note->amount - $note->refunds->where('status', 'posted')->sum('amount')), 2, '.', ''),
            ]),
            'refunds' => $invoice->refunds,
            'refundable_cash_amount' => number_format($balances->refundableCashCents($invoice) / 100, 2, '.', ''),
            'is_overdue' => in_array($invoice->status, ['posted', 'partial'], true) && $invoice->due_on?->isBefore(today()),
        ];
    }

    public function store(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('manageFinance', $org);
        app(CreateInvoiceDraft::class)->handle($org, $request->user(), $request->all());

        return back();
    }

    public function updateTreatment(Request $request, Invoice $invoice, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $invoice->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        abort_unless($invoice->status === 'draft', 422);
        $input = $request->validate(['accounting_treatment' => ['required', 'in:revenue,refundable_deposit'], 'vat_treatment' => [Rule::requiredIf($organization->vat_enabled), 'nullable', 'in:standard,zero_rated,exempt,out_of_scope']]);
        $rate = $organization->vat_enabled && $input['vat_treatment'] === 'standard' ? 5 : 0;
        $vat = round((float) $invoice->subtotal * $rate / 100, 2);
        $invoice->update(['accounting_treatment' => $input['accounting_treatment'], 'vat_treatment' => $organization->vat_enabled ? $input['vat_treatment'] : null, 'vat_rate' => $organization->vat_enabled ? $rate : null, 'vat_amount' => $organization->vat_enabled ? $vat : null, 'total' => (float) $invoice->subtotal + $vat]);
        $audit->handle($organization, $request->user(), 'finance.invoice.treatment_updated', $invoice, $input);

        return back();
    }

    public function post(Request $request, Invoice $invoice, RecordOrganizationAuditLog $audit, PostMappedFinanceJournal $mapped): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $invoice->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        abort_unless($invoice->status === 'draft', 422);
        abort_unless(in_array($invoice->accounting_treatment, ['revenue', 'refundable_deposit'], true), 422, 'Select an accounting treatment before posting.');
        abort_if($org->vat_enabled && ! in_array($invoice->vat_treatment, ['standard', 'zero_rated', 'exempt', 'out_of_scope'], true), 422, 'Select a VAT treatment before posting.');
        DB::transaction(function () use ($org, $invoice, $audit, $request, $mapped): void {
            $locked = Invoice::where('organization_id', $org->id)->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($locked->status === 'draft' && in_array($locked->accounting_treatment, ['revenue', 'refundable_deposit'], true), 422);
            abort_if($org->vat_enabled && ! in_array($locked->vat_treatment, ['standard', 'zero_rated', 'exempt', 'out_of_scope'], true), 422);
            $locked->update(['status' => 'posted', 'issued_on' => now()->toDateString()]);
            $mapped->invoice($org, $request->user(), $locked, $locked->total, now()->toDateString(), $locked->accounting_treatment);
            $audit->handle($org, $request->user(), 'finance.invoice.posted', $locked);
        });

        return back();
    }

    public function pay(Request $request, Invoice $invoice, RecordOrganizationAuditLog $audit, AccountingLedger $ledger, PostMappedFinanceJournal $mapped): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $invoice->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        abort_unless(in_array($invoice->status, ['posted', 'partial'], true), 422);
        DB::transaction(function () use ($ledger, $org, $invoice, $request, $audit, $mapped): void {
            $locked = Invoice::where('organization_id', $org->id)->lockForUpdate()->findOrFail($invoice->id);
            abort_unless(in_array($locked->status, ['posted', 'partial'], true), 422);
            $ledger->assertLegacyDateOpen($org->id, now()->toDateString());
            $remaining = app(InvoiceBalance::class)->outstandingCents($locked) / 100;
            abort_if($remaining <= 0, 422, 'This invoice is already paid.');
            $amount = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:'.$remaining]])['amount'];
            $payment = Payment::create(['organization_id' => $org->id, 'invoice_id' => $locked->id, 'reference' => 'PAY-'.Str::upper(Str::random(8)), 'amount' => $amount, 'received_on' => now()->toDateString()]);
            if ($mapped->hasMappedSource($org, $locked, 'invoice.posted')) {
                $mapped->customerPayment($org, $request->user(), $payment, $payment->amount, now()->toDateString());
            } else {
                JournalEntry::create(['organization_id' => $org->id, 'reference' => 'JRN-'.Str::upper(Str::random(8)), 'event' => 'payment.received', 'subject_type' => $payment->getMorphClass(), 'subject_id' => $payment->id, 'posted_on' => now()->toDateString(), 'debit_total' => $payment->amount, 'credit_total' => $payment->amount]);
            }
            app(InvoiceBalance::class)->refreshStatus($locked);
            $audit->handle($org, $request->user(), 'finance.payment.recorded', $locked, ['amount' => $amount]);
        });

        return back();
    }
}
