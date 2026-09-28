<?php

namespace App\Domain\CustomerPortal\Services;

use App\Domain\CustomerPortal\Models\PortalGrant;
use App\Models\User;

class PortalAccess
{
    public function grant(User $user, int $id, bool $lock = false): PortalGrant
    {
        abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email before using the portal.');
        $query = PortalGrant::where('user_id', $user->id)->whereNull('revoked_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }
}
