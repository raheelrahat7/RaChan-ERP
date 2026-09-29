<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PendingApprovals
{
    /** @return list<array<string, mixed>> */
    public function for(Organization $organization, User $actor): array
    {
        $owner = $actor->hasOrganizationRole($organization, OrganizationRole::Owner);
        $administrator = $actor->hasOrganizationRole($organization, OrganizationRole::Administrator);
        $items = [];
        if ($owner || $administrator) {
            $items = [...$items, ...$this->rows($organization, $actor, 'operating_budgets', 'Finance', 'Budget', 'submitted_by', 'accounting.budgets', 'accounting.budgets.approve', 'accounting.budgets.reject', null)];
        }
        if ($owner) {
            $items = [...$items, ...$this->commissionAllocations($organization, $actor)];
            foreach ([
                ['customer_refunds', 'Customer refund', 'customer-refunds.approve', 'customer-refunds.reject', 'invoices.index', 'amount'],
                ['vendor_credit_notes', 'Vendor credit', 'vendor-credit-notes.approve', 'vendor-credit-notes.reject', 'vendor-bills.index', 'amount'],
                ['vendor_cash_refunds', 'Vendor cash return', 'vendor-refunds.approve', 'vendor-refunds.reject', 'vendor-refunds.index', 'amount'],
                ['contractor_claims', 'Contractor claim', 'construction.claims.approve', 'construction.claims.reject', 'construction.index', 'amount_cents'],
            ] as [$table, $label, $approve, $reject, $index, $amount]) {
                $items = [...$items, ...$this->rows($organization, $actor, $table, 'Finance', $label, 'requested_by', $index, $approve, $reject, $amount)];
            }
            $items = [...$items, ...$this->rows($organization, $actor, 'lease_deposit_settlements', 'Leasing', 'Deposit settlement', 'submitted_by', 'lease-compliance.index', 'lease-compliance.deposit-settlements.approve', null, 'refund_amount')];
        }
        if ($actor->can('manageOperations', $organization)) {
            $items = [...$items, ...$this->rows($organization, $actor, 'purchase_requests', 'Procurement', 'Purchase request', 'requested_by', 'procurement.index', 'procurement.requests.approve', null, null)];
        }
        usort($items, fn (array $a, array $b): int => strcmp($b['submitted_at'], $a['submitted_at']));

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function commissionAllocations(Organization $org, User $actor): array
    {
        return array_values(DB::table('commission_allocations as allocation')
            ->join('commission_transactions as commission', 'commission.id', '=', 'allocation.commission_transaction_id')
            ->join('users as requester', 'requester.id', '=', 'allocation.requested_by')
            ->where('allocation.organization_id', $org->id)->where('commission.organization_id', $org->id)
            ->where('allocation.status', 'submitted')->where('allocation.requested_by', '!=', $actor->id)
            ->orderByDesc('allocation.created_at')
            ->get(['allocation.id', 'allocation.created_at', 'commission.commission_amount', 'requester.name as requested_by'])
            ->map(fn ($row): array => [
                'key' => 'commission_allocations:'.$row->id,
                'module' => 'Brokerage',
                'transaction' => 'Commission #'.$row->id,
                'amount' => round((float) $row->commission_amount, 2),
                'cost_centre' => null,
                'requested_by' => $row->requested_by,
                'status' => 'submitted',
                'submitted_at' => $row->created_at,
                'href' => route('real-estate.brokerage.index'),
                'approve_url' => route('real-estate.brokerage.allocations.approve', ['allocation' => $row->id]),
                'reject_url' => route('real-estate.brokerage.allocations.reject', ['allocation' => $row->id]),
                'reject_requires_reason' => true,
            ])->all());
    }

    /** @return list<array<string, mixed>> */
    private function rows(Organization $org, User $actor, string $table, string $module, string $label, string $requester, string $indexRoute, string $approveRoute, ?string $rejectRoute, ?string $amountColumn): array
    {
        return array_values(DB::table($table.' as item')->leftJoin('users as requester', 'requester.id', '=', 'item.'.$requester)
            ->where('item.organization_id', $org->id)->where('item.status', 'submitted')->where('item.'.$requester, '!=', $actor->id)
            ->orderByDesc('item.created_at')
            ->get(['item.id', in_array($table, ['operating_budgets', 'lease_deposit_settlements'], true) ? DB::raw('NULL AS reference') : 'item.reference', 'item.created_at', 'requester.name as requested_by', ...($amountColumn ? ['item.'.$amountColumn.' as amount'] : [])])
            ->map(function ($row) use ($table, $module, $label, $indexRoute, $approveRoute, $rejectRoute, $amountColumn): array {
                $routeParameter = match ($table) {
                    'operating_budgets' => 'budget',
                    'customer_refunds', 'vendor_cash_refunds' => 'refund',
                    'vendor_credit_notes' => 'creditNote',
                    'contractor_claims' => 'claim',
                    'lease_deposit_settlements' => 'settlement',
                    default => 'purchaseRequest',
                };

                return [
                    'key' => $table.':'.$row->id,
                    'module' => $module,
                    'transaction' => $row->reference ?? $label.' #'.$row->id,
                    'amount' => $amountColumn ? round((float) $row->amount / ($amountColumn === 'amount_cents' ? 100 : 1), 2) : null,
                    'cost_centre' => null,
                    'requested_by' => $row->requested_by,
                    'status' => 'submitted',
                    'submitted_at' => $row->created_at,
                    'href' => route($indexRoute),
                    'approve_url' => route($approveRoute, [$routeParameter => $row->id]),
                    'reject_url' => $rejectRoute ? route($rejectRoute, [$routeParameter => $row->id]) : null,
                    'reject_requires_reason' => $rejectRoute !== null,
                ];
            })->all());
    }
}
