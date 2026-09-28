<?php

namespace App\Domain\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'email', 'role', 'tenant_id', 'owner_id', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at', 'created_by'])]
class PortalInvitation extends Model
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
