<?php

namespace App\Domain\CustomerPortal\Queries;

use App\Domain\CustomerPortal\Models\PortalGrant;
use App\Domain\CustomerPortal\Models\PortalLeaseInvoice;
use App\Domain\CustomerPortal\Models\PortalServiceRequest;
use App\Domain\CustomerPortal\Services\PortalAccess;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Leasing\Queries\OwnerStatement;
use App\Domain\Operations\Services\JobCostAmount;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PortalOverview
{
    public function __construct(private PortalAccess $access, private InvoiceBalance $balances, private JobCostAmount $amounts, private OwnerStatement $statements) {}

    /** @return array<string,mixed> */
    public function for(User $actor, PortalGrant $grant, string $from, string $to): array
    {
        $grant = $this->access->grant($actor, $grant->id);
        $org = Organization::findOrFail($grant->organization_id);
        $base = ['grant' => $grant->only(['id', 'role']), 'portalOrganization' => $org->only(['id', 'name']), 'from' => $from, 'to' => $to];
        if ($grant->role === 'landlord') {
            $owner = Owner::where('organization_id', $org->id)->findOrFail($grant->owner_id);
            $properties = $owner->properties()->where('properties.organization_id', $org->id)->orderBy('name')->get(['properties.id', 'properties.name', 'properties.city', 'properties.address_line_1']);
            $jobs = MaintenanceRequest::where('organization_id', $org->id)->whereIn('property_id', $properties->modelKeys())->latest('id')->paginate(20)->withQueryString()->through(fn (MaintenanceRequest $job): array => $job->only(['id', 'reference', 'title', 'status', 'priority', 'property_id']));

            return [...$base, 'profile' => $owner->only(['id', 'name']), 'properties' => $properties->map(fn ($p) => $p->only(['id', 'name', 'city', 'address_line_1'])), 'leases' => [], 'invoices' => null, 'serviceRequests' => null, 'serviceSummaries' => $jobs, 'statement' => $this->statements->for($org, $owner, Carbon::parse($from), Carbon::parse($to))];
        }
        $tenant = Tenant::where('organization_id', $org->id)->findOrFail($grant->tenant_id);
        $leases = Lease::where('organization_id', $org->id)->where('tenant_id', $tenant->id)->orderByDesc('starts_on')->get();
        $leaseIds = $leases->modelKeys();
        $units = Unit::where('organization_id', $org->id)->whereIn('id', $leases->pluck('unit_id'))->get(['id', 'number'])->keyBy('id');
        $invoiceIds = PortalLeaseInvoice::where('organization_id', $org->id)->whereNull('revoked_at')->whereIn('lease_id', $leaseIds)->pluck('invoice_id')
            ->merge(DB::table('lease_service_charges')->where('organization_id', $org->id)->whereIn('lease_id', $leaseIds)->pluck('invoice_id'))
            ->merge(DB::table('lease_security_deposits')->where('organization_id', $org->id)->whereIn('lease_id', $leaseIds)->pluck('invoice_id'))->unique();
        $invoices = Invoice::where('organization_id', $org->id)->whereIn('id', $invoiceIds)->whereIn('contact_id', $leases->pluck('contact_id')->filter()->unique())->whereIn('status', ['posted', 'partial', 'paid'])->withSum(['payments' => fn ($q) => $q->where('organization_id', $org->id)], 'amount')->latest('issued_on')->paginate(20, ['*'], 'invoice_page')->withQueryString()->through(fn (Invoice $invoice): array => [...$invoice->only(['id', 'reference', 'status', 'total', 'currency']), 'issued_on' => $invoice->issued_on?->format('Y-m-d'), 'due_on' => $invoice->due_on?->format('Y-m-d'), 'outstanding' => $this->amounts->format($this->balances->outstandingCents($invoice))]);
        $ownGrantIds = PortalGrant::where('organization_id', $org->id)->where('user_id', $actor->id)->where('tenant_id', $tenant->id)->pluck('id');
        $requests = PortalServiceRequest::where('organization_id', $org->id)->whereIn('portal_grant_id', $ownGrantIds)->latest('id')->paginate(20, ['*'], 'request_page')->withQueryString()->through(function (PortalServiceRequest $record) use ($org): array {
            $job = MaintenanceRequest::where('organization_id', $org->id)->find($record->maintenance_request_id);

            return [...$record->only(['id', 'title', 'description', 'priority', 'created_at']), 'reference' => $job?->reference, 'status' => $job === null ? 'unavailable' : $job->status];
        });

        return [...$base, 'profile' => $tenant->only(['id', 'name']), 'properties' => [], 'leases' => $leases->map(fn (Lease $lease): array => [...$lease->only(['id', 'reference', 'status', 'rent_amount', 'currency']), 'unit' => $units->get($lease->unit_id)?->number, 'starts_on' => $lease->starts_on->format('Y-m-d'), 'ends_on' => $lease->ends_on->format('Y-m-d')]), 'invoices' => $invoices, 'serviceRequests' => $requests, 'serviceSummaries' => null, 'statement' => null];
    }
}
