<?php

namespace App\Domain\Accounting\Models;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{ledger_account_id: int, debit: string|int|float, credit: string|int|float}> $lines
 * @property array<string, string|int|null> $source_snapshot
 */
#[Fillable(['organization_id', 'journal_entry_id', 'status', 'source_snapshot', 'lines', 'reason', 'evidence_reference', 'submitted_by', 'decided_by', 'decided_at', 'decision_reason', 'reversed_by', 'reversal_journal_entry_id', 'reversal_reason'])]
class LegacyJournalMapping extends Model
{
    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'lines' => 'array', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
