<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageRentInvoiceLink
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function link(Organization $org, User $actor, int $leaseId, int $invoiceId, string $reason): void
    {
        abort_unless($actor->can('manageTransactions', $org) && $actor->can('viewFinance', $org), 403);
        DB::transaction(function () use ($org, $actor, $leaseId, $invoiceId, $reason): void {
            $lease = Lease::where('organization_id', $org->id)->lockForUpdate()->findOrFail($leaseId);
            $invoice = Invoice::where('organization_id', $org->id)->lockForUpdate()->findOrFail($invoiceId);
            if ($lease->contact_id === null || $invoice->contact_id !== $lease->contact_id
                || $invoice->accounting_treatment !== 'revenue' || ! in_array($invoice->status, ['posted', 'partial', 'paid'], true)
                || $invoice->due_on === null) {
                throw ValidationException::withMessages(['invoice_id' => 'Choose a posted revenue invoice with a due date and the same lease contact.']);
            }
            if (DB::table('lease_rent_invoices')->where('invoice_id', $invoiceId)->exists()) {
                throw ValidationException::withMessages(['invoice_id' => 'This invoice is already linked to a lease.']);
            }
            DB::table('lease_rent_invoices')->insert([
                'organization_id' => $org->id, 'lease_id' => $lease->id, 'invoice_id' => $invoice->id,
                'linked_by' => $actor->id, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'leasing.rent_invoice.linked', $lease, ['invoice_id' => $invoice->id, 'reason' => $reason]);
        });
    }

    public function unlink(Organization $org, User $actor, int $leaseId, int $invoiceId, string $reason): void
    {
        abort_unless($actor->can('manageTransactions', $org) && $actor->can('viewFinance', $org), 403);
        DB::transaction(function () use ($org, $actor, $leaseId, $invoiceId, $reason): void {
            $lease = Lease::where('organization_id', $org->id)->lockForUpdate()->findOrFail($leaseId);
            $deleted = DB::table('lease_rent_invoices')->where('organization_id', $org->id)->where('lease_id', $lease->id)
                ->where('invoice_id', $invoiceId)->delete();
            abort_unless($deleted === 1, 404);
            $this->audit->handle($org, $actor, 'leasing.rent_invoice.unlinked', $lease, ['invoice_id' => $invoiceId, 'reason' => $reason]);
        });
    }
}
