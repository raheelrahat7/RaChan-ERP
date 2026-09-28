<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'broker_id', 'commission_plan_id', 'source_type', 'source_id', 'base_amount', 'commission_amount', 'currency', 'status', 'payable_on', 'paid_on'])]
class CommissionTransaction extends Model
{
    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    protected function casts(): array
    {
        return ['base_amount' => 'decimal:2', 'commission_amount' => 'decimal:2', 'payable_on' => 'date', 'paid_on' => 'date'];
    }
}
