<?php

namespace App\Domain\Identity\Enums;

enum OrganizationPermission: string
{
    case ViewDashboard = 'dashboard.view';
    case ManageOrganization = 'organization.manage';
    case ManageMembers = 'members.manage';
    case ManageSettings = 'settings.manage';
    case ViewCrm = 'crm.view';
    case ManageCrm = 'crm.manage';
    case ManageCrmPipelines = 'crm.pipelines.manage';
    case ViewInventory = 'inventory.view';
    case ManageInventory = 'inventory.manage';
    case ViewTransactions = 'transactions.view';
    case ManageTransactions = 'transactions.manage';
    case ViewFinance = 'finance.view';
    case ManageFinance = 'finance.manage';
    case ViewOperations = 'operations.view';
    case ManageOperations = 'operations.manage';
}
