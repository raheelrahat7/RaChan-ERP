<?php

namespace App\Domain\Crm\Services;

use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Identity\Services\SectionAccess;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CrmEditPermission
{
    public function granted(Organization $organization, User $user): bool
    {
        $role = $user->organizations()->whereKey($organization->id)->value('organization_user.role');
        if (! $role || ! app(SectionAccess::class)->enabled($organization, $user, OrganizationPermission::ManageCrm, $role)) {
            return false;
        }

        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageCrm)
            || ($user->belongsToOrganization($organization) && DB::table('crm_edit_grants')
                ->where('organization_id', $organization->id)->where('user_id', $user->id)->exists());
    }
}
