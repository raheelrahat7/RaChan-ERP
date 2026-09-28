<?php

namespace App\Models;

use App\Domain\Accounting\Models\JournalLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'accounting_period_id', 'reversal_of_id', 'reference', 'source_reference', 'event', 'subject_type', 'subject_id', 'posted_on', 'debit_total', 'credit_total', 'currency'])]
class JournalEntry extends Model
{
    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    protected function casts(): array
    {
        return ['posted_on' => 'date', 'debit_total' => 'decimal:2', 'credit_total' => 'decimal:2'];
    }
}
