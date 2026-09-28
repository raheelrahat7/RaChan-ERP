<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\CorporateTaxReturn;
use App\Domain\Accounting\Models\JournalMappingApprover;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Models\LegacyJournalMapping;
use App\Domain\Accounting\Models\VatReturn;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageLegacyJournalMapping
{
    public function __construct(private RecordOrganizationAuditLog $audit, private AccountingLedger $ledger) {}

    public function canApprove(Organization $organization, User $actor): bool
    {
        return $actor->belongsToOrganization($organization) && ($actor->hasOrganizationRole($organization, OrganizationRole::Owner)
            || JournalMappingApprover::where('organization_id', $organization->id)->where('user_id', $actor->id)->whereNull('revoked_at')->exists());
    }

    public function delegate(Organization $organization, User $actor, int $userId, bool $allowed): void
    {
        DB::transaction(function () use ($organization, $actor, $userId, $allowed): void {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasOrganizationRole($organization, OrganizationRole::Owner), 403);
            $member = $organization->users()->findOrFail($userId);
            $this->check(! $member->hasOrganizationRole($organization, OrganizationRole::Owner), 'user_id', 'Owners already have approval authority.');
            $grant = JournalMappingApprover::updateOrCreate(['organization_id' => $organization->id, 'user_id' => $member->id], ['granted_by' => $actor->id, 'revoked_at' => $allowed ? null : now()]);
            $this->audit->handle($organization, $actor, $allowed ? 'accounting.mapping.approver_granted' : 'accounting.mapping.approver_revoked', $grant, ['user_id' => $member->id]);
        });
    }

    public function revokeForRemovedMember(Organization $organization, User $actor, User $member): void
    {
        Gate::forUser($actor)->authorize('manageMembers', $organization);
        Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
        $grant = JournalMappingApprover::where('organization_id', $organization->id)->where('user_id', $member->id)->whereNull('revoked_at')->first();
        if ($grant) {
            $grant->update(['revoked_at' => now()]);
            $this->audit->handle($organization, $actor, 'accounting.mapping.approver_revoked', $grant, ['user_id' => $member->id, 'reason' => 'Organization membership removed']);
        }
    }

    /** @param list<array{ledger_account_id: int, debit: string|int|float, credit: string|int|float}> $lines */
    public function submit(Organization $organization, User $actor, JournalEntry $entry, array $lines, string $reason, string $evidence): LegacyJournalMapping
    {
        Gate::forUser($actor)->authorize('manageFinance', $organization);

        return DB::transaction(function () use ($organization, $actor, $entry, $lines, $reason, $evidence): LegacyJournalMapping {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $source = JournalEntry::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($entry->id);
            $this->eligible($source);
            $this->check(! LegacyJournalMapping::where('journal_entry_id', $source->id)->where('status', 'submitted')->exists(), 'mapping', 'This journal already has a submitted proposal.');
            $this->validateLines($organization, $source, $lines);
            $lines = array_map(fn (array $line): array => ['ledger_account_id' => (int) $line['ledger_account_id'], 'debit' => number_format((float) $line['debit'], 2, '.', ''), 'credit' => number_format((float) $line['credit'], 2, '.', '')], $lines);
            Validator::make(['reason' => trim($reason), 'evidence_reference' => trim($evidence)], ['reason' => 'required|string|max:2000', 'evidence_reference' => 'required|string|max:255'])->validate();
            $mapping = LegacyJournalMapping::create([
                'organization_id' => $organization->id, 'journal_entry_id' => $source->id,
                'source_snapshot' => $this->snapshot($source), 'lines' => $lines, 'reason' => trim($reason),
                'evidence_reference' => trim($evidence), 'submitted_by' => $actor->id, 'status' => 'submitted',
            ]);
            $this->audit->handle($organization, $actor, 'accounting.mapping.submitted', $mapping, ['source' => $mapping->source_snapshot, 'lines' => $lines, 'evidence_reference' => trim($evidence)]);

            return $mapping;
        });
    }

    public function decide(Organization $organization, User $actor, LegacyJournalMapping $mapping, bool $approve, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $mapping, $approve, $reason): void {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canApprove($organization, $actor), 403);
            $locked = LegacyJournalMapping::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($mapping->id);
            $source = JournalEntry::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($locked->journal_entry_id);
            $this->check($locked->status === 'submitted', 'mapping', 'Only a submitted proposal can be decided.');
            $this->check($locked->submitted_by !== $actor->id, 'mapping', 'The requester cannot approve or reject their own proposal.');
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:2000'])->validate();
            if ($approve) {
                $this->eligible($source);
                $currentSnapshot = $this->snapshot($source);
                $submittedSnapshot = $locked->source_snapshot;
                ksort($currentSnapshot);
                ksort($submittedSnapshot);
                $this->check($currentSnapshot === $submittedSnapshot, 'mapping', 'The source changed after submission. Reject and submit a new proposal.');
                $this->validateLines($organization, $source, $locked->lines);
                $period = AccountingPeriod::where('organization_id', $organization->id)->where('starts_on', '<=', $source->posted_on)->where('ends_on', '>=', $source->posted_on)->lockForUpdate()->first();
                $this->check($period !== null && $period->status === 'open', 'mapping', 'The original date must be in an open accounting period.');
                // Filed snapshots must not be silently restated by historical allocations.
                $this->check(! VatReturn::where('organization_id', $organization->id)->where('status', 'filed')->where('starts_on', '<=', $source->posted_on)->where('ends_on', '>=', $source->posted_on)->exists()
                    && ! CorporateTaxReturn::where('organization_id', $organization->id)->where('status', 'filed')->where('ends_on', '>=', $source->posted_on)->exists(), 'mapping', 'This date affects a filed return. Resolve its correction treatment before historical allocation.');
                foreach ($locked->lines as $line) {
                    $source->lines()->create([...$line, 'description' => 'Approved historical mapping #'.$locked->id]);
                }
            }
            $locked->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => trim($reason)]);
            $this->audit->handle($organization, $actor, $approve ? 'accounting.mapping.approved' : 'accounting.mapping.rejected', $locked, ['source' => $locked->source_snapshot, 'lines' => $locked->lines, 'reason' => trim($reason), 'delegated' => ! $actor->hasOrganizationRole($organization, OrganizationRole::Owner)]);
        });
    }

    public function reverse(Organization $organization, User $actor, LegacyJournalMapping $mapping, string $date, string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $mapping, $date, $reason): void {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canApprove($organization, $actor), 403);
            $locked = LegacyJournalMapping::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($mapping->id);
            $this->check($locked->status === 'approved', 'mapping', 'Only an approved mapping can be reversed.');
            $source = JournalEntry::where('organization_id', $organization->id)->findOrFail($locked->journal_entry_id);
            Validator::make(['posted_on' => $date, 'reason' => trim($reason)], ['posted_on' => 'required|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$source->posted_on->toDateString(), 'reason' => 'required|string|max:2000'])->validate();
            $this->check(! BankStatementLine::where('organization_id', $organization->id)->whereIn('matched_journal_line_id', $source->lines()->select('id'))->exists(), 'mapping', 'Unmatch any bank statement rows before reversing this mapping.');
            $reversal = $this->ledger->reverse($organization, $actor, $source, $date, legacyMapping: true);
            $locked->update(['status' => 'reversed', 'reversed_by' => $actor->id, 'reversal_journal_entry_id' => $reversal->id, 'reversal_reason' => trim($reason)]);
            $this->audit->handle($organization, $actor, 'accounting.mapping.reversed', $locked, ['journal_entry_id' => $source->id, 'reversal_journal_entry_id' => $reversal->id, 'reason' => trim($reason)]);
        });
    }

    private function eligible(JournalEntry $entry): void
    {
        $this->check($entry->currency === 'AED' && ! $entry->lines()->exists() && $entry->reversal_of_id === null && $entry->event !== 'journal.reversed'
            && ! JournalEntry::where('reversal_of_id', $entry->id)->exists(), 'mapping', 'Choose an unreversed AED summary with no account lines.');
        $this->check($entry->posted_on->toDateString() <= today()->toDateString(), 'mapping', 'Future journals cannot be allocated.');
    }

    /** @return array<string, string|int|null> */
    private function snapshot(JournalEntry $entry): array
    {
        return ['id' => $entry->id, 'reference' => $entry->reference, 'posted_on' => $entry->posted_on->toDateString(), 'currency' => $entry->currency, 'debit_total' => $entry->debit_total, 'credit_total' => $entry->credit_total, 'event' => $entry->event, 'subject_type' => $entry->subject_type, 'subject_id' => $entry->subject_id, 'accounting_period_id' => $entry->accounting_period_id, 'source_reference' => $entry->source_reference, 'reversal_of_id' => $entry->reversal_of_id];
    }

    /** @param list<array{ledger_account_id: int, debit: string|int|float, credit: string|int|float}> $lines */
    private function validateLines(Organization $organization, JournalEntry $source, array $lines): void
    {
        Validator::make(['lines' => $lines], ['lines' => 'required|array|list|min:2|max:100', 'lines.*' => 'array:ledger_account_id,debit,credit', 'lines.*.ledger_account_id' => 'required|integer', 'lines.*.debit' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2', 'lines.*.credit' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2'])->validate();
        $ids = array_unique(array_column($lines, 'ledger_account_id'));
        $accounts = LedgerAccount::where('organization_id', $organization->id)->whereIn('id', $ids)->where('is_active', true)->lockForUpdate()->get();
        $this->check($accounts->count() === count($ids), 'lines', 'Every account must be active and belong to this organization.');
        $debit = $credit = 0;
        foreach ($lines as $line) {
            $d = (int) round((float) $line['debit'] * 100);
            $c = (int) round((float) $line['credit'] * 100);
            $this->check(($d > 0) !== ($c > 0), 'lines', 'Each line must have exactly one positive side.');
            $debit += $d;
            $credit += $c;
        }
        $this->check($debit > 0 && $debit === $credit && $debit === (int) round((float) $source->debit_total * 100) && $credit === (int) round((float) $source->credit_total * 100), 'lines', 'Allocations must balance and equal the original summary totals exactly.');
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
