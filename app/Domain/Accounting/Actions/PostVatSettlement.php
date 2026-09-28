<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Models\VatReturn;
use App\Domain\Accounting\Models\VatSettlement;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PostVatSettlement
{
    public function __construct(private AccountingLedger $ledger, private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $organization, User $actor, VatReturn $return, int $bankId, string $date): VatSettlement
    {
        return DB::transaction(function () use ($organization, $actor, $return, $bankId, $date) {
            $locked = VatReturn::where('organization_id', $organization->id)->with('adjustments')->lockForUpdate()->findOrFail($return->id);
            abort_unless($locked->status === 'filed', 422, 'Only filed VAT returns can be settled.');
            abort_if(VatSettlement::where('vat_return_id', $locked->id)->whereNull('reversal_journal_entry_id')->exists(), 422, 'This VAT return already has an active settlement.');

            [$bank, $bankLedgerAccount] = $this->bank($organization, $bankId);
            $output = round((float) $locked->snapshot['totals']['output_vat'] + $locked->adjustments->sum(fn ($adjustment) => (float) $adjustment->output_vat_delta), 2);
            $input = round((float) $locked->snapshot['totals']['recoverable_input_vat'] + $locked->adjustments->sum(fn ($adjustment) => (float) $adjustment->input_vat_delta), 2);
            abort_if($output < 0 || $input < 0, 422, 'Adjusted VAT balances cannot be negative.');
            $net = round($output - $input, 2);
            abort_if($net === 0.0, 422, 'This return has no VAT to settle.');

            $lines = [];
            if ($output > 0) {
                $lines[] = ['ledger_account_id' => $this->account($organization, $actor, '2200', 'Output VAT Payable', 'liability')->id, 'debit' => $output, 'credit' => 0];
            }
            if ($input > 0) {
                $lines[] = ['ledger_account_id' => $this->account($organization, $actor, '1250', 'Recoverable Input VAT', 'asset')->id, 'debit' => 0, 'credit' => $input];
            }
            $lines[] = $net > 0
                ? ['ledger_account_id' => $bankLedgerAccount->id, 'debit' => 0, 'credit' => $net]
                : ['ledger_account_id' => $this->account($organization, $actor, '1260', 'VAT Refund Receivable', 'asset')->id, 'debit' => abs($net), 'credit' => 0];

            $attempt = VatSettlement::where('vat_return_id', $locked->id)->count() + 1;
            $entry = $this->ledger->post($organization, $actor, $date, 'VAT settlement '.$locked->starts_on->toDateString().' to '.$locked->ends_on->toDateString(), $lines, 'vat.settled', "vat-settlement:{$locked->id}:{$attempt}");
            $settlement = VatSettlement::create([
                'organization_id' => $organization->id, 'vat_return_id' => $locked->id, 'bank_account_id' => $bank->id,
                'type' => $net > 0 ? 'payment' : 'refund_receivable', 'output_vat' => $output, 'input_vat' => $input,
                'net_vat' => $net, 'journal_entry_id' => $entry->id, 'approved_by' => $actor->id,
            ]);
            $this->audit->handle($organization, $actor, 'accounting.vat_return.settled', $settlement, ['net_vat' => $net]);

            return $settlement;
        });
    }

    public function receiveRefund(Organization $organization, User $actor, VatSettlement $settlement, string $date): void
    {
        DB::transaction(function () use ($organization, $actor, $settlement, $date): void {
            $locked = VatSettlement::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($settlement->id);
            abort_unless($locked->type === 'refund_receivable' && ! $locked->reversal_journal_entry_id && ! $locked->receipt_journal_entry_id, 422, 'Only an active, unreceived VAT refund can be received.');
            [, $bankLedgerAccount] = $this->bank($organization, $locked->bank_account_id);
            $amount = abs((float) $locked->net_vat);
            $entry = $this->ledger->post($organization, $actor, $date, 'VAT refund received', [
                ['ledger_account_id' => $bankLedgerAccount->id, 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => $this->account($organization, $actor, '1260', 'VAT Refund Receivable', 'asset')->id, 'debit' => 0, 'credit' => $amount],
            ], 'vat.refund_received', "vat-refund-received:{$locked->id}");
            $locked->update(['receipt_journal_entry_id' => $entry->id]);
            $this->audit->handle($organization, $actor, 'accounting.vat_refund.received', $locked, ['amount' => $amount]);
        });
    }

    public function reverse(Organization $organization, User $actor, VatSettlement $settlement, string $date): void
    {
        DB::transaction(function () use ($organization, $actor, $settlement, $date): void {
            $locked = VatSettlement::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($settlement->id);
            abort_unless(! $locked->reversal_journal_entry_id, 422, 'This VAT settlement is already reversed.');
            abort_if($locked->receipt_journal_entry_id && ! $locked->receipt_reversal_journal_entry_id, 422, 'Reverse the VAT refund receipt first.');
            $entry = $this->ledger->reverse($organization, $actor, JournalEntry::findOrFail($locked->journal_entry_id), $date, false, false, false, false, true);
            $locked->update(['reversal_journal_entry_id' => $entry->id, 'reversed_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.vat_settlement.reversed', $locked);
        });
    }

    public function reverseRefundReceipt(Organization $organization, User $actor, VatSettlement $settlement, string $date): void
    {
        DB::transaction(function () use ($organization, $actor, $settlement, $date): void {
            $locked = VatSettlement::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($settlement->id);
            abort_unless($locked->receipt_journal_entry_id && ! $locked->receipt_reversal_journal_entry_id, 422, 'This VAT refund receipt cannot be reversed.');
            $entry = $this->ledger->reverse($organization, $actor, JournalEntry::findOrFail($locked->receipt_journal_entry_id), $date, false, false, false, false, true);
            $locked->update(['receipt_reversal_journal_entry_id' => $entry->id]);
            $this->audit->handle($organization, $actor, 'accounting.vat_refund_receipt.reversed', $locked);
        });
    }

    /**
     * @return array{BankAccount, LedgerAccount}
     */
    private function bank(Organization $organization, int $bankId): array
    {
        $bank = BankAccount::where('organization_id', $organization->id)->where('currency', 'AED')->whereNotNull('ledger_account_id')->findOrFail($bankId);
        $account = LedgerAccount::where('organization_id', $organization->id)->where('type', 'asset')->where('is_active', true)->findOrFail($bank->ledger_account_id);

        return [$bank, $account];
    }

    private function account(Organization $organization, User $actor, string $code, string $name, string $type): LedgerAccount
    {
        $account = LedgerAccount::firstOrCreate(['organization_id' => $organization->id, 'code' => $code], ['name' => $name, 'type' => $type, 'is_active' => true]);
        abort_unless($account->type === $type && $account->is_active, 422, "Account {$code} is unavailable.");
        if ($account->wasRecentlyCreated) {
            $this->audit->handle($organization, $actor, 'accounting.account.created', $account, ['automatic' => true]);
        }

        return $account;
    }
}
