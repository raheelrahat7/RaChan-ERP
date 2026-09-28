<?php

namespace App\Domain\Accounting\Models;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'vat_return_id', 'bank_account_id', 'type', 'output_vat', 'input_vat', 'net_vat', 'journal_entry_id', 'reversal_journal_entry_id', 'approved_by', 'reversed_by', 'receipt_journal_entry_id', 'receipt_reversal_journal_entry_id'])]
class VatSettlement extends Model
{
    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    protected function casts(): array
    {
        return ['output_vat' => 'decimal:2', 'input_vat' => 'decimal:2', 'net_vat' => 'decimal:2'];
    }
}
