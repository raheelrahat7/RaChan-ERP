<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PostMappedFinanceJournal
{
    private const ACCOUNTS = [
        '1100' => ['Accounts Receivable', 'asset'],
        '1150' => ['Undeposited Funds', 'asset'],
        '1190' => ['Payment Clearing', 'asset'],
        '1500' => ['Capital Assets', 'asset'],
        '2100' => ['Accounts Payable', 'liability'],
        '2300' => ['Customer Deposits', 'liability'],
        '4000' => ['Revenue', 'income'],
        '4100' => ['Recovery Income', 'income'],
        '4200' => ['Deposit Forfeiture Income', 'income'],
        '5000' => ['Operating Expense', 'expense'],
        '1250' => ['Recoverable Input VAT', 'asset'],
        '2200' => ['Output VAT Payable', 'liability'],
    ];

    public function __construct(private AccountingLedger $ledger, private RecordOrganizationAuditLog $audit) {}

    public function vendorBill(Organization $organization, User $actor, Model $bill, string $amount, string $date, string $treatment): JournalEntry
    {
        abort_unless(in_array($treatment, ['operating_expense', 'capital_asset'], true), 422);

        $vat = (float) ($bill->getAttribute('vat_amount') ?? 0);
        $recoverable = (bool) $bill->getAttribute('input_vat_recoverable');
        $gross = (float) $amount;
        $base = $recoverable ? $gross - $vat : $gross;
        $lines = [[$treatment === 'capital_asset' ? '1500' : '5000', $base, 0]];
        if ($recoverable && $vat > 0) {
            $lines[] = ['1250', $vat, 0];
        }
        $lines[] = ['2100', 0, $gross];

        return $this->postLines($organization, $actor, $bill, 'vendor_bill.posted', $gross, $date, $lines);
    }

    public function vendorPayment(Organization $organization, User $actor, Model $payment, string $amount, string $date): JournalEntry
    {
        return $this->post($organization, $actor, $payment, 'vendor_bill.paid', $amount, $date, '2100', '1190');
    }

    public function vendorCashRefund(Organization $organization, User $actor, Model $refund, string $amount, string $date): JournalEntry
    {
        return $this->post($organization, $actor, $refund, 'vendor.cash_refund_received', $amount, $date, '1190', '2100');
    }

    public function customerPayment(Organization $organization, User $actor, Model $payment, string $amount, string $date): JournalEntry
    {
        return $this->post($organization, $actor, $payment, 'payment.received', $amount, $date, '1150', '1100');
    }

    public function depositRefund(Organization $organization, User $actor, Model $settlement, string $amount, string $date): JournalEntry
    {
        return $this->post($organization, $actor, $settlement, 'deposit.refund_approved', $amount, $date, '2300', '1190');
    }

    public function customerRefund(Organization $organization, User $actor, Model $refund, string $amount, string $date): JournalEntry
    {
        return $this->post($organization, $actor, $refund, 'customer.refund_approved', $amount, $date, '1100', '1190');
    }

    public function depositRentOffset(Organization $organization, User $actor, Model $deduction, string $amount, string $date): JournalEntry
    {
        return $this->post($organization, $actor, $deduction, 'deposit.rent_offset_approved', $amount, $date, '2300', '1100');
    }

    public function depositRecovery(Organization $organization, User $actor, Model $deduction, string $amount, string $date, string $vatTreatment): JournalEntry
    {
        abort_unless(in_array($vatTreatment, ['standard', 'zero_rated', 'exempt', 'out_of_scope'], true), 422);
        $gross = (float) $amount;
        $vat = $vatTreatment === 'standard' ? round($gross - ($gross / 1.05), 2) : 0;
        $lines = [['2300', $gross, 0], ['4100', 0, $gross - $vat]];
        if ($vat > 0) {
            $lines[] = ['2200', 0, $vat];
        }

        return $this->postLines($organization, $actor, $deduction, 'deposit.recovery_approved', $gross, $date, $lines);
    }

    public function depositForfeiture(Organization $organization, User $actor, Model $deduction, string $amount, string $date, string $vatTreatment): JournalEntry
    {
        abort_unless(in_array($vatTreatment, ['standard', 'zero_rated', 'exempt', 'out_of_scope'], true), 422);
        $gross = (float) $amount;
        $vat = $vatTreatment === 'standard' ? round($gross - ($gross / 1.05), 2) : 0;
        $lines = [['2300', $gross, 0], ['4200', 0, $gross - $vat]];
        if ($vat > 0) {
            $lines[] = ['2200', 0, $vat];
        }

        return $this->postLines($organization, $actor, $deduction, 'deposit.forfeiture_approved', $gross, $date, $lines);
    }

    public function invoice(Organization $organization, User $actor, Model $invoice, string $amount, string $date, string $treatment): JournalEntry
    {
        abort_unless(in_array($treatment, ['revenue', 'refundable_deposit'], true), 422);

        $gross = (float) $amount;
        $vat = (float) ($invoice->getAttribute('vat_amount') ?? 0);
        $lines = [['1100', $gross, 0], [$treatment === 'revenue' ? '4000' : '2300', 0, $gross - $vat]];
        if ($vat > 0) {
            $lines[] = ['2200', 0, $vat];
        }

        return $this->postLines($organization, $actor, $invoice, 'invoice.posted', $gross, $date, $lines);
    }

    public function hasMappedSource(Organization $organization, Model $subject, string $event): bool
    {
        return JournalEntry::where('organization_id', $organization->id)->where('event', $event)->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->whereHas('lines')->exists();
    }

    private function post(Organization $organization, User $actor, Model $subject, string $event, string $amount, string $date, string $debitCode, string $creditCode): JournalEntry
    {
        abort_unless($subject->getAttribute('organization_id') === $organization->id && ($subject->getAttribute('currency') ?? 'AED') === 'AED', 422, 'Mapped postings require an AED organization record.');
        $cents = (int) round((float) $amount * 100);
        abort_unless($cents > 0, 422, 'Journal amount must be positive.');
        $period = $this->ledger->periodForFinancePosting($organization->id, $date);
        $debit = $this->account($organization, $actor, $debitCode);
        $credit = $this->account($organization, $actor, $creditCode);

        $entry = JournalEntry::create(['organization_id' => $organization->id, 'accounting_period_id' => $period?->id, 'reference' => 'JRN-'.Str::upper(Str::random(10)), 'event' => $event, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'posted_on' => $date, 'debit_total' => $amount, 'credit_total' => $amount, 'currency' => 'AED']);
        $entry->lines()->createMany([
            ['ledger_account_id' => $debit->id, 'debit' => $amount, 'credit' => 0, 'description' => $event],
            ['ledger_account_id' => $credit->id, 'debit' => 0, 'credit' => $amount, 'description' => $event],
        ]);
        $this->audit->handle($organization, $actor, 'accounting.journal.posted', $entry, ['event' => $event]);

        return $entry;
    }

    /**
     * @param  list<array{string, float|int, float|int}>  $lines
     */
    private function postLines(Organization $organization, User $actor, Model $subject, string $event, float $amount, string $date, array $lines): JournalEntry
    {
        abort_unless($subject->getAttribute('organization_id') === $organization->id && ($subject->getAttribute('currency') ?? 'AED') === 'AED', 422, 'Mapped postings require an AED organization record.');
        $period = $this->ledger->periodForFinancePosting($organization->id, $date);
        $entry = JournalEntry::create(['organization_id' => $organization->id, 'accounting_period_id' => $period?->id, 'reference' => 'JRN-'.Str::upper(Str::random(10)), 'event' => $event, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'posted_on' => $date, 'debit_total' => $amount, 'credit_total' => $amount, 'currency' => 'AED']);
        foreach ($lines as [$code, $debit, $credit]) {
            $entry->lines()->create(['ledger_account_id' => $this->account($organization, $actor, $code)->id, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'description' => $event]);
        }
        $this->audit->handle($organization, $actor, 'accounting.journal.posted', $entry, ['event' => $event]);

        return $entry;
    }

    private function account(Organization $organization, User $actor, string $code): LedgerAccount
    {
        [$name, $type] = self::ACCOUNTS[$code];
        $account = LedgerAccount::firstOrCreate(['organization_id' => $organization->id, 'code' => $code], ['name' => $name, 'type' => $type, 'is_active' => true]);
        abort_unless($account->type === $type && $account->is_active, 422, 'The mapped account is inactive or has a conflicting type.');
        if ($account->wasRecentlyCreated) {
            $this->audit->handle($organization, $actor, 'accounting.account.created', $account, ['automatic' => true]);
        }

        return $account;
    }
}
