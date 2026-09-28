<?php

namespace App\Domain\Accounting\Models;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'fixed_asset_id', 'proceeds_account_id', 'proceeds', 'carrying_amount', 'gain_loss', 'journal_entry_id', 'reversal_journal_entry_id', 'approved_by', 'reversed_by'])]
class FixedAssetDisposal extends Model
{
    /** @return BelongsTo<FixedAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function proceedsAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'proceeds_account_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return ['proceeds' => 'decimal:2', 'carrying_amount' => 'decimal:2', 'gain_loss' => 'decimal:2'];
    }
}
