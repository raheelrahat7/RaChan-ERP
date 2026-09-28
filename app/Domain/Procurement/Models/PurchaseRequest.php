<?php

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'property_id', 'requested_by', 'approved_by', 'reference', 'purpose', 'status', 'approved_at'])]
class PurchaseRequest extends Model
{
    /** @return HasMany<PurchaseRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class);
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}
