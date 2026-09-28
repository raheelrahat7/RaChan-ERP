<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['organization_id', 'documentable_type', 'documentable_id', 'name', 'category', 'expires_on', 'path'])]
class ComplianceDocument extends Model
{
    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }
}
