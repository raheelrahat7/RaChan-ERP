<?php

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialStatements
{
    /**
     * @return array<string, mixed>
     */
    public function profitAndLoss(Organization $organization, ?string $from, string $to): array
    {
        $rows = $this->balances($organization, $from, $to)->filter(fn ($row) => in_array($row->type, ['income', 'expense'], true))
            ->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'type' => $row->type, 'amount' => $this->amount($row->type === 'income' ? $row->credit - $row->debit : $row->debit - $row->credit)]);
        $income = $rows->where('type', 'income')->sum(fn ($row) => (float) $row['amount']);
        $expenses = $rows->where('type', 'expense')->sum(fn ($row) => (float) $row['amount']);

        return ['income' => $rows->where('type', 'income')->values(), 'expenses' => $rows->where('type', 'expense')->values(), 'total_income' => $this->amount($income), 'total_expenses' => $this->amount($expenses), 'profit' => $this->amount($income - $expenses)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function balanceSheet(Organization $organization, string $asOf): ?array
    {
        $period = AccountingPeriod::where('organization_id', $organization->id)->where('starts_on', '<=', $asOf)->where('ends_on', '>=', $asOf)->first();
        if (! $period) {
            return null;
        }
        $rows = $this->balances($organization, null, $asOf)->filter(fn ($row) => in_array($row->type, ['asset', 'liability', 'equity'], true))
            ->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'type' => $row->type, 'amount' => $this->amount($row->type === 'asset' ? $row->debit - $row->credit : $row->credit - $row->debit)]);
        $priorResults = $this->profitAndLoss($organization, null, $period->starts_on->subDay()->toDateString())['profit'];
        $currentProfit = $this->profitAndLoss($organization, $period->starts_on->toDateString(), $asOf)['profit'];
        $assets = $rows->where('type', 'asset')->values();
        $liabilities = $rows->where('type', 'liability')->values();
        $equity = $rows->where('type', 'equity')->values();
        $totalAssets = $assets->sum(fn ($row) => (float) $row['amount']);
        $totalLiabilities = $liabilities->sum(fn ($row) => (float) $row['amount']);
        $totalEquityAccounts = $equity->sum(fn ($row) => (float) $row['amount']);

        return [
            'period' => $period->only('id', 'name', 'starts_on', 'ends_on', 'status'), 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'prior_results' => $priorResults, 'current_profit' => $currentProfit, 'total_assets' => $this->amount($totalAssets),
            'total_liabilities' => $this->amount($totalLiabilities), 'total_equity' => $this->amount($totalEquityAccounts + (float) $priorResults + (float) $currentProfit),
            'total_liabilities_and_equity' => $this->amount($totalLiabilities + $totalEquityAccounts + (float) $priorResults + (float) $currentProfit),
        ];
    }

    /** @return Collection<int, \stdClass> */
    private function balances(Organization $organization, ?string $from, string $to): Collection
    {
        return LedgerAccount::query()->leftJoin('journal_lines', 'journal_lines.ledger_account_id', '=', 'ledger_accounts.id')
            ->leftJoin('journal_entries', function ($join) use ($organization, $from, $to): void {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_entries.organization_id', $organization->id)->where('journal_entries.currency', 'AED')->where('journal_entries.posted_on', '<=', $to);
                if ($from) {
                    $join->where('journal_entries.posted_on', '>=', $from);
                }
            })->where('ledger_accounts.organization_id', $organization->id)
            ->groupBy('ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type')
            ->orderBy('ledger_accounts.code')->toBase()->get(['ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type', DB::raw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.debit ELSE 0 END), 0) as debit'), DB::raw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.credit ELSE 0 END), 0) as credit')]);
    }

    private function amount(float|int|string $value): string
    {
        return number_format(round((float) $value * 100) / 100, 2, '.', '');
    }
}
