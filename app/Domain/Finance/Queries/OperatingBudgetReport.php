<?php

namespace App\Domain\Finance\Queries;

use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Finance\Models\OperatingBudget;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OperatingBudgetReport
{
    /** @return array<string, mixed> */
    public function for(Organization $organization, int $year, int $throughMonth): array
    {
        $budgets = OperatingBudget::where('organization_id', $organization->id)->where('year', $year)->orderBy('version')->with(['lines.account:id,code,name,type,is_active', 'submitter:id,name', 'approver:id,name', 'rejector:id,name'])->get();
        $approved = $budgets->where('status', 'approved')->sortBy('effective_from')->values();
        $accounts = LedgerAccount::where('organization_id', $organization->id)->where('type', 'expense')->orderBy('code')->get()->keyBy('id');
        $today = today()->toDateString();
        $monthEnd = Carbon::create($year, $throughMonth, 1)->endOfMonth()->toDateString();
        $throughDate = min($monthEnd, $today);
        $actuals = [];
        if ($throughDate >= sprintf('%04d-01-01', $year)) {
            $actuals = DB::table('journal_entries')
                ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
                ->where('journal_entries.organization_id', $organization->id)
                ->where('journal_entries.currency', 'AED')
                ->whereBetween('journal_entries.posted_on', [sprintf('%04d-01-01', $year), $throughDate])
                ->where('ledger_accounts.organization_id', $organization->id)
                ->where('ledger_accounts.type', 'expense')
                ->select('ledger_accounts.id as account_id', DB::raw('MONTH(journal_entries.posted_on) as month'), DB::raw('SUM(journal_lines.debit - journal_lines.credit) as actual'))
                ->groupBy('ledger_accounts.id', DB::raw('MONTH(journal_entries.posted_on)'))
                ->get()->keyBy(fn ($row): string => $row->account_id.'-'.$row->month);
        }

        $lineMaps = $approved->mapWithKeys(fn (OperatingBudget $budget): array => [$budget->id => $budget->lines->keyBy(fn ($line): string => $line->ledger_account_id.'-'.$line->month)]);
        $rows = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthDate = sprintf('%04d-%02d-01', $year, $month);
            $activeBudget = $approved->last(fn (OperatingBudget $budget): bool => $budget->effective_from?->toDateString() <= $monthDate);
            $lines = $activeBudget ? $lineMaps[$activeBudget->id] : collect();
            foreach ($accounts as $account) {
                $key = $account->id.'-'.$month;
                $line = $lines->get($key);
                $actual = $month <= $throughMonth && $throughDate >= $monthDate ? (float) ($actuals[$key]->actual ?? 0) : 0.0;
                $budgetAmount = $line ? (float) $line->amount : 0.0;
                if ($month > $throughMonth && ! $line) {
                    continue;
                }
                if (! isset($rows[$account->id])) {
                    $rows[$account->id] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'is_active' => $account->is_active, 'months' => [], 'budget_to_date' => 0.0, 'actual_to_date' => 0.0];
                }
                $rows[$account->id]['months'][$month] = ['budget' => $this->money($budgetAmount), 'actual' => $month <= $throughMonth ? $this->money($actual) : null, 'variance' => $month <= $throughMonth ? $this->money($actual - $budgetAmount) : null, 'version' => $activeBudget?->version];
                if ($month <= $throughMonth) {
                    $rows[$account->id]['budget_to_date'] += $budgetAmount;
                    $rows[$account->id]['actual_to_date'] += $actual;
                }
            }
        }

        $rows = collect($rows)->map(function (array $row): array {
            $row['budget_to_date'] = $this->money($row['budget_to_date']);
            $row['actual_to_date'] = $this->money($row['actual_to_date']);
            $row['remaining'] = $this->money((float) $row['budget_to_date'] - (float) $row['actual_to_date']);
            $row['variance_to_date'] = $this->money((float) $row['actual_to_date'] - (float) $row['budget_to_date']);

            return $row;
        })->values();

        $currentMonth = sprintf('%04d-%02d-01', $year, $throughMonth);
        $selectedBudget = $approved->last(fn (OperatingBudget $budget): bool => $budget->effective_from?->toDateString() <= $currentMonth);

        return [
            'year' => $year,
            'through_month' => $throughMonth,
            'rows' => $rows,
            'totals' => [
                'budget_to_date' => $this->money($rows->sum(fn (array $row): float => (float) $row['budget_to_date'])),
                'actual_to_date' => $this->money($rows->sum(fn (array $row): float => (float) $row['actual_to_date'])),
                'remaining' => $this->money($rows->sum(fn (array $row): float => (float) $row['remaining'])),
                'variance_to_date' => $this->money($rows->sum(fn (array $row): float => (float) $row['variance_to_date'])),
            ],
            'versions' => $budgets->whereIn('status', ['approved', 'rejected'])->map(fn (OperatingBudget $budget): array => [
                'id' => $budget->id,
                'version' => $budget->version,
                'status' => $budget->status,
                'effective_from' => $budget->effective_from?->toDateString(),
                'decision_at' => ($budget->approved_at ?? $budget->rejected_at)?->toDateTimeString(),
                'reason' => $budget->reason,
                'decision_reason' => $budget->rejection_reason,
                'decided_by' => $budget->status === 'approved' ? $budget->approver?->name : $budget->rejector?->name,
                'lines' => $budget->lines->groupBy('ledger_account_id')->map(fn ($lines): array => [
                    'account' => $lines->first()->account?->only('code', 'name'),
                    'monthly_amounts' => $lines->sortBy('month')->mapWithKeys(fn ($line): array => [$line->month - 1 => $line->amount])->all(),
                ])->values(),
            ])->values(),
            'draft' => $budgets->first(fn (OperatingBudget $budget): bool => $budget->status === 'draft'),
            'pending' => $budgets->first(fn (OperatingBudget $budget): bool => $budget->status === 'submitted'),
            'selected_version' => $selectedBudget?->version,
            'accounts' => LedgerAccount::where('organization_id', $organization->id)->where('type', 'expense')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }

    private function money(float|int|string $amount): string
    {
        return number_format(round((float) $amount * 100) / 100, 2, '.', '');
    }
}
