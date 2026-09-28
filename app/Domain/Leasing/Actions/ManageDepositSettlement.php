<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Actions\PostMappedFinanceJournal;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Models\LeaseDepositDeduction;
use App\Domain\Leasing\Models\LeaseDepositSettlement;
use App\Domain\Leasing\Models\LeaseSecurityDeposit;
use App\Domain\Leasing\Models\LeaseServiceCharge;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManageDepositSettlement
{
    public function __construct(private RecordOrganizationAuditLog $audit, private PostMappedFinanceJournal $journals) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Organization $org, User $actor, LeaseSecurityDeposit $deposit, array $input): LeaseDepositSettlement
    {
        abort_if(LeaseDepositSettlement::where('organization_id', $org->id)->where('lease_security_deposit_id', $deposit->id)->exists(), 422, 'This deposit already has a settlement.');
        $settlement = LeaseDepositSettlement::create(['organization_id' => $org->id, 'lease_security_deposit_id' => $deposit->id, 'status' => 'draft', 'notes' => $input['notes'] ?? null, 'created_by' => $actor->id]);
        $this->audit->handle($org, $actor, 'leasing.deposit_settlement.created', $settlement);

        return $settlement;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function addDeduction(Organization $org, User $actor, LeaseDepositSettlement $settlement, array $input): void
    {
        abort_unless($settlement->status === 'draft', 422, 'Only a draft settlement can be edited.');
        if ($input['category'] === 'rent_arrears') {
            $invoice = Invoice::where('organization_id', $org->id)->findOrFail((int) $input['invoice_id']);
            $this->assertInvoiceBelongsToLease($settlement, $invoice);
            abort_unless(in_array($invoice->status, ['posted', 'partial'], true) && $invoice->accounting_treatment === 'revenue', 422, 'Choose an outstanding posted revenue invoice.');
            $outstanding = app(InvoiceBalance::class)->outstandingCents($invoice) / 100;
            abort_if((float) $input['amount'] > $outstanding, 422, 'The deduction exceeds the invoice balance.');
        } else {
            $input['invoice_id'] = null;
            $input['vat_treatment'] = $org->vat_enabled ? $input['vat_treatment'] : 'out_of_scope';
            $input['vat_rate'] = $input['vat_treatment'] === 'standard' ? 5 : 0;
            $input['vat_amount'] = $input['vat_treatment'] === 'standard' ? round((float) $input['amount'] - ((float) $input['amount'] / 1.05), 2) : 0;
        }
        $deduction = LeaseDepositDeduction::create(['organization_id' => $org->id, 'lease_deposit_settlement_id' => $settlement->id, ...$input, 'created_by' => $actor->id]);
        $this->audit->handle($org, $actor, 'leasing.deposit_deduction.created', $deduction);
    }

    public function submit(Organization $org, User $actor, LeaseDepositSettlement $settlement): void
    {
        DB::transaction(function () use ($org, $actor, $settlement): void {
            $locked = LeaseDepositSettlement::where('organization_id', $org->id)->lockForUpdate()->findOrFail($settlement->id);
            abort_unless($locked->status === 'draft', 422, 'Only a draft settlement can be submitted.');
            $collected = (float) $locked->deposit->invoice->payments()->sum('amount');
            $deductions = (float) $locked->deductions()->sum('amount');
            abort_if($deductions > $collected, 422, 'Deductions cannot exceed the collected deposit.');
            $locked->update(['status' => 'submitted', 'collected_amount' => $collected, 'deductions_total' => $deductions, 'refund_amount' => $collected - $deductions, 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->audit->handle($org, $actor, 'leasing.deposit_settlement.submitted', $locked, ['automatic_journal' => false]);
        });
    }

    public function approve(Organization $org, User $actor, LeaseDepositSettlement $settlement): void
    {
        DB::transaction(function () use ($org, $actor, $settlement): void {
            $locked = LeaseDepositSettlement::where('organization_id', $org->id)->lockForUpdate()->findOrFail($settlement->id);
            abort_unless($locked->status === 'submitted', 422, 'Only a submitted settlement can be approved.');
            $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->handle($org, $actor, 'leasing.deposit_settlement.approved', $locked, ['automatic_journal' => false]);
        });
    }

    public function postRefund(Organization $org, User $actor, LeaseDepositSettlement $settlement, string $date): void
    {
        DB::transaction(function () use ($org, $actor, $settlement, $date): void {
            $locked = LeaseDepositSettlement::where('organization_id', $org->id)->lockForUpdate()->findOrFail($settlement->id);
            abort_unless($locked->status === 'approved' && (float) $locked->refund_amount > 0, 422, 'Only an approved settlement with a refund can be posted.');
            abort_if($locked->refund_journal_entry_id !== null, 422, 'The refund is already posted.');
            $journal = $this->journals->depositRefund($org, $actor, $locked, $locked->refund_amount, $date);
            $locked->update(['refund_journal_entry_id' => $journal->id, 'refund_approved_by' => $actor->id, 'refund_posted_on' => $date]);
            $this->audit->handle($org, $actor, 'leasing.deposit_refund.posted', $locked, ['journal_entry_id' => $journal->id]);
        });
    }

    public function postRentOffset(Organization $org, User $actor, LeaseDepositDeduction $deduction, string $date): void
    {
        DB::transaction(function () use ($org, $actor, $deduction, $date): void {
            $locked = LeaseDepositDeduction::where('organization_id', $org->id)->lockForUpdate()->findOrFail($deduction->id);
            abort_unless($locked->category === 'rent_arrears' && $locked->settlement->status === 'approved', 422, 'Only an approved rent-arrears deduction can be posted.');
            abort_if($locked->offset_journal_entry_id !== null, 422, 'The rent offset is already posted.');
            $invoice = Invoice::where('organization_id', $org->id)->lockForUpdate()->findOrFail($locked->invoice_id);
            $this->assertInvoiceBelongsToLease($locked->settlement, $invoice);
            abort_unless(in_array($invoice->status, ['posted', 'partial'], true), 422, 'The invoice is no longer outstanding.');
            abort_unless($invoice->currency === 'AED' && $invoice->accounting_treatment === 'revenue' && $this->journals->hasMappedSource($org, $invoice, 'invoice.posted'), 422, 'Rent offsets require an AED revenue invoice posted to the detailed ledger.');
            $outstanding = app(InvoiceBalance::class)->outstandingCents($invoice) / 100;
            abort_if((float) $locked->amount > $outstanding, 422, 'The deduction exceeds the current invoice balance.');
            $latestCredit = app(InvoiceBalance::class)->latestCreditActivity($invoice);
            abort_if($latestCredit !== null && $date < $latestCredit, 422, 'Use a date on or after the latest invoice credit-note activity.');
            $journal = $this->journals->depositRentOffset($org, $actor, $locked, $locked->amount, $date);
            $payment = Payment::create(['organization_id' => $org->id, 'invoice_id' => $invoice->id, 'reference' => 'OFF-'.Str::upper(Str::random(8)), 'amount' => $locked->amount, 'currency' => 'AED', 'received_on' => $date, 'method' => 'security_deposit_offset']);
            app(InvoiceBalance::class)->refreshStatus($invoice);
            $locked->update(['offset_payment_id' => $payment->id, 'offset_journal_entry_id' => $journal->id, 'offset_posted_on' => $date, 'offset_approved_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'leasing.deposit_rent_offset.posted', $locked, ['invoice_id' => $invoice->id, 'journal_entry_id' => $journal->id]);
        });
    }

    public function postRecovery(Organization $org, User $actor, LeaseDepositDeduction $deduction, string $date): void
    {
        DB::transaction(function () use ($org, $actor, $deduction, $date): void {
            $locked = LeaseDepositDeduction::where('organization_id', $org->id)->lockForUpdate()->findOrFail($deduction->id);
            abort_unless(in_array($locked->category, ['damage', 'cleaning', 'utilities', 'other'], true) && $locked->settlement->status === 'approved', 422, 'Only an approved recovery deduction can be posted.');
            abort_if($locked->recovery_journal_entry_id !== null, 422, 'The recovery is already posted.');
            $journal = $this->journals->depositRecovery($org, $actor, $locked, $locked->amount, $date, $locked->vat_treatment);
            $locked->update(['recovery_journal_entry_id' => $journal->id, 'recovery_posted_on' => $date, 'recovery_approved_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'leasing.deposit_recovery.posted', $locked, ['journal_entry_id' => $journal->id, 'vat_treatment' => $locked->vat_treatment]);
        });
    }

    public function postForfeiture(Organization $org, User $actor, LeaseDepositDeduction $deduction, string $date): void
    {
        DB::transaction(function () use ($org, $actor, $deduction, $date): void {
            $locked = LeaseDepositDeduction::where('organization_id', $org->id)->lockForUpdate()->findOrFail($deduction->id);
            abort_unless($locked->category === 'forfeiture' && $locked->settlement->status === 'approved', 422, 'Only an approved forfeiture can be posted.');
            abort_if($locked->forfeiture_journal_entry_id !== null, 422, 'The forfeiture is already posted.');
            $journal = $this->journals->depositForfeiture($org, $actor, $locked, $locked->amount, $date, $locked->vat_treatment);
            $locked->update(['forfeiture_journal_entry_id' => $journal->id, 'forfeiture_posted_on' => $date, 'forfeiture_approved_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'leasing.deposit_forfeiture.posted', $locked, ['journal_entry_id' => $journal->id, 'vat_treatment' => $locked->vat_treatment]);
        });
    }

    public function reverseRentOffset(Organization $org, User $actor, LeaseDepositDeduction $deduction, string $date): void
    {
        DB::transaction(function () use ($org, $actor, $deduction, $date): void {
            $locked = LeaseDepositDeduction::where('organization_id', $org->id)->lockForUpdate()->findOrFail($deduction->id);
            abort_unless($locked->category === 'rent_arrears' && $locked->offset_journal_entry_id, 422, 'Post a rent offset before reversing it.');
            $invoice = Invoice::where('organization_id', $org->id)->lockForUpdate()->findOrFail($locked->invoice_id);
            $journal = JournalEntry::where('organization_id', $org->id)->findOrFail($locked->offset_journal_entry_id);
            $reversal = app(AccountingLedger::class)->reverse($org, $actor, $journal, $date, depositOffset: true);
            Payment::create(['organization_id' => $org->id, 'invoice_id' => $invoice->id, 'reference' => 'OFF-REV-'.Str::upper(Str::random(8)), 'amount' => -(float) $locked->amount, 'currency' => 'AED', 'received_on' => $date, 'method' => 'security_deposit_offset_reversal']);
            app(InvoiceBalance::class)->refreshStatus($invoice);
            $this->audit->handle($org, $actor, 'leasing.deposit_rent_offset.reversed', $locked, ['journal_entry_id' => $reversal->id, 'invoice_id' => $invoice->id]);
        });
    }

    private function assertInvoiceBelongsToLease(LeaseDepositSettlement $settlement, Invoice $invoice): void
    {
        $lease = $settlement->deposit->lease;
        $sameContact = $lease->contact_id !== null && $invoice->contact_id === $lease->contact_id;
        $leaseCharge = LeaseServiceCharge::where('organization_id', $settlement->organization_id)->where('lease_id', $lease->id)->where('invoice_id', $invoice->id)->exists();
        abort_unless($sameContact || $leaseCharge, 422, 'The invoice must belong to the lease contact or be a charge linked to this lease.');
    }
}
