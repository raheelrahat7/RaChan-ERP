<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Models\LeaseSecurityDeposit;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateSecurityDeposit
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Organization $org, User $actor, Lease $lease, array $input): LeaseSecurityDeposit
    {
        return DB::transaction(function () use ($org, $actor, $lease, $input) {
            $locked = Lease::where('organization_id', $org->id)->lockForUpdate()->findOrFail($lease->id);
            abort_if(LeaseSecurityDeposit::where('lease_id', $locked->id)->exists(), 422, 'This lease already has a security-deposit schedule.');
            $invoice = Invoice::create(['organization_id' => $org->id, 'contact_id' => $locked->contact_id, 'reference' => 'INV-'.Str::upper(Str::random(8)), 'due_on' => $input['due_on'], 'subtotal' => $input['amount'], 'total' => $input['amount'], 'accounting_treatment' => 'refundable_deposit', 'vat_treatment' => $org->vat_enabled ? 'out_of_scope' : null, 'vat_rate' => $org->vat_enabled ? 0 : null, 'vat_amount' => $org->vat_enabled ? 0 : null]);
            InvoiceLine::create(['invoice_id' => $invoice->id, 'description' => 'Security deposit for lease '.$locked->reference, 'quantity' => 1, 'unit_price' => $input['amount'], 'line_total' => $input['amount']]);
            $deposit = LeaseSecurityDeposit::create(['organization_id' => $org->id, 'lease_id' => $locked->id, 'invoice_id' => $invoice->id, 'required_amount' => $input['amount'], 'due_on' => $input['due_on'], 'notes' => $input['notes'] ?? null, 'created_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'leasing.security_deposit.created', $deposit, ['invoice_id' => $invoice->id]);
            $this->audit->handle($org, $actor, 'finance.invoice.created', $invoice, ['source' => 'lease_security_deposit']);

            return $deposit;
        });
    }
}
