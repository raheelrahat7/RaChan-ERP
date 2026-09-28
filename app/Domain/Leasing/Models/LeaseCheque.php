<?php

namespace App\Domain\Leasing\Models;

use App\Models\Lease;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'lease_id', 'replacement_of_id', 'cheque_number', 'bank_name', 'payer_name', 'amount', 'due_on', 'status', 'deposited_on', 'cleared_on', 'bounced_on', 'bounce_reason', 'created_by', 'updated_by'])]
class LeaseCheque extends Model
{
    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return BelongsTo<self, $this> */
    public function replacementOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_of_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_on' => 'date', 'deposited_on' => 'date', 'cleared_on' => 'date', 'bounced_on' => 'date'];
    }
}
