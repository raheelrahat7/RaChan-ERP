<?php

namespace App\Domain\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'vat_return_id', 'discovered_on', 'output_vat_delta', 'input_vat_delta', 'correction_method', 'reason', 'recorded_by'])]
class VatReturnAdjustment extends Model
{
    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return ['discovered_on' => 'date', 'output_vat_delta' => 'decimal:2', 'input_vat_delta' => 'decimal:2'];
    }
}
