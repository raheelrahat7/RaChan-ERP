<?php

namespace App\Domain\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'portal_invitation_id', 'role', 'tenant_id', 'owner_id', 'revoked_at', 'revocation_reason'])]
class PortalGrant extends Model
{
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
