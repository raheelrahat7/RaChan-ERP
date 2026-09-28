<?php

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\CorporateTaxReturn;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\VendorBill;
use Illuminate\Support\Facades\DB;

class PeriodCloseReadiness
{
    public function __construct(private FixedAssetReviewReadiness $assetReviews) {}

    /**
     * @return array{period: array<string, mixed>, journal_count: int, debits: string, credits: string, checks: list<array{key: string, label: string, count: int, status: string, detail: string}>, has_ledger_blocker: bool}
     */
    public function for(AccountingPeriod $period): array
    {
        $entries = JournalEntry::query()->where('organization_id', $period->organization_id)
            ->where('currency', 'AED')->whereBetween('posted_on', [$period->starts_on, $period->ends_on]);
        $lineTotals = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.organization_id', $period->organization_id)->where('journal_entries.currency', 'AED')
            ->whereBetween('journal_entries.posted_on', [$period->starts_on, $period->ends_on])
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) debit, COALESCE(SUM(journal_lines.credit), 0) credit')->first();
        $debitCents = $this->cents((string) $lineTotals->debit);
        $creditCents = $this->cents((string) $lineTotals->credit);
        $bankLines = BankStatementLine::query()->where('organization_id', $period->organization_id)
            ->whereBetween('occurred_on', [$period->starts_on, $period->ends_on]);

        $checks = [
            ['key' => 'ledger_balance', 'label' => 'Detailed ledger is balanced', 'count' => abs($debitCents - $creditCents), 'status' => $debitCents === $creditCents ? 'clear' : 'attention', 'detail' => 'Difference shown in fils.'],
            ['key' => 'legacy_entries', 'label' => 'Unallocated legacy journal summaries', 'count' => (clone $entries)->whereDoesntHave('lines')->count(), 'status' => (clone $entries)->whereDoesntHave('lines')->exists() ? 'attention' : 'clear', 'detail' => 'Historical summaries have no ledger-account allocation.'],
            ['key' => 'draft_invoices', 'label' => 'Organization-wide draft invoices', 'count' => Invoice::where('organization_id', $period->organization_id)->where('status', 'draft')->count(), 'status' => 'information', 'detail' => 'Review whether drafts belong in this period before closing.'],
            ['key' => 'draft_bills', 'label' => 'Draft vendor bills dated in period', 'count' => VendorBill::where('organization_id', $period->organization_id)->where('status', 'draft')->whereBetween('bill_date', [$period->starts_on, $period->ends_on])->count(), 'status' => 'information', 'detail' => 'Review, post, or correct dated drafts.'],
            ['key' => 'unmatched_bank', 'label' => 'Unmatched bank statement rows', 'count' => (clone $bankLines)->whereNull('matched_journal_line_id')->count(), 'status' => 'information', 'detail' => 'Matching is manual and does not post settlement.'],
            ['key' => 'unsettled_bank', 'label' => 'Matched bank rows without active settlement', 'count' => (clone $bankLines)->whereNotNull('matched_journal_line_id')->whereDoesntHave('settlements', fn ($query) => $query->whereNull('reversal_journal_entry_id'))->count(), 'status' => 'information', 'detail' => 'Finance-manager settlement remains pending.'],
        ];

        if ($period->ends_on->format('m-d') === '12-31') {
            $assetReadiness = $this->assetReviews->for(Organization::findOrFail($period->organization_id), $period->ends_on->year);
            $checks[] = ['key' => 'missing_asset_reviews', 'label' => 'Fixed assets missing annual estimate review', 'count' => $assetReadiness['missing']->count(), 'status' => 'information', 'detail' => 'Complete the documented year-end useful-life, residual-value, method, and impairment review.'];
            $checks[] = ['key' => 'asset_review_follow_up', 'label' => 'Fixed asset reviews requiring follow-up', 'count' => $assetReadiness['attention']->count(), 'status' => 'information', 'detail' => 'Review documented estimate-change and impairment-assessment requirements under approved policies.'];
        }

        $organization = Organization::findOrFail($period->organization_id);
        if ($organization->corporate_tax_profile && $period->ends_on->month === (($organization->corporate_tax_year_start_month + 10) % 12) + 1 && $period->ends_on->isLastOfMonth()) {
            $taxReturn = CorporateTaxReturn::where('organization_id', $period->organization_id)->whereDate('ends_on', $period->ends_on)->first();
            $checks[] = ['key' => 'corporate_tax_preparation', 'label' => 'Corporate-tax return awaiting approval or preparation', 'count' => ! $taxReturn || $taxReturn->status === 'prepared' ? 1 : 0, 'status' => 'information', 'detail' => 'Prepare and obtain owner approval for the completed tax year.'];
            $checks[] = ['key' => 'corporate_tax_filing', 'label' => 'Corporate-tax return awaiting filing', 'count' => $taxReturn && $taxReturn->status !== 'filed' ? 1 : 0, 'status' => 'information', 'detail' => 'Internal filing confirmation and payment are due within nine months of tax-period end.'];
            $checks[] = ['key' => 'corporate_tax_payment', 'label' => 'Corporate-tax liability awaiting payment', 'count' => $taxReturn && (float) $taxReturn->tax_payable > 0 && ! $taxReturn->payment_journal_entry_id ? 1 : 0, 'status' => 'information', 'detail' => 'Record payment through the corporate-tax workflow by the statutory due date.'];
        }

        return [
            'period' => [
                ...$period->only('id', 'name', 'status'),
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ],
            'journal_count' => (clone $entries)->count(),
            'debits' => $this->money($debitCents), 'credits' => $this->money($creditCents),
            'checks' => $checks,
            'has_ledger_blocker' => collect($checks)->contains(fn (array $check) => $check['status'] === 'attention'),
        ];
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
