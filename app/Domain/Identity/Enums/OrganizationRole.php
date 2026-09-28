<?php

namespace App\Domain\Identity\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Manager = 'manager';
    case Member = 'member';
    case Viewer = 'viewer';

    /** @return list<OrganizationPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Administrator => OrganizationPermission::cases(),
            self::Manager => [
                OrganizationPermission::ViewDashboard,
                OrganizationPermission::ViewCrm,
                OrganizationPermission::ManageCrm,
                OrganizationPermission::ViewInventory,
                OrganizationPermission::ManageInventory,
                OrganizationPermission::ViewTransactions,
                OrganizationPermission::ManageTransactions,
                OrganizationPermission::ViewFinance,
                OrganizationPermission::ManageFinance,
                OrganizationPermission::ViewOperations,
                OrganizationPermission::ManageOperations,
            ],
            self::Member, self::Viewer => [
                OrganizationPermission::ViewDashboard,
                OrganizationPermission::ViewCrm,
                OrganizationPermission::ViewInventory,
                OrganizationPermission::ViewTransactions,
                OrganizationPermission::ViewFinance,
                OrganizationPermission::ViewOperations,
            ],
        };
    }

    public function grants(OrganizationPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
