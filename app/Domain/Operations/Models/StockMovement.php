<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'spare_part_id', 'stock_store_id', 'maintenance_request_id', 'recorded_by', 'type', 'quantity', 'delta', 'reference', 'reason', 'operation_key', 'related_movement_id', 'reversed_by_movement_id'])]
class StockMovement extends Model
{
    /** @return BelongsTo<SparePart, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(SparePart::class, 'spare_part_id');
    }

    /** @return BelongsTo<StockStore, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(StockStore::class, 'stock_store_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'delta' => 'integer'];
    }
}
