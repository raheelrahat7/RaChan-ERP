<?php

namespace App\Support;

use App\Domain\Crm\Queries\FollowUpAlerts;
use App\Domain\Leasing\Models\LeaseEjariRegistration;
use App\Domain\Operations\Queries\OperationalAlerts;
use App\Models\ComplianceDocument;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;

class CurrentOperationalAlerts
{
    /** @return list<array{category: string, title: string, count: int, href: string}> */
    public function forOrganization(int $organizationId, ?User $actor = null): array
    {
        $today = today();
        $operations = app(OperationalAlerts::class)->counts(Organization::findOrFail($organizationId), $actor);

        return [
            ...app(FollowUpAlerts::class)->forOrganization($organizationId, $actor),
            ['category' => 'expiring_leases', 'title' => 'Expiring leases', 'count' => Lease::where('organization_id', $organizationId)->where('status', 'active')->whereBetween('ends_on', [$today, $today->copy()->addDays(60)])->count(), 'href' => '/agreements'],
            ['category' => 'expiring_documents', 'title' => 'Expiring documents', 'count' => ComplianceDocument::where('organization_id', $organizationId)->whereBetween('expires_on', [$today, $today->copy()->addDays(30)])->count(), 'href' => '/compliance-documents'],
            ['category' => 'expiring_ejari', 'title' => 'Expiring Ejari registrations', 'count' => LeaseEjariRegistration::where('organization_id', $organizationId)->where('status', 'registered')->whereBetween('expires_on', [$today, $today->copy()->addDays(60)])->count(), 'href' => '/lease-compliance'],
            ['category' => 'overdue_invoices', 'title' => 'Overdue invoices', 'count' => Invoice::where('organization_id', $organizationId)->whereIn('status', ['posted', 'partial'])->where('due_on', '<', $today)->count(), 'href' => '/invoices'],
            ['category' => 'overdue_vendor_bills', 'title' => 'Overdue vendor bills', 'count' => VendorBill::where('organization_id', $organizationId)->whereIn('status', ['posted', 'partial'])->where('due_on', '<', $today)->count(), 'href' => '/vendor-bills'],
            ['category' => 'overdue_maintenance', 'title' => 'Overdue maintenance', 'count' => $operations['overdue_maintenance'], 'href' => '/maintenance?filter=overdue'],
            ['category' => 'due_preventive_plans', 'title' => 'Due preventive plans', 'count' => $operations['due_preventive_plans'], 'href' => '/preventive-maintenance'],
        ];
    }

    /** @return list<string> */
    public function categories(): array
    {
        return ['overdue_crm_follow_ups', 'due_crm_follow_ups', 'expiring_leases', 'expiring_documents', 'expiring_ejari', 'overdue_invoices', 'overdue_vendor_bills', 'overdue_maintenance', 'due_preventive_plans'];
    }
}
