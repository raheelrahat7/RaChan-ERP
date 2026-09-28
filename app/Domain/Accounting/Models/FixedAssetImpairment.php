<?php

namespace App\Domain\Accounting\Models;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'fixed_asset_id', 'fixed_asset_review_id', 'posted_on', 'effective_month', 'amount', 'journal_entry_id', 'reversal_journal_entry_id', 'approved_by', 'reversed_by'])]
class FixedAssetImpairment extends Model
{
    /** @return BelongsTo<FixedAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** @return BelongsTo<FixedAssetReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(FixedAssetReview::class, 'fixed_asset_review_id');
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
        return ['posted_on' => 'date', 'amount' => 'decimal:2'];
    }
}
