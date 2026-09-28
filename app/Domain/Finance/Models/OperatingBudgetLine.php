<?php

namespace App\Domain\Finance\Models;

use App\Domain\Accounting\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['operating_budget_id', 'ledger_account_id', 'month', 'amount'])]
class OperatingBudgetLine extends Model
{
    protected function casts(): array
    {
        return ['month' => 'integer', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return BelongsTo<OperatingBudget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(OperatingBudget::class, 'operating_budget_id');
    }
}
