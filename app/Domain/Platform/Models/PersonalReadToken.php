<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'name', 'token_hash', 'abilities', 'expires_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class PersonalReadToken extends Model
{
    protected function casts(): array
    {
        return ['abilities' => 'array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
