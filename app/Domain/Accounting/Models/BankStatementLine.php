<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'bank_account_id', 'imported_by', 'import_batch', 'external_id', 'occurred_on', 'description', 'amount', 'matched_journal_line_id', 'matched_by', 'matched_at'])]
class BankStatementLine extends Model
{
    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return HasMany<BankSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(BankSettlement::class);
    }

    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'amount' => 'decimal:2', 'matched_at' => 'datetime'];
    }
}
