<?php

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AccountActivity
{
    /** @return array{opening_net: string, period_debit: string, period_credit: string, closing_net: string, lines: LengthAwarePaginator<int, JournalLine>} */
    public function forAccount(Organization $organization, LedgerAccount $account, ?string $from, ?string $to): array
    {
        $base = JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.organization_id', $organization->id)->where('journal_entries.currency', 'AED')
            ->where('journal_lines.ledger_account_id', $account->id);

        $opening = $from ? (clone $base)->where('journal_entries.posted_on', '<', $from)
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as net')->toBase()->value('net') : '0.00';
        $period = (clone $base)->when($from, fn ($query) => $query->where('journal_entries.posted_on', '>=', $from))
            ->when($to, fn ($query) => $query->where('journal_entries.posted_on', '<=', $to))
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')->first();
        $lines = (clone $base)->when($from, fn ($query) => $query->where('journal_entries.posted_on', '>=', $from))
            ->when($to, fn ($query) => $query->where('journal_entries.posted_on', '<=', $to))
            ->select('journal_lines.*')->with('entry:id,reference,source_reference,event,posted_on,reversal_of_id')
            ->orderBy('journal_entries.posted_on')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->paginate(50)->withQueryString();

        $openingCents = (int) round((float) $opening * 100);
        $debitCents = (int) round((float) $period->debit * 100);
        $creditCents = (int) round((float) $period->credit * 100);

        return [
            'opening_net' => number_format($openingCents / 100, 2, '.', ''),
            'period_debit' => number_format($debitCents / 100, 2, '.', ''),
            'period_credit' => number_format($creditCents / 100, 2, '.', ''),
            'closing_net' => number_format(($openingCents + $debitCents - $creditCents) / 100, 2, '.', ''),
            'lines' => $lines,
        ];
    }

    /** @return Collection<int, JournalLine> */
    public function exportLines(Organization $organization, LedgerAccount $account, ?string $from, ?string $to): Collection
    {
        return JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.organization_id', $organization->id)->where('journal_entries.currency', 'AED')
            ->where('journal_lines.ledger_account_id', $account->id)
            ->when($from, fn ($query) => $query->where('journal_entries.posted_on', '>=', $from))
            ->when($to, fn ($query) => $query->where('journal_entries.posted_on', '<=', $to))
            ->select('journal_lines.*')->with('entry:id,reference,source_reference,event,posted_on,reversal_of_id')
            ->orderBy('journal_entries.posted_on')->orderBy('journal_entries.id')->orderBy('journal_lines.id')->get();
    }
}
