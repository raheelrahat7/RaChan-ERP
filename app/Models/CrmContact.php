<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** @property int $version */
#[Fillable(['organization_id', 'account_id', 'first_name', 'last_name', 'email', 'phone'])]
class CrmContact extends Model
{
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<CrmAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CrmAccount::class, 'account_id');
    }

    /** @return MorphMany<CrmActivity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'subject');
    }
}
