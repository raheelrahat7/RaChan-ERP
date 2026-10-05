<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Configuration\Services\AllocateReferenceNumber;
use App\Domain\Finance\Services\EstimatePricing;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateInvoiceDraft
{
    /** @param array<string, mixed> $input */
    public function handle(Organization $org, User $actor, array $input): Invoice
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        $data = Validator::make($input, ['description' => ['required', 'string', 'max:255'], 'quantity' => ['required'], 'unit_price' => ['required', 'numeric', 'gt:0'], 'due_on' => ['required', 'date'], 'accounting_treatment' => ['required', 'in:revenue,refundable_deposit'], 'vat_treatment' => [Rule::requiredIf($org->vat_enabled), 'nullable', 'in:standard,zero_rated,exempt,out_of_scope']])->validate();
        $pricing = app(EstimatePricing::class)->calculate(['currency' => 'AED', 'lines' => [['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price' => $data['unit_price']]]]);

        return DB::transaction(function () use ($org, $actor, $data, $pricing): Invoice {
            $subtotal = $pricing['total_cents'];
            $rate = $org->vat_enabled && $data['vat_treatment'] === 'standard' ? 5 : 0;
            $vat = intdiv($subtotal * $rate + 50, 100);
            $money = fn (int $cents) => intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
            $invoice = Invoice::create(['organization_id' => $org->id, 'reference' => app(AllocateReferenceNumber::class)->handle($org, 'invoice', (string) Str::uuid()), 'due_on' => $data['due_on'], 'subtotal' => $money($subtotal), 'total' => $money($subtotal + $vat), 'accounting_treatment' => $data['accounting_treatment'], 'vat_treatment' => $org->vat_enabled ? $data['vat_treatment'] : null, 'vat_rate' => $org->vat_enabled ? $rate : null, 'vat_amount' => $org->vat_enabled ? $money($vat) : null]);
            InvoiceLine::create(['invoice_id' => $invoice->id, 'description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price' => $data['unit_price'], 'line_total' => $money($subtotal)]);
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'finance.invoice.created', $invoice);

            return $invoice;
        });
    }
}
