<?php

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Finance\Queries\OutstandingBalances;
use App\Models\Organization;

class FinanceOverview
{
    public function __construct(private OutstandingBalances $outstanding) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization, string $asOf): array
    {
        $balances = $this->outstanding->for($organization, $asOf);
        $bankLines = BankStatementLine::query()->where('organization_id', $organization->id)->whereDate('occurred_on', '<=', $asOf);
        $period = AccountingPeriod::where('organization_id', $organization->id)->where('starts_on', '<=', $asOf)->where('ends_on', '>=', $asOf)->first();

        return [
            'as_of' => $asOf,
            'receivables' => $balances['total_receivables'],
            'payables' => $balances['total_payables'],
            'overdue_receivables' => $balances['overdue_receivables'],
            'overdue_payables' => $balances['overdue_payables'],
            'unmatched_bank_lines' => (clone $bankLines)->whereNull('matched_journal_line_id')->count(),
            'unsettled_bank_lines' => (clone $bankLines)->whereNotNull('matched_journal_line_id')->whereDoesntHave('settlements', fn ($query) => $query->whereNull('reversal_journal_entry_id'))->count(),
            'current_period' => $period ? [
                ...$period->only('id', 'name', 'status'),
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ] : null,
        ];
    }
}
