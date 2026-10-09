<?php

namespace App\Domain\Brokerage\Queries;

use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CommissionOverview
{
    public function query(Organization $org, ?int $teamId = null): Builder
    {
        $clawbacks = DB::table('commission_clawbacks')->where('organization_id', $org->id)
            ->selectRaw('commission_transaction_id, SUM(amount) as total')->groupBy('commission_transaction_id');

        return DB::table('commission_transactions as commission')
            ->join('brokers as broker', function ($join) use ($org): void {
                $join->on('broker.id', '=', 'commission.broker_id')->where('broker.organization_id', $org->id);
            })
            ->leftJoin('crm_team_memberships as membership', function ($join) use ($org): void {
                $join->on('membership.user_id', '=', 'broker.user_id')->where('membership.organization_id', $org->id);
            })
            ->leftJoin('crm_teams as team', function ($join) use ($org): void {
                $join->on('team.id', '=', 'membership.team_id')->where('team.organization_id', $org->id);
            })
            ->leftJoin('commission_allocations as allocation', function ($join) use ($org): void {
                $join->on('allocation.commission_transaction_id', '=', 'commission.id')->where('allocation.organization_id', $org->id);
            })
            ->leftJoinSub($clawbacks, 'clawbacks', 'clawbacks.commission_transaction_id', '=', 'commission.id')
            ->where('commission.organization_id', $org->id)
            ->when($teamId !== null, fn ($q) => $q->where('team.id', $teamId))
            ->select('commission.*', 'broker.name as broker_name', 'team.id as team_id', 'team.name as team_name',
                'allocation.status as allocation_status', 'allocation.net_company', 'allocation.agent_payable',
                DB::raw('COALESCE(clawbacks.total, 0) as clawback_total'));
    }

    /** @return array<string, mixed> */
    public function serialize(object $row, bool $canManage): array
    {
        $values = (array) $row;
        $values['version'] = (int) $values['version'];
        if ($values['allocation_status'] === 'approved') {
            $toCents = static function (string $amount): int {
                [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

                return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
            };
            $cents = $toCents((string) $values['net_company']) - $toCents((string) $values['clawback_total']);
            $values['net_contribution'] = ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
        } else {
            $values['net_contribution'] = null;
        }
        $values['permissions'] = ['read' => true, 'record_clawback' => $canManage];

        return $values;
    }

    /** @return list<array<string, mixed>> */
    public function teamSummary(Organization $org): array
    {
        return array_values($this->query($org)->reorder()->select('team.id as team_id', 'team.name as team_name')
            ->selectRaw('COUNT(*) as total_commissions, SUM(commission.commission_amount) as gross_commission, SUM(COALESCE(clawbacks.total, 0)) as clawback_total, SUM(CASE WHEN allocation.status = ? THEN allocation.net_company - COALESCE(clawbacks.total, 0) ELSE 0 END) as net_contribution', ['approved'])
            ->groupBy('team.id', 'team.name')->orderBy('team.name')->get()
            ->map(fn ($row) => ['team_id' => $row->team_id, 'team_name' => $row->team_name ?? 'Unassigned',
                'total_commissions' => (int) $row->total_commissions, 'gross_commission' => $row->gross_commission,
                'clawback_total' => $row->clawback_total, 'net_contribution' => $row->net_contribution])->all());
    }
}
