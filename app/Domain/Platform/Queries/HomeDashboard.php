<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Finance\Queries\OutstandingBalances;
use App\Domain\Platform\Actions\ManageWorkTask;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HomeDashboard
{
    public function __construct(private LeadVisibility $leads, private OutstandingBalances $balances) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function for(Organization $organization, User $actor, array $input): array
    {
        $period = $input['period'] ?? 'month';
        $purpose = $input['purpose'] ?? 'all';
        $today = CarbonImmutable::now($organization->timezone ?: 'UTC');
        $from = match ($period) {
            'quarter' => $today->startOfQuarter(),
            'year' => $today->startOfYear(),
            default => $today->startOfMonth(),
        };
        $to = match ($period) {
            'quarter' => $today->endOfQuarter(),
            'year' => $today->endOfYear(),
            default => $today->endOfMonth(),
        };
        $previousFrom = match ($period) {
            'quarter' => $from->subQuarter(),
            'year' => $from->subYear(),
            default => $from->subMonth(),
        };
        $previousTo = $from->subDay();
        $range = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $previous = ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString()];
        $orgId = $organization->id;
        $companies = DB::table('accounting_companies')->where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']);
        $branches = DB::table('accounting_branches')->where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'company_id']);
        $companyId = isset($input['company']) ? (int) $input['company'] : null;
        $branchId = isset($input['branch']) ? (int) $input['branch'] : null;
        if ($companyId && ! $companies->contains('id', $companyId)) {
            throw ValidationException::withMessages(['company' => 'Choose a company in this organization.']);
        }
        $branch = $branchId ? $branches->firstWhere('id', $branchId) : null;
        if ($branchId && (! $branch || ($companyId && (int) $branch->company_id !== $companyId))) {
            throw ValidationException::withMessages(['branch' => 'Choose a branch in the selected company.']);
        }
        $companyId ??= $branch ? (int) $branch->company_id : null;

        $canViewFinance = $actor->can('viewFinance', $organization);
        $canViewTransactions = $actor->can('viewTransactions', $organization);

        $currentIncome = $canViewFinance ? $this->incomeExpense($orgId, $range, $companyId, $branchId) : null;
        $previousIncome = $canViewFinance ? $this->incomeExpense($orgId, $previous, $companyId, $branchId) : null;
        $cash = $canViewFinance ? $this->cashBalance($orgId, $today->toDateString(), $companyId, $branchId) : null;
        $cashBefore = $canViewFinance ? $this->cashBalance($orgId, $today->subDays(7)->toDateString(), $companyId, $branchId) : null;
        $outstanding = $canViewFinance ? $this->balances->for($organization, $today->toDateString()) : null;
        $commission = $canViewFinance ? $this->commission($orgId, $range) : null;
        $contracts = $canViewTransactions ? $this->contracts($orgId, $purpose, $today) : null;
        $cheques = $canViewTransactions ? $this->cheques($orgId, $today) : null;
        $pendingCount = count(app(PendingApprovals::class)->for($organization, $actor));
        $overdueTaskCount = DB::table('work_tasks')->where('organization_id', $orgId)->where('status', 'open')
            ->where('due_at', '<', now())->when(! app(ManageWorkTask::class)->manages($organization, $actor), fn ($query) => $query->where('assigned_to', $actor->id))->count();

        return [
            'filters' => [
                'period' => $period,
                'purpose' => $purpose,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'range' => $range,
                'options' => ['companies' => $companies->all(), 'branches' => $branches->all()],
            ],
            'kpis' => [
                'revenue' => ['value' => $currentIncome['revenue'] ?? null, 'previous' => $previousIncome['revenue'] ?? null],
                'expenses' => ['value' => $currentIncome['expenses'] ?? null, 'previous' => $previousIncome['expenses'] ?? null],
                'net_profit' => ['value' => $currentIncome !== null ? $currentIncome['revenue'] - $currentIncome['expenses'] : null,
                    'previous' => $previousIncome !== null ? $previousIncome['revenue'] - $previousIncome['expenses'] : null],
                'cash_balance' => ['value' => $cash, 'change_7d_pct' => $cashBefore === null || $cashBefore == 0.0 ? null : round(($cash - $cashBefore) / abs($cashBefore) * 100, 2)],
                'receivables' => ['value' => $outstanding !== null ? (float) $outstanding['total_receivables'] : null],
                'payables' => ['value' => $outstanding !== null ? (float) $outstanding['total_payables'] : null],
                'vat_payable' => ['value' => $canViewFinance ? $this->vatPayable($orgId, $today->toDateString(), $companyId, $branchId) : null],
                'commission_payable' => $commission,
                'active_deals' => ['count' => $contracts['active_deals'] ?? null],
                'expiring_contracts' => ['count' => $contracts['expiring'] ?? null],
                'pdc_due' => $cheques['due'] ?? null,
                'bounced_cheques' => $cheques['bounced'] ?? null,
                'pending_approvals' => ['count' => $pendingCount],
                'overdue_tasks' => ['count' => $overdueTaskCount],
            ],
            'trend' => $canViewTransactions ? $this->trend($orgId, $purpose, $today) : null,
            // Existing commission records distinguish the broker amount, but not company,
            // co-broker and referral shares. Do not invent the missing components.
            'commission_split' => null,
            'top_agents' => $canViewFinance ? $this->topAgents($orgId, $range) : [],
            'lead_pipeline' => $this->leadPipeline($organization, $actor),
            'lead_sources' => $this->leadSources($organization, $actor, $range),
            'deal_pipeline' => $canViewTransactions ? $this->dealPipeline($orgId, $purpose) : null,
            'cost_centres' => $canViewFinance ? $this->costCentres($orgId, $range, $companyId, $branchId) : [],
            'insights' => array_values(array_filter([
                $pendingCount > 0 ? ['icon' => 'check', 'text' => $pendingCount.' approval requests need a decision.'] : null,
                $overdueTaskCount > 0 ? ['icon' => 'clock', 'text' => $overdueTaskCount.' tasks are overdue.'] : null,
            ])),
        ];
    }

    /** @param array{from:string,to:string} $range
     * @return array{revenue:float,expenses:float}
     */
    private function incomeExpense(int $orgId, array $range, ?int $companyId = null, ?int $branchId = null): array
    {
        $rows = DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->where('entry.organization_id', $orgId)->where('account.organization_id', $orgId)
            ->where('entry.currency', 'AED')->whereBetween('entry.posted_on', [$range['from'], $range['to']])
            ->when($companyId, fn ($query) => $query->where('line.company_id', $companyId))
            ->when($branchId, fn ($query) => $query->where('line.branch_id', $branchId))
            ->whereIn('account.type', ['income', 'expense'])
            ->selectRaw('account.type, SUM(line.debit) AS debit, SUM(line.credit) AS credit')->groupBy('account.type')->get()->keyBy('type');

        return [
            'revenue' => round((float) ($rows['income']->credit ?? 0) - (float) ($rows['income']->debit ?? 0), 2),
            'expenses' => round((float) ($rows['expense']->debit ?? 0) - (float) ($rows['expense']->credit ?? 0), 2),
        ];
    }

    private function cashBalance(int $orgId, string $asOf, ?int $companyId = null, ?int $branchId = null): float
    {
        return round((float) DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->where('entry.organization_id', $orgId)->where('account.organization_id', $orgId)
            ->where('entry.currency', 'AED')->where('entry.posted_on', '<=', $asOf)
            ->when($companyId, fn ($query) => $query->where('line.company_id', $companyId))
            ->when($branchId, fn ($query) => $query->where('line.branch_id', $branchId))
            ->where('account.type', 'asset')->where(function ($query): void {
                $query->whereIn('account.code', ['1000', '1010', '1020'])
                    ->orWhereIn('account.id', DB::table('bank_accounts')->whereNotNull('ledger_account_id')->select('ledger_account_id'));
            })->selectRaw('COALESCE(SUM(line.debit - line.credit), 0) AS balance')->value('balance'), 2);
    }

    private function vatPayable(int $orgId, string $today, ?int $companyId = null, ?int $branchId = null): float
    {
        $period = DB::table('accounting_periods')->where('organization_id', $orgId)->where('status', 'open')
            ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->first(['starts_on', 'ends_on']);
        if (! $period) {
            return 0.0;
        }

        $rows = DB::table('journal_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->where('entry.organization_id', $orgId)->where('account.organization_id', $orgId)->where('entry.currency', 'AED')
            ->whereBetween('entry.posted_on', [$period->starts_on, $period->ends_on])->whereIn('account.code', ['2200', '1250'])
            ->when($companyId, fn ($query) => $query->where('line.company_id', $companyId))
            ->when($branchId, fn ($query) => $query->where('line.branch_id', $branchId))
            ->selectRaw('account.code, SUM(line.debit) AS debit, SUM(line.credit) AS credit')->groupBy('account.code')->get()->keyBy('code');

        return round((float) ($rows['2200']->credit ?? 0) - (float) ($rows['2200']->debit ?? 0)
            - (float) ($rows['1250']->debit ?? 0) + (float) ($rows['1250']->credit ?? 0), 2);
    }

    /** @param array{from:string,to:string} $range
     * @return array{value:float,agents:int}
     */
    private function commission(int $orgId, array $range): array
    {
        $query = DB::table('commission_transactions')->where('organization_id', $orgId)->where('currency', 'AED')
            ->whereNull('paid_on')->whereBetween('payable_on', [$range['from'], $range['to']]);

        return ['value' => round((float) (clone $query)->sum('commission_amount'), 2), 'agents' => (clone $query)->distinct()->count('broker_id')];
    }

    /** @param array{from:string,to:string} $range
     * @return list<array{user_id:int,name:string,team:null,commission:float,deals:int}>
     */
    private function topAgents(int $orgId, array $range): array
    {
        $rows = DB::table('commission_transactions as commission')
            ->join('brokers as broker', 'broker.id', '=', 'commission.broker_id')
            ->join('users as agent', 'agent.id', '=', 'broker.user_id')
            ->join('organization_user as membership', fn ($join) => $join->on('membership.user_id', '=', 'agent.id')->where('membership.organization_id', '=', $orgId))
            ->where('commission.organization_id', $orgId)->where('broker.organization_id', $orgId)
            ->where('commission.currency', 'AED')->whereBetween('commission.payable_on', [$range['from'], $range['to']])
            ->selectRaw("agent.id AS user_id, agent.name, SUM(commission.commission_amount) AS total_commission, COUNT(DISTINCT CONCAT(commission.source_type, ':', commission.source_id)) AS deals")
            ->groupBy('agent.id', 'agent.name')->orderByDesc('total_commission')->limit(5)->get();

        return array_values($rows->map(fn ($row) => [
            'user_id' => (int) $row->user_id, 'name' => (string) $row->name, 'team' => null,
            'commission' => round((float) $row->total_commission, 2), 'deals' => (int) $row->deals,
        ])->all());
    }

    /** @return array{active_deals:int,expiring:int} */
    private function contracts(int $orgId, string $purpose, CarbonImmutable $today): array
    {
        $reservations = DB::table('reservations')->where('organization_id', $orgId)->where('status', 'active')->where('expires_at', '>=', $today->toDateTimeString())->count();
        $rent = DB::table('leases')->where('organization_id', $orgId)->whereIn('status', ['draft', 'active']);
        $sale = DB::table('sales_contracts')->where('organization_id', $orgId)->whereIn('status', ['draft', 'active']);
        $active = ($purpose === 'all' ? $reservations : 0)
            + ($purpose !== 'sale' ? (clone $rent)->count() : 0)
            + ($purpose !== 'rent' ? (clone $sale)->count() : 0);
        $expiring = $purpose === 'sale' ? 0 : (clone $rent)->where('status', 'active')->whereBetween('ends_on', [$today->toDateString(), $today->addDays(30)->toDateString()])->count();

        return ['active_deals' => $active, 'expiring' => $expiring];
    }

    /** @return array{due:array{count:int,amount:float},bounced:array{count:int,amount:float}} */
    private function cheques(int $orgId, CarbonImmutable $today): array
    {
        $base = DB::table('lease_cheques')->where('organization_id', $orgId);
        $due = (clone $base)->whereIn('status', ['scheduled', 'deposited'])->whereBetween('due_on', [$today->toDateString(), $today->addDays(30)->toDateString()]);
        $bounced = (clone $base)->where('status', 'bounced')->whereBetween('bounced_on', [$today->subDays(7)->toDateString(), $today->toDateString()]);

        return [
            'due' => ['count' => (clone $due)->count(), 'amount' => round((float) $due->sum('amount'), 2)],
            'bounced' => ['count' => (clone $bounced)->count(), 'amount' => round((float) $bounced->sum('amount'), 2)],
        ];
    }

    /** @return array{months:list<string>,sales_value:list<float>,rental_value:list<float>} */
    private function trend(int $orgId, string $purpose, CarbonImmutable $today): array
    {
        $months = array_values(collect(range(11, 0))->map(fn (int $ago) => $today->startOfMonth()->subMonths($ago)->format('Y-m'))->all());
        $from = $months[0].'-01';
        $sales = $purpose === 'rent' ? collect() : DB::table('sales_contracts')->where('organization_id', $orgId)->where('currency', 'AED')
            ->where('status', 'active')->where('contracted_on', '>=', $from)->get(['contracted_on', 'sale_price']);
        $leases = $purpose === 'sale' ? collect() : DB::table('leases')->where('organization_id', $orgId)->where('currency', 'AED')
            ->where('status', 'active')->where('starts_on', '>=', $from)->get(['starts_on', 'ends_on', 'rent_amount']);

        return [
            'months' => $months,
            'sales_value' => array_map(fn (string $month): float => round((float) $sales->filter(fn ($row) => str_starts_with((string) $row->contracted_on, $month))->sum('sale_price'), 2), $months),
            'rental_value' => array_map(fn (string $month): float => round((float) $leases->filter(fn ($row) => str_starts_with((string) $row->starts_on, $month))
                ->sum(fn ($row) => (float) $row->rent_amount * 365 / max(1, CarbonImmutable::parse($row->starts_on)->diffInDays(CarbonImmutable::parse($row->ends_on)) + 1)), 2), $months),
        ];
    }

    /** @return array{pipeline:array{id:int,name:string}|null,stages:list<array{id:int,name:string,type:string,count:int}>} */
    private function leadPipeline(Organization $organization, User $actor): array
    {
        if (! $actor->can('viewCrm', $organization)) {
            return ['pipeline' => null, 'stages' => []];
        }
        $pipeline = DB::table('crm_pipelines')->where('organization_id', $organization->id)->where('active', true)->orderByDesc('is_default')->orderBy('id')->first(['id', 'name']);
        if (! $pipeline) {
            return ['pipeline' => null, 'stages' => []];
        }
        $counts = $this->leads->scope(CrmLead::query()->where('organization_id', $organization->id)->where('pipeline_id', $pipeline->id), $organization, $actor)
            ->selectRaw('current_stage_id, COUNT(*) AS total')->groupBy('current_stage_id')->toBase()->get()->keyBy('current_stage_id');
        $stages = array_values(DB::table('crm_pipeline_stages')->where('pipeline_id', $pipeline->id)->where('active', true)->orderBy('position')->get(['id', 'name', 'type'])
            ->map(fn ($stage) => ['id' => (int) $stage->id, 'name' => (string) $stage->name, 'type' => (string) $stage->type, 'count' => (int) ($counts[$stage->id]->total ?? 0)])->all());

        return ['pipeline' => ['id' => (int) $pipeline->id, 'name' => (string) $pipeline->name], 'stages' => $stages];
    }

    /** @param array{from:string,to:string} $range
     * @return list<array{source:string,count:int}>
     */
    private function leadSources(Organization $organization, User $actor, array $range): array
    {
        if (! $actor->can('viewCrm', $organization)) {
            return [];
        }
        $sources = $this->leads->scope(CrmLead::query()->where('organization_id', $organization->id)
            ->whereBetween('created_at', [$range['from'].' 00:00:00', $range['to'].' 23:59:59']), $organization, $actor)
            ->selectRaw('COALESCE(NULLIF(source, ""), "Unknown") AS source_name, COUNT(*) AS total')->groupBy('source_name')->orderByDesc('total')->toBase()->get();
        $top = array_values($sources->take(6)->map(fn ($row) => ['source' => (string) $row->source_name, 'count' => (int) $row->total])->all());
        $other = $sources->skip(6)->sum('total');
        if ($other > 0) {
            $top[] = ['source' => 'Other', 'count' => (int) $other];
        }

        return $top;
    }

    /** @param array{from:string,to:string} $range
     * @return list<array{level:int,company:string,branch:?string,department:null,cost_centre:?string,revenue:float,expense:float,profit:float}>
     */
    private function costCentres(int $orgId, array $range, ?int $companyId, ?int $branchId): array
    {
        $rows = DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->join('accounting_companies as company', 'company.id', '=', 'line.company_id')
            ->leftJoin('accounting_branches as branch', 'branch.id', '=', 'line.branch_id')
            ->leftJoin('accounting_cost_centres as centre', 'centre.id', '=', 'line.cost_centre_id')
            ->where('entry.organization_id', $orgId)->where('account.organization_id', $orgId)
            ->where('company.organization_id', $orgId)->where('entry.currency', 'AED')
            ->whereBetween('entry.posted_on', [$range['from'], $range['to']])
            ->whereIn('account.type', ['income', 'expense'])
            ->when($companyId, fn ($query) => $query->where('line.company_id', $companyId))
            ->when($branchId, fn ($query) => $query->where('line.branch_id', $branchId))
            ->selectRaw('company.name AS company, branch.name AS branch, centre.name AS centre, SUM(CASE WHEN account.type = "income" THEN line.credit - line.debit ELSE 0 END) AS revenue, SUM(CASE WHEN account.type = "expense" THEN line.debit - line.credit ELSE 0 END) AS expense')
            ->groupBy('company.name', 'branch.name', 'centre.name')->get();
        $totals = [];
        foreach ($rows as $row) {
            foreach ([0, 1, 2] as $level) {
                if ($level === 1 && $row->branch === null || $level === 2 && $row->centre === null) {
                    continue;
                }
                $key = $row->company.'|'.($level > 0 ? $row->branch : '').'|'.($level > 1 ? $row->centre : '');
                $totals[$key] ??= ['level' => $level, 'company' => $row->company, 'branch' => $level > 0 ? $row->branch : null,
                    'department' => null, 'cost_centre' => $level > 1 ? $row->centre : null, 'revenue' => 0.0, 'expense' => 0.0, 'profit' => 0.0];
                $totals[$key]['revenue'] += (float) $row->revenue;
                $totals[$key]['expense'] += (float) $row->expense;
                $totals[$key]['profit'] = round($totals[$key]['revenue'] - $totals[$key]['expense'], 2);
            }
        }

        return array_values($totals);
    }

    /** @return array{stages:list<array{key:string,label:string,count:int,value:float}>} */
    private function dealPipeline(int $orgId, string $purpose): array
    {
        $stages = [];
        if ($purpose === 'all') {
            $stages[] = ['key' => 'reserved', 'label' => 'Reserved', 'count' => DB::table('reservations')->where('organization_id', $orgId)->where('status', 'active')->count(), 'value' => 0.0];
        }
        foreach ([['rent', 'leases', 'rent_amount'], ['sale', 'sales_contracts', 'sale_price']] as [$kind, $table, $column]) {
            if ($purpose !== 'all' && $purpose !== $kind) {
                continue;
            }
            foreach (['draft', 'active'] as $status) {
                $query = DB::table($table)->where('organization_id', $orgId)->where('currency', 'AED')->where('status', $status);
                $stages[] = ['key' => $kind.'_'.$status, 'label' => ucfirst($kind).' '.ucfirst($status),
                    'count' => (clone $query)->count(), 'value' => round((float) $query->sum($column), 2)];
            }
        }

        return ['stages' => $stages];
    }
}
