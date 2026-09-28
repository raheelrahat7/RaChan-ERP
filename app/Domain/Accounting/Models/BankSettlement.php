<?php

namespace App\Domain\Accounting\Models;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'bank_statement_line_id', 'journal_entry_id', 'reversal_journal_entry_id', 'approved_by', 'reversed_by'])]
class BankSettlement extends Model
{
    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
