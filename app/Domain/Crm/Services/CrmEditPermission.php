<?php

namespace App\Domain\Crm\Services;

use App\Domain\Identity\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CrmEditPermission
{
    public function granted(Organization $organization, User $user): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageCrm)
            || ($user->belongsToOrganization($organization) && DB::table('crm_edit_grants')
                ->where('organization_id', $organization->id)->where('user_id', $user->id)->exists());
    }
}
