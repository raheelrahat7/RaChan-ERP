<?php

namespace App\Policies;

use App\Domain\Crm\Services\CrmEditPermission;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageOrganization);
    }

    public function manageSettings(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageSettings);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageMembers);
    }

    public function viewCrm(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ViewCrm);
    }

    public function manageCrm(User $user, Organization $organization): bool
    {
        return app(CrmEditPermission::class)->granted($organization, $user);
    }

    public function manageCrmPipelines(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageCrmPipelines);
    }

    public function viewInventory(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ViewInventory);
    }

    public function manageInventory(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageInventory);
    }

    public function viewTransactions(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ViewTransactions);
    }

    public function manageTransactions(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageTransactions);
    }

    public function viewFinance(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ViewFinance);
    }

    public function manageFinance(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageFinance);
    }

    public function reopenAccountingPeriod(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationRole($organization, OrganizationRole::Owner);
    }

    public function viewOperations(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ViewOperations);
    }

    public function manageOperations(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::ManageOperations);
    }
}
