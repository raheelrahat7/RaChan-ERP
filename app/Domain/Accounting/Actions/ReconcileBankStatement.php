<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\BankSettlement;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReconcileBankStatement
{
    public function __construct(private RecordOrganizationAuditLog $audit, private AccountingLedger $ledger) {}

    public function import(Organization $organization, User $actor, BankAccount $bankAccount, UploadedFile $file): int
    {
        abort_unless($bankAccount->organization_id === $organization->id && $bankAccount->currency === 'AED', 404);
        $handle = fopen($file->getRealPath(), 'r');
        abort_unless($handle !== false, 422);
        try {
            $header = fgetcsv($handle);
            if (is_array($header)) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
            }
            if ($header !== ['transaction_id', 'date', 'description', 'amount']) {
                throw ValidationException::withMessages(['file' => 'CSV header must be transaction_id,date,description,amount.']);
            }
            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($rows) >= 5000 || count($row) !== 4) {
                    throw ValidationException::withMessages(['file' => 'CSV must contain at most 5,000 rows with four columns each.']);
                }
                [$externalId, $date, $description, $amount] = array_map(fn ($value) => trim((string) $value), $row);
                $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
                if ($externalId === '' || strlen($externalId) > 100 || $description === '' || strlen($description) > 255 || ! $validDate || ! preg_match('/^-?(?:0|[1-9]\d{0,11})\.\d{2}$/', $amount) || (float) $amount == 0.0 || isset($rows[$externalId])) {
                    throw ValidationException::withMessages(['file' => 'Each row needs a unique transaction ID, valid YYYY-MM-DD date, description, and nonzero signed AED amount with two decimals.']);
                }
                $rows[$externalId] = ['occurred_on' => $date, 'description' => $description, 'amount' => $amount];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'CSV contains no statement rows.']);
            }
        } finally {
            fclose($handle);
        }

        return DB::transaction(function () use ($organization, $actor, $bankAccount, $rows): int {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $batch = (string) Str::uuid();
            $created = 0;
            foreach ($rows as $externalId => $row) {
                $existing = BankStatementLine::where('bank_account_id', $bankAccount->id)->where('external_id', $externalId)->first();
                if ($existing) {
                    if ($existing->occurred_on->toDateString() !== $row['occurred_on'] || $existing->description !== $row['description'] || $existing->amount !== number_format((float) $row['amount'], 2, '.', '')) {
                        throw ValidationException::withMessages(['file' => 'Transaction ID '.$externalId.' already exists with different data.']);
                    }

                    continue;
                }
                BankStatementLine::create(['organization_id' => $organization->id, 'bank_account_id' => $bankAccount->id, 'imported_by' => $actor->id, 'import_batch' => $batch, 'external_id' => $externalId, ...$row]);
                $created++;
            }
            $this->audit->handle($organization, $actor, 'accounting.bank_statement.imported', $bankAccount, ['batch' => $batch, 'created' => $created]);

            return $created;
        });
    }

    public function match(Organization $organization, User $actor, BankStatementLine $statementLine, JournalLine $journalLine): void
    {
        DB::transaction(function () use ($organization, $actor, $statementLine, $journalLine): void {
            $bankLine = BankStatementLine::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($statementLine->id);
            $journal = JournalLine::with(['account', 'entry'])->lockForUpdate()->findOrFail($journalLine->id);
            abort_if(JournalEntry::where('reversal_of_id', $journal->journal_entry_id)->exists(), 422, 'A reversed clearing entry cannot be matched.');
            abort_unless($bankLine->matched_journal_line_id === null, 422, 'Statement line is already matched.');
            abort_if(BankStatementLine::where('matched_journal_line_id', $journal->id)->exists(), 422, 'Journal line is already matched.');
            abort_unless($journal->entry->organization_id === $organization->id && $journal->entry->currency === 'AED', 404);
            $incoming = (float) $bankLine->amount > 0;
            $expectedCode = $incoming ? '1150' : '1190';
            $expectedEvents = $incoming ? ['payment.received'] : ['vendor_bill.paid', 'deposit.refund_approved', 'customer.refund_approved'];
            $journalAmount = $incoming ? $journal->debit : $journal->credit;
            abort_unless($journal->account->organization_id === $organization->id && $journal->account->code === $expectedCode && in_array($journal->entry->event, $expectedEvents, true) && (int) round(abs((float) $bankLine->amount) * 100) === (int) round((float) $journalAmount * 100), 422, 'The direction and amount must match an eligible clearing entry.');
            $bankLine->update(['matched_journal_line_id' => $journal->id, 'matched_by' => $actor->id, 'matched_at' => now()]);
            $this->audit->handle($organization, $actor, 'accounting.bank_statement.matched', $bankLine, ['journal_line_id' => $journal->id]);
        });
    }

    public function unmatch(Organization $organization, User $actor, BankStatementLine $statementLine): void
    {
        DB::transaction(function () use ($organization, $actor, $statementLine): void {
            $line = BankStatementLine::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($statementLine->id);
            abort_unless($line->matched_journal_line_id !== null, 422);
            abort_if($line->settlements()->whereNull('reversal_journal_entry_id')->exists(), 422, 'Reverse the bank settlement before unmatching.');
            $previousId = $line->matched_journal_line_id;
            $line->update(['matched_journal_line_id' => null, 'matched_by' => null, 'matched_at' => null]);
            $this->audit->handle($organization, $actor, 'accounting.bank_statement.unmatched', $line, ['journal_line_id' => $previousId]);
        });
    }

    public function linkLedgerAccount(Organization $organization, User $actor, BankAccount $bankAccount, LedgerAccount $ledgerAccount): void
    {
        abort_unless($bankAccount->organization_id === $organization->id && $ledgerAccount->organization_id === $organization->id, 404);
        abort_unless($ledgerAccount->type === 'asset' && $ledgerAccount->is_active && ! in_array($ledgerAccount->code, ['1150', '1190'], true), 422, 'Choose an active bank asset account, not a clearing account.');
        $bankAccount->update(['ledger_account_id' => $ledgerAccount->id]);
        $this->audit->handle($organization, $actor, 'accounting.bank_account.ledger_linked', $bankAccount, ['ledger_account_id' => $ledgerAccount->id]);
    }

    public function settle(Organization $organization, User $actor, BankStatementLine $statementLine): void
    {
        DB::transaction(function () use ($organization, $actor, $statementLine): void {
            $line = BankStatementLine::where('organization_id', $organization->id)->with(['bankAccount', 'settlements'])->lockForUpdate()->findOrFail($statementLine->id);
            abort_unless($line->matched_journal_line_id !== null, 422, 'Match a clearing entry first.');
            $matched = JournalLine::findOrFail($line->matched_journal_line_id);
            abort_if(JournalEntry::where('reversal_of_id', $matched->journal_entry_id)->exists(), 422, 'A reversed clearing entry cannot be settled.');
            abort_if($line->settlements->contains(fn (BankSettlement $settlement) => $settlement->reversal_journal_entry_id === null), 422, 'Already settled.');
            abort_unless($line->bankAccount->ledger_account_id !== null, 422, 'Link a bank ledger account first.');
            $bank = LedgerAccount::where('organization_id', $organization->id)->findOrFail($line->bankAccount->ledger_account_id);
            abort_unless($bank->type === 'asset' && $bank->is_active, 422, 'Bank account needs an active asset ledger account.');
            $clearing = JournalLine::whereKey($line->matched_journal_line_id)->with('account')->firstOrFail()->account;
            abort_unless($clearing?->organization_id === $organization->id && $clearing->is_active, 422, 'Clearing account is inactive.');
            $incoming = (float) $line->amount > 0;
            abort_unless($clearing->code === ($incoming ? '1150' : '1190'), 422);
            $amount = number_format(abs((float) $line->amount), 2, '.', '');
            $entry = $this->ledger->post($organization, $actor, $line->occurred_on->toDateString(), 'Bank settlement '.$line->external_id, [
                ['ledger_account_id' => $incoming ? $bank->id : $clearing->id, 'debit' => $amount, 'credit' => '0'],
                ['ledger_account_id' => $incoming ? $clearing->id : $bank->id, 'debit' => '0', 'credit' => $amount],
            ], 'bank.settled');
            $settlement = BankSettlement::create(['organization_id' => $organization->id, 'bank_statement_line_id' => $line->id, 'journal_entry_id' => $entry->id, 'approved_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.bank_statement.settled', $settlement, ['journal_entry_id' => $entry->id]);
        });
    }

    public function reverseSettlement(Organization $organization, User $actor, BankStatementLine $statementLine, string $date): void
    {
        DB::transaction(function () use ($organization, $actor, $statementLine, $date): void {
            $line = BankStatementLine::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($statementLine->id);
            $settlement = BankSettlement::where('bank_statement_line_id', $line->id)->whereNull('reversal_journal_entry_id')->lockForUpdate()->firstOrFail();
            $reversal = $this->ledger->reverse($organization, $actor, $settlement->journalEntry, $date, true);
            $settlement->update(['reversal_journal_entry_id' => $reversal->id, 'reversed_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'accounting.bank_statement.settlement_reversed', $settlement, ['reversal_journal_entry_id' => $reversal->id]);
        });
    }
}
