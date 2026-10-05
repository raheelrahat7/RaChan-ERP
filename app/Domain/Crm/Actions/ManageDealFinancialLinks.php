<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CommissionTransaction;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageDealFinancialLinks
{
    public const REQUIREMENTS = ['invoice_linked', 'invoice_settled', 'commission_linked', 'commission_paid'];

    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, Deal $deal, array $input): void
    {
        $data = Validator::make($input, ['kind' => ['required', 'in:invoice,commission'], 'record_id' => ['required', 'integer'], 'expected_version' => ['required', 'integer', 'min:1'], 'remove' => ['sometimes', 'boolean']])->validate();
        DB::transaction(function () use ($org, $actor, $deal, $data): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $deal = app(DealAccess::class)->query($org, $actor)->lockForUpdate()->findOrFail($deal->id);
            abort_unless(app(DealAccess::class)->allows($org, $actor, $deal->pipeline, 'edit', $deal->assigned_to) && app(DealAccess::class)->allows($org, $actor, $deal->pipeline, 'amount', $deal->assigned_to), 403);
            Gate::forUser($actor)->authorize($data['kind'] === 'invoice' ? 'manageFinance' : 'manageTransactions', $org);
            if ($deal->version !== $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'Refresh the deal before linking financial records.']);
            }
            $model = $data['kind'] === 'invoice' ? Invoice::class : CommissionTransaction::class;
            $model::where('organization_id', $org->id)->findOrFail($data['record_id']);
            $attributes = ['organization_id' => $org->id, 'deal_id' => $deal->id, $data['kind'].'_id' => $data['record_id']];
            if ($data['remove'] ?? false) {
                DB::table('crm_deal_financial_links')->where($attributes)->delete();
                $this->requirement($org, $actor, $deal, $deal->stage->financial_requirement);
            } else {
                DB::table('crm_deal_financial_links')->updateOrInsert($attributes, ['linked_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $deal->increment('version');
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.deal.financial_link_changed', $deal, $data);
        });
    }

    /** @return array{invoices: list<array<string, mixed>>, commissions: list<array<string, mixed>>} */
    public function summary(Organization $org, User $actor, Deal $deal): array
    {
        $access = app(DealAccess::class);
        abort_unless($deal->organization_id === $org->id && $access->allows($org, $actor, $deal->pipeline, 'read', $deal->assigned_to), 404);
        if (! $access->allows($org, $actor, $deal->pipeline, 'amount', $deal->assigned_to)) {
            return ['invoices' => [], 'commissions' => []];
        }
        $links = DB::table('crm_deal_financial_links')->where('organization_id', $org->id)->where('deal_id', $deal->id)->get();
        $invoices = $actor->can('viewFinance', $org) ? Invoice::where('organization_id', $org->id)->whereIn('id', $links->pluck('invoice_id')->filter())->withSum('payments', 'amount')->get()->map(fn (Invoice $invoice) => [...$invoice->only('id', 'reference', 'status', 'total', 'currency'), 'received' => $invoice->payments_sum_amount ?? '0.00', 'outstanding' => number_format(app(InvoiceBalance::class)->outstandingCents($invoice) / 100, 2, '.', '')])->all() : [];
        $commissions = $actor->can('viewTransactions', $org) ? CommissionTransaction::where('organization_id', $org->id)->whereIn('id', $links->pluck('commission_id')->filter())->get()->map->only(['id', 'status', 'commission_amount', 'currency', 'paid_on'])->all() : [];

        return ['invoices' => array_values($invoices), 'commissions' => array_values($commissions)];
    }

    public function requirement(Organization $org, User $actor, Deal $deal, ?string $requirement): void
    {
        if (! $requirement) {
            return;
        }
        $summary = $this->summary($org, $actor, $deal);
        $valid = match ($requirement) {
            'invoice_linked' => $summary['invoices'] !== [],
            'invoice_settled' => $summary['invoices'] !== [] && collect($summary['invoices'])->every(fn ($invoice) => in_array($invoice['status'], ['posted', 'partial', 'paid'], true) && $invoice['outstanding'] === '0.00'),
            'commission_linked' => $summary['commissions'] !== [],
            'commission_paid' => $summary['commissions'] !== [] && collect($summary['commissions'])->every(fn ($commission) => $commission['paid_on'] !== null),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['stage_id' => 'The linked financial records do not satisfy this stage requirement.']);
        }
    }
}
