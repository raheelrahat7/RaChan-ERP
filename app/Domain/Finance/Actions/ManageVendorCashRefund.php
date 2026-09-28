<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Actions\PostMappedFinanceJournal;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Finance\Models\VendorCashRefund;
use App\Domain\Finance\Models\VendorCreditNote;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Finance\Services\VendorRefundCapacity;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageVendorCashRefund
{
    public function __construct(private VendorRefundCapacity $capacity, private InvoiceBalance $amounts, private PostMappedFinanceJournal $journals, private AccountingLedger $ledger, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function request(Organization $org, User $actor, VendorBill $bill, array $input): VendorCashRefund
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        $values = Validator::make($input, ['credit_note_id' => ['required', 'integer'], 'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'numeric', 'min:0.01'], 'posted_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid']])->validate();
        $reason = trim($values['reason']);
        $this->check($reason !== '', 'reason', 'Record why the vendor returned money.');

        return DB::transaction(function () use ($org, $actor, $bill, $values, $reason): VendorCashRefund {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $bill = VendorBill::where('organization_id', $org->id)->findOrFail($bill->id);
            $note = VendorCreditNote::where('organization_id', $org->id)->where('vendor_bill_id', $bill->id)->findOrFail((int) $values['credit_note_id']);
            $existing = VendorCashRefund::where('organization_id', $org->id)->where('operation_key', $values['operation_key'])->first();
            if ($existing !== null) {
                $this->check($existing->requested_by === $actor->id && $existing->vendor_bill_id === $bill->id && $existing->vendor_credit_note_id === $note->id && $this->amounts->cents($existing->amount) === $this->amounts->cents($values['amount']) && $existing->posted_on->format('Y-m-d') === $values['posted_on'] && $existing->reason === $reason, 'operation_key', 'This request key already has different details.');

                return $existing;
            }
            $this->eligible($org, $bill, $note, $values['amount'], $values['posted_on']);
            $refund = VendorCashRefund::create(['organization_id' => $org->id, 'vendor_bill_id' => $bill->id, 'vendor_credit_note_id' => $note->id, 'reference' => 'VRF-'.Str::upper(Str::random(12)), 'operation_key' => $values['operation_key'], 'amount' => $values['amount'], 'posted_on' => $values['posted_on'], 'reason' => $reason, 'requested_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'finance.vendor_cash_refund.submitted', $refund, ['amount' => $refund->amount, 'vendor_bill_id' => $bill->id, 'credit_note_id' => $note->id, 'reason' => $reason]);

            return $refund;
        });
    }

    public function approve(Organization $org, User $actor, VendorCashRefund $refund): void
    {
        $this->owner($org, $actor);
        DB::transaction(function () use ($org, $actor, $refund): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $refund = VendorCashRefund::where('organization_id', $org->id)->findOrFail($refund->id);
            if ($refund->status === 'posted') {
                return;
            }
            $this->check($refund->status === 'submitted' && $refund->requested_by !== $actor->id, 'refund', 'A different owner must approve a submitted request.');
            $bill = VendorBill::where('organization_id', $org->id)->findOrFail($refund->vendor_bill_id);
            $note = VendorCreditNote::where('organization_id', $org->id)->where('vendor_bill_id', $bill->id)->findOrFail($refund->vendor_credit_note_id);
            $this->eligible($org, $bill, $note, $refund->amount, $refund->posted_on->format('Y-m-d'));
            $this->ledger->periodForFinancePosting($org->id, $refund->posted_on->format('Y-m-d'));
            $this->check(AccountingPeriod::where('organization_id', $org->id)->where('status', 'open')->where('starts_on', '<=', $refund->posted_on)->where('ends_on', '>=', $refund->posted_on)->exists(), 'posted_on', 'An open accounting period is required.');
            $journal = $this->journals->vendorCashRefund($org, $actor, $refund, $refund->amount, $refund->posted_on->format('Y-m-d'));
            $refund->update(['status' => 'posted', 'approved_by' => $actor->id, 'approved_at' => now(), 'journal_entry_id' => $journal->id]);
            $this->audit->handle($org, $actor, 'finance.vendor_cash_refund.approved', $refund, ['amount' => $refund->amount, 'journal_entry_id' => $journal->id]);
        });
    }

    public function reject(Organization $org, User $actor, VendorCashRefund $refund, string $reason): void
    {
        $this->owner($org, $actor);
        $reason = $this->reason($reason);
        DB::transaction(function () use ($org, $actor, $refund, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $refund = VendorCashRefund::where('organization_id', $org->id)->findOrFail($refund->id);
            $this->check($refund->status === 'submitted' && $refund->requested_by !== $actor->id, 'refund', 'A different owner must reject a submitted request.');
            $refund->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);
            $this->audit->handle($org, $actor, 'finance.vendor_cash_refund.rejected', $refund, ['reason' => $reason]);
        });
    }

    public function reverse(Organization $org, User $actor, VendorCashRefund $refund, string $date, string $reason): void
    {
        $this->owner($org, $actor);
        $reason = $this->reason($reason);
        Validator::make(['posted_on' => $date], ['posted_on' => ['required', 'date_format:Y-m-d']])->validate();
        DB::transaction(function () use ($org, $actor, $refund, $date, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $refund = VendorCashRefund::where('organization_id', $org->id)->findOrFail($refund->id);
            if ($refund->status === 'reversed' && $refund->reversed_on?->format('Y-m-d') === $date && $refund->reversal_reason === $reason) {
                return;
            }
            $this->check($refund->status === 'posted', 'refund', 'Only a posted vendor receipt can be reversed.');
            $this->check($date >= $refund->posted_on->format('Y-m-d') && $date <= today()->format('Y-m-d'), 'posted_on', 'Use a date on or after receipt and no later than today.');
            $source = JournalEntry::where('organization_id', $org->id)->findOrFail($refund->journal_entry_id);
            $this->check(! BankStatementLine::where('organization_id', $org->id)->whereIn('matched_journal_line_id', $source->lines()->select('id'))->exists(), 'refund', 'Unmatch the receipt from the bank statement before reversing it.');
            $journal = $this->ledger->reverse($org, $actor, $source, $date, vendorCashRefund: true);
            $refund->update(['status' => 'reversed', 'reversed_by' => $actor->id, 'reversed_on' => $date, 'reversal_reason' => $reason, 'reversal_journal_entry_id' => $journal->id]);
            $this->audit->handle($org, $actor, 'finance.vendor_cash_refund.reversed', $refund, ['reason' => $reason, 'journal_entry_id' => $journal->id]);
        });
    }

    private function eligible(Organization $org, VendorBill $bill, VendorCreditNote $note, string $amount, string $date): void
    {
        $this->check($bill->currency === 'AED' && in_array($bill->status, ['posted', 'partial', 'paid'], true), 'vendor_bill', 'Choose a posted AED vendor bill.');
        $source = JournalEntry::where('organization_id', $org->id)->where('event', 'vendor_bill.posted')->where('subject_type', $bill->getMorphClass())->where('subject_id', $bill->id)->whereHas('lines')->first();
        $this->check($source !== null && ! JournalEntry::where('organization_id', $org->id)->where('reversal_of_id', $source->id)->exists(), 'vendor_bill', 'The bill requires an unreversed detailed posting.');
        $this->check($note->status === 'posted' && $note->reversed_on === null && $note->journal_entry_id !== null, 'credit_note_id', 'Choose a posted unreversed supplier credit.');
        $this->check($date >= $note->posted_on?->format('Y-m-d') && $date <= today()->format('Y-m-d'), 'posted_on', 'Use a date on or after the credit and no later than today.');
        $used = VendorCashRefund::where('organization_id', $org->id)->where('vendor_credit_note_id', $note->id)->whereNotNull('journal_entry_id')->whereNull('reversal_journal_entry_id')->sum('amount');
        $this->check($this->amounts->cents($amount) > 0 && $this->amounts->cents($amount) <= min($this->amounts->cents($note->amount) - $this->amounts->cents($used), min($this->capacity->available($bill, $date), $this->capacity->available($bill, today()->format('Y-m-d')))), 'amount', 'The receipt exceeds the unrefunded supplier credit or actual vendor overpayment.');
    }

    private function owner(Organization $org, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewFinance', $org);
        abort_unless($actor->hasOrganizationRole($org, OrganizationRole::Owner), 403);
    }

    private function reason(string $reason): string
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        $this->check($reason !== '', 'reason', 'Record a reason.');

        return $reason;
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
