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
                OrganizationPermission::ViewDocuments,
                OrganizationPermission::ViewInventoryCosts,
                OrganizationPermission::ViewDashboard,
                OrganizationPermission::ViewCrm,
                OrganizationPermission::ManageCrm,
                OrganizationPermission::ViewInventory,
                OrganizationPermission::ManageInventory,
                OrganizationPermission::ManageDocuments,
                OrganizationPermission::ManageInventoryCatalogue,
                OrganizationPermission::ManageInventoryCosts,
                OrganizationPermission::ImportInventory,
                OrganizationPermission::ExportInventory,
                OrganizationPermission::ViewTransactions,
                OrganizationPermission::ManageTransactions,
                OrganizationPermission::ViewFinance,
                OrganizationPermission::ManageFinance,
                OrganizationPermission::ViewOperations,
                OrganizationPermission::ManageOperations,
            ],
            self::Member, self::Viewer => [
                OrganizationPermission::ManageDocuments,
                OrganizationPermission::ExportInventory,
                OrganizationPermission::ViewDocuments,
                OrganizationPermission::ViewInventoryCosts,
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
