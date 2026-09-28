<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\CorporateTaxReturn;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManageCorporateTaxReturn
{
    public function __construct(private AccountingLedger $ledger, private RecordOrganizationAuditLog $audit) {}

    public function approve(Organization $org, User $actor, CorporateTaxReturn $return, string $date): void
    {
        DB::transaction(function () use ($org, $actor, $return, $date): void {
            $r = $this->locked($org, $return);
            abort_unless($r->status === 'prepared' && ! $r->provision_journal_entry_id, 422, 'Only a prepared return can be approved.');
            $journal = null;
            if ((float) $r->tax_payable > 0) {
                $journal = $this->ledger->post($org, $actor, $date, 'Corporate tax provision '.$r->starts_on->toDateString().' to '.$r->ends_on->toDateString(), [['ledger_account_id' => $this->account($org, $actor, '5400', 'Corporate Tax Expense', 'expense')->id, 'debit' => $r->tax_payable, 'credit' => 0], ['ledger_account_id' => $this->account($org, $actor, '2400', 'Corporate Tax Payable', 'liability')->id, 'debit' => 0, 'credit' => $r->tax_payable]], 'corporate_tax.provisioned', "corporate-tax-provision:{$r->id}");
            } $r->update(['status' => 'approved', 'approved_by' => $actor->id, 'provision_journal_entry_id' => $journal?->id]);
            $this->audit->handle($org, $actor, 'accounting.corporate_tax_return.approved', $r);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function file(Organization $org, User $actor, CorporateTaxReturn $return, array $input): void
    {
        $r = $this->locked($org, $return);
        abort_unless($r->status === 'approved', 422, 'Only an approved return can be marked filed.');
        $r->update(['status' => 'filed', 'filed_on' => $input['filed_on'], 'fta_reference' => trim($input['fta_reference']), 'filed_by' => $actor->id]);
        $this->audit->handle($org, $actor, 'accounting.corporate_tax_return.filed', $r);
    }

    public function pay(Organization $org, User $actor, CorporateTaxReturn $return, int $bankId, string $date): void
    {
        DB::transaction(function () use ($org, $actor, $return, $bankId, $date): void {
            $r = $this->locked($org, $return);
            abort_unless($r->status === 'filed' && (float) $r->tax_payable > 0 && ! $r->payment_journal_entry_id, 422, 'Only an unpaid filed return can be paid.');
            $bank = BankAccount::where('organization_id', $org->id)->where('currency', 'AED')->findOrFail($bankId);
            $bankAccount = LedgerAccount::where('organization_id', $org->id)->whereKey($bank->ledger_account_id)->where('type', 'asset')->where('is_active', true)->firstOrFail();
            $entry = $this->ledger->post($org, $actor, $date, 'Corporate tax payment', [['ledger_account_id' => $this->account($org, $actor, '2400', 'Corporate Tax Payable', 'liability')->id, 'debit' => $r->tax_payable, 'credit' => 0], ['ledger_account_id' => $bankAccount->id, 'debit' => 0, 'credit' => $r->tax_payable]], 'corporate_tax.paid', "corporate-tax-payment:{$r->id}");
            $r->update(['bank_account_id' => $bank->id, 'payment_journal_entry_id' => $entry->id]);
            $this->audit->handle($org, $actor, 'accounting.corporate_tax_return.paid', $r);
        });
    }

    public function reversePayment(Organization $org, User $actor, CorporateTaxReturn $return, string $date): void
    {
        $r = $this->locked($org, $return);
        abort_unless($r->payment_journal_entry_id && ! $r->payment_reversal_journal_entry_id, 422);
        $entry = $this->ledger->reverse($org, $actor, JournalEntry::findOrFail($r->payment_journal_entry_id), $date, false, false, false, false, false, true);
        $r->update(['payment_reversal_journal_entry_id' => $entry->id]);
    }

    public function reverseProvision(Organization $org, User $actor, CorporateTaxReturn $return, string $date): void
    {
        $r = $this->locked($org, $return);
        abort_if($r->payment_journal_entry_id && ! $r->payment_reversal_journal_entry_id, 422, 'Reverse payment first.');
        abort_unless($r->provision_journal_entry_id && ! $r->provision_reversal_journal_entry_id, 422);
        $entry = $this->ledger->reverse($org, $actor, JournalEntry::findOrFail($r->provision_journal_entry_id), $date, false, false, false, false, false, true);
        $r->update(['status' => 'prepared', 'provision_reversal_journal_entry_id' => $entry->id]);
    }

    private function locked(Organization $org, CorporateTaxReturn $return): CorporateTaxReturn
    {
        return CorporateTaxReturn::where('organization_id', $org->id)->lockForUpdate()->findOrFail($return->id);
    }

    private function account(Organization $org, User $actor, string $code, string $name, string $type): LedgerAccount
    {
        $a = LedgerAccount::firstOrCreate(['organization_id' => $org->id, 'code' => $code], ['name' => $name, 'type' => $type, 'is_active' => true]);
        abort_unless($a->type === $type && $a->is_active, 422);
        if ($a->wasRecentlyCreated) {
            $this->audit->handle($org, $actor, 'accounting.account.created', $a, ['automatic' => true]);
        }

        return $a;
    }
}
