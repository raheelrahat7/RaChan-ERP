<?php

namespace App\Domain\Accounting\Models;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_entry_id', 'ledger_account_id', 'description', 'debit', 'credit'])]
class JournalLine extends Model
{
    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }
}
