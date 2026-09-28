<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'code', 'name', 'type', 'is_active'])]
class LedgerAccount extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
