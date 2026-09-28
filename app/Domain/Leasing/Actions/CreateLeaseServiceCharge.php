<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Models\LeaseServiceCharge;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateLeaseServiceCharge
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Organization $org, User $actor, Lease $lease, array $input): LeaseServiceCharge
    {
        return DB::transaction(function () use ($org, $actor, $lease, $input) {
            $locked = Lease::where('organization_id', $org->id)->lockForUpdate()->findOrFail($lease->id);
            abort_unless(in_array($locked->status, ['draft', 'active'], true), 422, 'Service charges require a current lease.');
            $rate = $org->vat_enabled && $input['vat_treatment'] === 'standard' ? 5 : 0;
            $vat = round((float) $input['net_amount'] * $rate / 100, 2);
            $label = str_replace('_', ' ', $input['category']).' '.$input['period_starts_on'].' to '.$input['period_ends_on'];
            $invoice = Invoice::create(['organization_id' => $org->id, 'contact_id' => $locked->contact_id, 'reference' => 'INV-'.Str::upper(Str::random(8)), 'due_on' => $input['due_on'], 'subtotal' => $input['net_amount'], 'total' => (float) $input['net_amount'] + $vat, 'accounting_treatment' => 'revenue', 'vat_treatment' => $org->vat_enabled ? $input['vat_treatment'] : null, 'vat_rate' => $org->vat_enabled ? $rate : null, 'vat_amount' => $org->vat_enabled ? $vat : null]);
            InvoiceLine::create(['invoice_id' => $invoice->id, 'description' => ucfirst($label).' · lease '.$locked->reference, 'quantity' => 1, 'unit_price' => $input['net_amount'], 'line_total' => $input['net_amount']]);
            $charge = LeaseServiceCharge::create(['organization_id' => $org->id, 'lease_id' => $locked->id, 'invoice_id' => $invoice->id, ...$input, 'created_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'leasing.service_charge.created', $charge, ['invoice_id' => $invoice->id, 'gross_amount' => $invoice->total]);
            $this->audit->handle($org, $actor, 'finance.invoice.created', $invoice, ['source' => 'lease_service_charge']);

            return $charge;
        });
    }
}
