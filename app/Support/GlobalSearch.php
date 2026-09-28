<?php

namespace App\Support;

use App\Domain\Operations\Services\JobCardAccess;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use App\Models\VendorBill;

class GlobalSearch
{
    /** @return list<array{type: string, title: string, detail: string, href: string}> */
    public function search(Organization $organization, User $user, string $term): array
    {
        $pattern = '%'.$term.'%';
        $results = [];

        if ($user->can('viewCrm', $organization)) {
            foreach (CrmLead::where('organization_id', $organization->id)->where(fn ($query) => $query->where('first_name', 'like', $pattern)->orWhere('last_name', 'like', $pattern)->orWhere('email', 'like', $pattern)->orWhere('company', 'like', $pattern))->latest()->limit(10)->get() as $lead) {
                $results[] = ['type' => 'CRM lead', 'title' => trim($lead->first_name.' '.$lead->last_name), 'detail' => $lead->company ?? $lead->email ?? '', 'href' => '/crm/leads'];
            }
            foreach (CrmContact::where('organization_id', $organization->id)->where(fn ($query) => $query->where('first_name', 'like', $pattern)->orWhere('last_name', 'like', $pattern)->orWhere('email', 'like', $pattern))->latest()->limit(10)->get() as $contact) {
                $results[] = ['type' => 'CRM contact', 'title' => trim($contact->first_name.' '.$contact->last_name), 'detail' => $contact->email ?? '', 'href' => '/crm/contacts'];
            }
        }

        if ($user->can('viewInventory', $organization)) {
            foreach (Property::where('organization_id', $organization->id)->where(fn ($query) => $query->where('name', 'like', $pattern)->orWhere('city', 'like', $pattern)->orWhere('address_line_1', 'like', $pattern))->latest()->limit(10)->get() as $property) {
                $results[] = ['type' => 'Property', 'title' => $property->name, 'detail' => $property->city ?? '', 'href' => '/inventory/properties/'.$property->id];
            }
        }

        if ($user->can('viewFinance', $organization)) {
            foreach (Invoice::where('organization_id', $organization->id)->where('reference', 'like', $pattern)->latest()->limit(10)->get() as $invoice) {
                $results[] = ['type' => 'Invoice', 'title' => $invoice->reference, 'detail' => $invoice->status, 'href' => '/invoices'];
            }
            foreach (VendorBill::where('organization_id', $organization->id)->where(fn ($query) => $query->where('reference', 'like', $pattern)->orWhere('description', 'like', $pattern))->latest()->limit(10)->get() as $bill) {
                $results[] = ['type' => 'Vendor bill', 'title' => $bill->reference, 'detail' => $bill->description, 'href' => '/vendor-bills'];
            }
        }

        if ($user->can('viewOperations', $organization)) {
            foreach (app(JobCardAccess::class)->scope(MaintenanceRequest::query(), $organization, $user)->where(fn ($query) => $query->where('reference', 'like', $pattern)->orWhere('title', 'like', $pattern))->latest()->limit(10)->get() as $item) {
                $results[] = ['type' => 'Maintenance', 'title' => $item->reference, 'detail' => $item->title, 'href' => '/maintenance'];
            }
        }

        return $results;
    }
}
