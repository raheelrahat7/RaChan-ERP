<?php

namespace App\Domain\Leasing\Models;

use App\Models\Lease;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'lease_id', 'renewal_of_id', 'status', 'ejari_number', 'applied_on', 'registered_on', 'expires_on', 'notes', 'created_by', 'updated_by'])]
class LeaseEjariRegistration extends Model
{
    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return BelongsTo<self, $this> */
    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_of_id');
    }

    protected function casts(): array
    {
        return ['applied_on' => 'date', 'registered_on' => 'date', 'expires_on' => 'date'];
    }
}
