<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Finance\Models\VendorCreditNote;
use App\Domain\Finance\Services\VendorRefundCapacity;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageVendorCreditNote
{
    public function __construct(private AccountingLedger $ledger, private RecordOrganizationAuditLog $audit) {}

    public function request(Organization $organization, User $actor, VendorBill $bill, string $amount, string $date, string $reason): VendorCreditNote
    {
        Gate::forUser($actor)->authorize('manageFinance', $organization);

        return DB::transaction(function () use ($organization, $actor, $bill, $amount, $date, $reason): VendorCreditNote {
            $locked = VendorBill::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($bill->id);
            $source = $this->source($organization, $locked);
            $this->assertDate($source->posted_on->toDateString(), $date);
            $this->assertAvailable($locked, $amount);
            $this->check(trim($reason) !== '', 'reason', 'A supplier credit note reason is required.');
            $note = VendorCreditNote::create([
                'organization_id' => $organization->id,
                'vendor_bill_id' => $locked->id,
                'reference' => 'VCN-'.Str::upper(Str::random(12)),
                'amount' => $amount,
                'vat_treatment' => $locked->vat_treatment,
                'input_vat_recoverable' => $locked->input_vat_recoverable,
                'accounting_treatment' => $locked->accounting_treatment,
                'reason' => trim($reason),
                'status' => 'submitted',
                'requested_by' => $actor->id,
                'posted_on' => $date,
            ]);
            $this->audit->handle($organization, $actor, 'finance.vendor_credit_note.submitted', $note, ['vendor_bill_id' => $locked->id, 'amount' => $amount, 'posted_on' => $date, 'reason' => trim($reason)]);

            return $note;
        });
    }

    public function approve(Organization $organization, User $actor, VendorCreditNote $creditNote): void
    {
        $this->authorizeOwner($organization, $actor);
        DB::transaction(function () use ($organization, $actor, $creditNote): void {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $locked = VendorCreditNote::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($creditNote->id);
            $bill = VendorBill::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($locked->vendor_bill_id);
            $source = $this->source($organization, $bill);
            $this->check($locked->status === 'submitted', 'credit_note', 'Only a submitted supplier credit note can be approved.');
            $this->check($locked->requested_by !== $actor->id, 'credit_note', 'The requester cannot approve their own supplier credit note.');
            $this->assertAvailable($bill, $locked->amount);
            $this->assertDate($source->posted_on->toDateString(), $locked->posted_on->toDateString());
            $gross = $this->cents($source->debit_total);
            $amount = $this->cents($locked->amount);
            $vatLine = $source->lines->first(fn ($line) => $line->account->code === '1250');
            $originalVat = $vatLine ? $this->cents($vatLine->debit) : 0;
            $active = VendorCreditNote::where('organization_id', $organization->id)->where('vendor_bill_id', $bill->id)->where('status', 'posted')->whereNull('reversed_on')->get();
            $previousGross = $active->sum(fn (VendorCreditNote $item): int => $this->cents($item->amount));
            $previousVat = $active->sum(fn (VendorCreditNote $item): int => $this->cents($item->vat_amount));
            $vat = max(0, min($amount, $originalVat - $previousVat, (int) round(($previousGross + $amount) * $originalVat / $gross) - $previousVat));
            $lines = [];
            foreach ($source->lines as $line) {
                $value = match ($line->account->code) {
                    '2100' => $amount,
                    '1250' => $vat,
                    default => $amount - $vat,
                };
                if ($value > 0) {
                    $lines[] = [
                        'ledger_account_id' => $line->ledger_account_id,
                        'debit' => $line->account->code === '2100' ? $value / 100 : 0,
                        'credit' => $line->account->code === '2100' ? 0 : $value / 100,
                    ];
                }
            }
            $journal = $this->ledger->post($organization, $actor, $locked->posted_on->toDateString(), $locked->reason, $lines, 'vendor.credit_note.posted', 'vendor-credit-note:'.$locked->id);
            $journal->update(['subject_type' => $locked->getMorphClass(), 'subject_id' => $locked->id]);
            $locked->update(['status' => 'posted', 'vat_amount' => $vat / 100, 'approved_by' => $actor->id, 'approved_at' => now(), 'journal_entry_id' => $journal->id]);
            $this->refreshBillStatus($bill);
            $this->audit->handle($organization, $actor, 'finance.vendor_credit_note.approved', $locked, ['vendor_bill_id' => $bill->id, 'journal_entry_id' => $journal->id, 'amount' => $locked->amount, 'vat_amount' => $locked->vat_amount, 'posted_on' => $locked->posted_on->toDateString()]);
        });
    }

    public function reject(Organization $organization, User $actor, VendorCreditNote $creditNote, string $reason): void
    {
        $this->authorizeOwner($organization, $actor);
        DB::transaction(function () use ($organization, $actor, $creditNote, $reason): void {
            $locked = VendorCreditNote::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($creditNote->id);
            $this->check($locked->status === 'submitted', 'credit_note', 'Only a submitted supplier credit note can be rejected.');
            $this->check($locked->requested_by !== $actor->id, 'credit_note', 'The requester cannot reject their own supplier credit note.');
            $this->check(trim($reason) !== '', 'reason', 'A rejection reason is required.');
            $locked->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejection_reason' => trim($reason)]);
            $this->audit->handle($organization, $actor, 'finance.vendor_credit_note.rejected', $locked, ['reason' => trim($reason)]);
        });
    }

    public function reverse(Organization $organization, User $actor, VendorCreditNote $creditNote, string $date, string $reason): void
    {
        $this->authorizeOwner($organization, $actor);
        DB::transaction(function () use ($organization, $actor, $creditNote, $date, $reason): void {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $locked = VendorCreditNote::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($creditNote->id);
            $this->check($locked->status === 'posted', 'credit_note', 'Only a posted supplier credit note can be reversed.');
            $this->check(! app(VendorRefundCapacity::class)->hasActive($organization->id, $locked->vendor_bill_id), 'credit_note', 'Reverse active vendor cash receipts before reversing supplier credits.');
            $this->assertDate($locked->posted_on?->toDateString() ?? '', $date);
            $this->check(trim($reason) !== '', 'reason', 'A reversal reason is required.');
            $source = JournalEntry::where('organization_id', $organization->id)->findOrFail($locked->journal_entry_id);
            $reversal = $this->ledger->reverse($organization, $actor, $source, $date, vendorCreditNote: true);
            $locked->update(['status' => 'reversed', 'reversed_by' => $actor->id, 'reversed_on' => $date, 'reversal_reason' => trim($reason), 'reversal_journal_entry_id' => $reversal->id]);
            $this->refreshBillStatus(VendorBill::where('organization_id', $organization->id)->findOrFail($locked->vendor_bill_id));
            $this->audit->handle($organization, $actor, 'finance.vendor_credit_note.reversed', $locked, ['reversal_journal_entry_id' => $reversal->id, 'reason' => trim($reason)]);
        });
    }

    private function source(Organization $organization, VendorBill $bill): JournalEntry
    {
        $this->check($bill->organization_id === $organization->id && $bill->currency === 'AED' && in_array($bill->status, ['posted', 'partial', 'paid'], true), 'vendor_bill', 'Choose a posted AED vendor bill in this organization.');
        $source = JournalEntry::where('organization_id', $organization->id)->where('event', 'vendor_bill.posted')->where('subject_type', $bill->getMorphClass())->where('subject_id', $bill->id)->with('lines.account')->lockForUpdate()->first();
        $this->check($source !== null, 'vendor_bill', 'The bill requires an original detailed posting.');
        assert($source !== null);
        $this->check($source->currency === 'AED' && ! JournalEntry::where('reversal_of_id', $source->id)->exists(), 'vendor_bill', 'The bill requires an unreversed detailed AED posting.');
        $codes = $source->lines->map(fn ($line) => $line->account->code)->sort()->values()->all();
        $this->check(in_array($codes, [['2100', '5000'], ['1250', '2100', '5000'], ['1500', '2100'], ['1250', '1500', '2100']], true), 'vendor_bill', 'The bill must have detailed accounts-payable and original expense or asset lines.');
        $this->check($this->cents($source->credit_total) === $this->cents($bill->total), 'vendor_bill', 'The original posting must match the vendor bill total.');

        return $source;
    }

    private function assertAvailable(VendorBill $bill, string $amount): void
    {
        $used = VendorCreditNote::where('vendor_bill_id', $bill->id)->where('status', 'posted')->whereNull('reversed_on')->sum('amount');
        $available = max(0, $this->cents($bill->total) - $this->cents($used));
        $this->check($this->cents($amount) > 0 && $this->cents($amount) <= $available, 'amount', 'Supplier credits cannot exceed the original posted bill amount; any resulting balance remains unapplied.');
    }

    private function assertDate(string $sourceDate, string $date): void
    {
        $this->check($date !== '' && $date >= $sourceDate && $date <= today()->toDateString(), 'posted_on', 'Use a date on or after the source posting and no later than today.');
    }

    private function refreshBillStatus(VendorBill $bill): void
    {
        $payments = $this->cents($bill->payments()->sum('amount'));
        $credits = $this->cents(VendorCreditNote::where('vendor_bill_id', $bill->id)->where('status', 'posted')->whereNull('reversed_on')->sum('amount'));
        $allocated = $payments + $credits;
        $gross = $this->cents($bill->total);
        $bill->update(['status' => $allocated >= $gross ? 'paid' : ($allocated > 0 ? 'partial' : 'posted')]);
    }

    private function authorizeOwner(Organization $organization, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewFinance', $organization);
        $this->check($actor->hasOrganizationRole($organization, OrganizationRole::Owner), 'credit_note', 'Only the organization owner can approve, reject, or reverse supplier credit notes.');
    }

    private function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
