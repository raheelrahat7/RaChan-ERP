<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Configuration\Services\AllocateReferenceNumber;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Workflows\Models\WorkflowRecord;
use App\Domain\Workflows\Services\WorkflowAccess;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateInvoiceFromEstimate
{
    /** @param array<string, mixed> $input */
    public function handle(Organization $org, User $actor, int $recordId, array $input): Invoice
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        $data = Validator::make($input, ['expected_version' => ['required', 'integer'], 'due_on' => ['required', 'date_format:Y-m-d'], 'accounting_treatment' => ['required', 'in:revenue,refundable_deposit'], 'vat_treatment' => [Rule::requiredIf($org->vat_enabled), 'nullable', 'in:standard,zero_rated,exempt,out_of_scope']])->validate();

        return DB::transaction(function () use ($org, $actor, $recordId, $data): Invoice {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $record = WorkflowRecord::where('organization_id', $org->id)->lockForUpdate()->findOrFail($recordId);
            app(WorkflowAccess::class)->record($org, $actor, $record, true);
            abort_unless($record->pipeline->kind === 'estimate', 422);
            if ($record->invoice_id) {
                return Invoice::where('organization_id', $org->id)->findOrFail($record->invoice_id);
            }
            if ($record->version !== $data['expected_version'] || $record->stage->type !== 'success' || $record->details['currency'] !== 'AED') {
                throw ValidationException::withMessages(['record' => 'Choose a current accepted AED estimate; other currencies require a separate approved accounting conversion policy.']);
            }
            $subtotal = $record->details['total_cents'];
            $vatRate = $org->vat_enabled && $data['vat_treatment'] === 'standard' ? 5 : 0;
            $vat = intdiv($subtotal * $vatRate + 50, 100);
            $money = fn (int $cents) => intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
            $invoice = Invoice::create(['organization_id' => $org->id, 'contact_id' => $record->contact_id, 'reference' => app(AllocateReferenceNumber::class)->handle($org, 'invoice', (string) Str::uuid()), 'due_on' => $data['due_on'], 'subtotal' => $money($subtotal), 'total' => $money($subtotal + $vat), 'currency' => 'AED', 'status' => 'draft', 'accounting_treatment' => $data['accounting_treatment'], 'vat_treatment' => $org->vat_enabled ? $data['vat_treatment'] : null, 'vat_rate' => $org->vat_enabled ? $vatRate : null, 'vat_amount' => $org->vat_enabled ? $money($vat) : null]);
            foreach ($record->details['lines'] as $line) {
                InvoiceLine::create(['invoice_id' => $invoice->id, 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => $money($line['total_cents'])]);
            }
            $record->update(['invoice_id' => $invoice->id, 'version' => $record->version + 1]);
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'finance.estimate.draft_invoice_created', $invoice, ['estimate_id' => $record->id]);

            return $invoice;
        });
    }
}
