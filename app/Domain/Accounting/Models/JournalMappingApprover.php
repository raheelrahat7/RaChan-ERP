<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'granted_by', 'revoked_at'])]
class JournalMappingApprover extends Model
{
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
