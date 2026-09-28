<?php

namespace App\Domain\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'starts_on', 'ends_on', 'status', 'snapshot', 'prepared_by', 'filed_by', 'filed_on', 'fta_reference'])]
class VatReturn extends Model
{
    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    /** @return HasMany<VatReturnAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(VatReturnAdjustment::class);
    }

    /** @return HasOne<VatSettlement, $this> */
    public function settlement(): HasOne
    {
        return $this->hasOne(VatSettlement::class)->latestOfMany();
    }

    /** @return HasMany<VatSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(VatSettlement::class);
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'filed_on' => 'date', 'snapshot' => 'array'];
    }
}
