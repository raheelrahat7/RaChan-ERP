<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Models\LegacyJournalMapping;
use App\Domain\Accounting\Queries\PeriodCloseReadiness;
use App\Domain\Finance\Models\VendorCreditNote;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Finance\Services\VendorRefundCapacity;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBillPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountingLedger
{
    public function __construct(private RecordOrganizationAuditLog $audit, private PeriodCloseReadiness $readiness) {}

    /** @param array{name: string, starts_on: string, ends_on: string} $input */
    public function createPeriod(Organization $organization, User $actor, array $input): AccountingPeriod
    {
        return DB::transaction(function () use ($organization, $actor, $input): AccountingPeriod {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            abort_if(AccountingPeriod::where('organization_id', $organization->id)->where('starts_on', '<=', $input['ends_on'])->where('ends_on', '>=', $input['starts_on'])->exists(), 422, 'Accounting periods cannot overlap.');
            $period = AccountingPeriod::create(['organization_id' => $organization->id, ...$input]);
            $this->audit->handle($organization, $actor, 'accounting.period.created', $period);

            return $period;
        });
    }

    public function closePeriod(Organization $organization, User $actor, AccountingPeriod $period): void
    {
        DB::transaction(function () use ($organization, $actor, $period): void {
            $locked = AccountingPeriod::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($period->id);
            abort_unless($locked->status === 'open', 422);
            abort_if($this->readiness->for($locked)['has_ledger_blocker'], 422, 'Resolve period close-readiness ledger blockers before closing.');
            $locked->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()]);
            $this->audit->handle($organization, $actor, 'accounting.period.closed', $locked);
        });
    }

    public function reopenPeriod(Organization $organization, User $actor, AccountingPeriod $period): void
    {
        DB::transaction(function () use ($organization, $actor, $period): void {
            $locked = AccountingPeriod::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($period->id);
            abort_unless($locked->status === 'closed', 422, 'Only a closed accounting period can be reopened.');
            $locked->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
            $this->audit->handle($organization, $actor, 'accounting.period.reopened', $locked);
        });
    }

    /** @param list<array{ledger_account_id: int, debit: string|int|float, credit: string|int|float, description?: string|null, company_id?:int|null, branch_id?:int|null, cost_centre_id?:int|null}> $lines */
    public function post(Organization $organization, User $actor, string $date, string $description, array $lines, string $event = 'manual.posted', ?string $sourceReference = null): JournalEntry
    {
        return DB::transaction(function () use ($organization, $actor, $date, $description, $lines, $event, $sourceReference): JournalEntry {
            $period = $this->openPeriod($organization->id, $date);
            if ($sourceReference !== null) {
                abort_if(JournalEntry::where('organization_id', $organization->id)->where('source_reference', $sourceReference)->exists(), 422, 'This source reference has already been posted.');
            }
            abort_unless(count($lines) >= 2, 422);
            $debits = 0;
            $credits = 0;
            $accountIds = array_unique(array_column($lines, 'ledger_account_id'));
            $accounts = LedgerAccount::where('organization_id', $organization->id)->whereIn('id', $accountIds)->lockForUpdate()->get()->keyBy('id');
            abort_unless($accounts->count() === count($accountIds), 404);

            foreach ($lines as $line) {
                app(ManageAccountingDimensions::class)->validateLine($organization, $line);
                abort_unless($accounts[$line['ledger_account_id']]->is_active, 422, 'Inactive accounts cannot receive new postings.');
                abort_if((float) $line['debit'] < 0 || (float) $line['credit'] < 0, 422, 'Journal debit and credit amounts cannot be negative.');
                $debit = (int) round((float) $line['debit'] * 100);
                $credit = (int) round((float) $line['credit'] * 100);
                abort_unless(($debit > 0) !== ($credit > 0), 422, 'Each line needs one positive debit or credit.');
                $debits += $debit;
                $credits += $credit;
            }
            abort_unless($debits > 0 && $debits === $credits, 422, 'Journal debits and credits must balance.');

            $entry = JournalEntry::create(['organization_id' => $organization->id, 'accounting_period_id' => $period->id, 'reference' => 'JRN-'.Str::upper(Str::random(10)), 'source_reference' => $sourceReference, 'event' => $event, 'posted_on' => $date, 'debit_total' => $debits / 100, 'credit_total' => $credits / 100, 'currency' => 'AED']);
            foreach ($lines as $line) {
                $entry->lines()->create(['ledger_account_id' => $line['ledger_account_id'], 'company_id' => $line['company_id'] ?? null, 'branch_id' => $line['branch_id'] ?? null, 'cost_centre_id' => $line['cost_centre_id'] ?? null, 'description' => $line['description'] ?? $description, 'debit' => $line['debit'], 'credit' => $line['credit']]);
            }
            $this->audit->handle($organization, $actor, 'accounting.journal.posted', $entry, ['description' => $description, 'source_reference' => $sourceReference]);

            return $entry;
        });
    }

    /** @param list<array{ledger_account_id: int, debit: string|int|float, credit: string|int|float, description?: string|null}> $lines */
    public function postOpeningBalance(Organization $organization, User $actor, string $date, string $sourceReference, array $lines): JournalEntry
    {
        abort_unless(trim($sourceReference) !== '', 422, 'A source reference is required.');

        return $this->post($organization, $actor, $date, 'Opening balance '.$sourceReference, $lines, 'opening.balance', $sourceReference);
    }

    public function reverse(Organization $organization, User $actor, JournalEntry $original, string $date, bool $bankSettlement = false, bool $depreciation = false, bool $assetDisposal = false, bool $assetImpairment = false, bool $vatSettlement = false, bool $corporateTax = false, bool $depositOffset = false, bool $creditNote = false, bool $customerRefund = false, bool $vendorCreditNote = false, bool $legacyMapping = false, bool $vendorCashRefund = false): JournalEntry
    {
        return DB::transaction(function () use ($organization, $actor, $original, $date, $bankSettlement, $depreciation, $assetDisposal, $assetImpairment, $vatSettlement, $corporateTax, $depositOffset, $creditNote, $customerRefund, $vendorCreditNote, $legacyMapping, $vendorCashRefund): JournalEntry {
            if (in_array($original->event, ['vendor_bill.paid', 'vendor_bill.posted', 'vendor.cash_refund_received'], true)) {
                Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            }
            $entry = JournalEntry::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($original->id);
            abort_if(! $legacyMapping && LegacyJournalMapping::where('journal_entry_id', $entry->id)->where('status', 'approved')->exists(), 422, 'Reverse approved historical mappings from the mapping workflow.');
            abort_if($entry->event === 'credit_note.posted' && ! $creditNote, 422, 'Reverse credit notes from the invoice workflow.');
            abort_if($entry->event === 'customer.refund_approved' && ! $customerRefund, 422, 'Reverse customer refunds from the approved refund workflow.');
            abort_if($entry->event === 'vendor.cash_refund_received' && ! $vendorCashRefund, 422, 'Reverse vendor cash receipts through their approval workflow.');
            abort_if($entry->event === 'vendor.credit_note.posted' && ! $vendorCreditNote, 422, 'Reverse supplier credit notes from their approved workflow.');
            if ($entry->event === 'vendor_bill.paid') {
                $payment = VendorBillPayment::where('organization_id', $organization->id)->find($entry->subject_id);
                abort_if($payment !== null && app(VendorRefundCapacity::class)->hasActive($organization->id, $payment->vendor_bill_id), 422, 'Reverse active vendor cash receipts before reversing their source payment.');
            }
            if ($entry->event === 'vendor_bill.posted') {
                abort_if(VendorCreditNote::where('organization_id', $organization->id)->where('vendor_bill_id', $entry->subject_id)->where('status', 'posted')->exists(), 422, 'Reverse supplier credits before reversing the bill.');
            }
            abort_if($entry->event === 'invoice.posted' && app(InvoiceBalance::class)->hasActiveCredits($organization->id, (int) $entry->subject_id), 422, 'Reverse active credit notes before reversing the invoice journal.');
            abort_if($entry->event === 'deposit.rent_offset_approved' && ! $depositOffset, 422, 'Reverse rent offsets from lease compliance.');
            if ($entry->event === 'deposit.refund_approved') {
                abort_if(BankStatementLine::whereIn('matched_journal_line_id', $entry->lines()->select('id'))->exists(), 422, 'Reverse the bank settlement and unmatch the refund before reversing its journal.');
            }
            abort_if($entry->event === 'bank.settled' && ! $bankSettlement, 422, 'Reverse bank settlements from bank reconciliation.');
            abort_if($entry->event === 'depreciation.posted' && ! $depreciation, 422, 'Reverse depreciation from the fixed asset register.');
            abort_if($entry->event === 'fixed_asset.disposed' && ! $assetDisposal, 422, 'Reverse asset disposals from the fixed asset register.');
            abort_if($entry->event === 'fixed_asset.impaired' && ! $assetImpairment, 422, 'Reverse asset impairments from the fixed asset register.');
            abort_if(in_array($entry->event, ['vat.settled', 'vat.refund_received'], true) && ! $vatSettlement, 422, 'Reverse VAT settlements from the VAT return.');
            abort_if(in_array($entry->event, ['corporate_tax.provisioned', 'corporate_tax.paid'], true) && ! $corporateTax, 422, 'Reverse corporate-tax journals from the corporate-tax return.');
            abort_unless($entry->currency === 'AED' && $entry->event !== 'journal.reversed' && $entry->lines()->count() >= 2, 422, 'Only original detailed AED journals can be reversed here.');
            abort_if(JournalEntry::where('reversal_of_id', $entry->id)->exists(), 422, 'This journal has already been reversed.');
            $period = $this->openPeriod($organization->id, $date);
            $reversal = JournalEntry::create(['organization_id' => $organization->id, 'accounting_period_id' => $period->id, 'reversal_of_id' => $entry->id, 'reference' => 'JRN-'.Str::upper(Str::random(10)), 'event' => 'journal.reversed', 'posted_on' => $date, 'debit_total' => $entry->credit_total, 'credit_total' => $entry->debit_total, 'currency' => 'AED']);
            /** @var JournalLine $line */
            foreach ($entry->lines as $line) {
                $reversal->lines()->create(['ledger_account_id' => $line->ledger_account_id, 'company_id' => $line->company_id, 'branch_id' => $line->branch_id, 'cost_centre_id' => $line->cost_centre_id, 'description' => 'Reversal of '.$entry->reference, 'debit' => $line->credit, 'credit' => $line->debit]);
            }
            $this->audit->handle($organization, $actor, 'accounting.journal.reversed', $reversal, ['original_id' => $entry->id]);

            return $reversal;
        });
    }

    public function periodForFinancePosting(int $organizationId, string $date): ?AccountingPeriod
    {
        Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
        $period = AccountingPeriod::where('organization_id', $organizationId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->lockForUpdate()->first();
        abort_if($period?->status === 'closed', 422, 'The accounting period is closed.');

        return $period;
    }

    public function assertLegacyDateOpen(int $organizationId, string $date): void
    {
        $this->periodForFinancePosting($organizationId, $date);
    }

    private function openPeriod(int $organizationId, string $date): AccountingPeriod
    {
        $period = AccountingPeriod::where('organization_id', $organizationId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->lockForUpdate()->first();
        abort_unless($period && $period->status === 'open', 422, 'An open accounting period is required.');

        return $period;
    }
}
