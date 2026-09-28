<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Queries\FinancialStatements;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialStatementsController extends Controller
{
    public function __invoke(Request $request, FinancialStatements $statements): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $filters = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $asOf = $filters['as_of'] ?? today()->toDateString();
        $from = $filters['from'] ?? today()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? $asOf;

        return Inertia::render('finance/FinancialStatements', [
            'filters' => ['as_of' => $asOf, 'from' => $from, 'to' => $to],
            'balanceSheet' => $statements->balanceSheet($organization, $asOf),
            'profitAndLoss' => $statements->profitAndLoss($organization, $from, $to),
        ]);
    }

    public function exportBalanceSheet(Request $request, FinancialStatements $statements): StreamedResponse
    {
        [$organization, $asOf] = $this->balanceSheetContext($request);
        $statement = $statements->balanceSheet($organization, $asOf);
        abort_unless($statement !== null, 422, 'An accounting period containing the as-of date is required.');

        return response()->streamDownload(function () use ($statement, $asOf): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Balance sheet AED', 'As of', $asOf]);
            foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $key => $label) {
                fputcsv($output, [$label]);
                foreach ($statement[$key] as $row) {
                    fputcsv($output, [$row['code'], $this->safeCell($row['name']), $row['amount']]);
                }
                if ($key !== 'equity') {
                    fputcsv($output, ['Total '.strtolower($label), $statement['total_'.$key]]);
                }
            }
            fputcsv($output, ['Prior accumulated results', $statement['prior_results']]);
            fputcsv($output, ['Current-period profit/(loss)', $statement['current_profit']]);
            fputcsv($output, ['Total equity including results', $statement['total_equity']]);
            fputcsv($output, ['Total liabilities and equity', $statement['total_liabilities_and_equity']]);
            fclose($output);
        }, "balance-sheet-aed-{$asOf}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportProfitAndLoss(Request $request, FinancialStatements $statements): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $filters = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $statement = $statements->profitAndLoss($organization, $filters['from'], $filters['to']);

        return response()->streamDownload(function () use ($statement, $filters): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Profit and loss AED', 'From', $filters['from'], 'To', $filters['to']]);
            foreach (['income' => 'Income', 'expenses' => 'Expenses'] as $key => $label) {
                fputcsv($output, [$label]);
                foreach ($statement[$key] as $row) {
                    fputcsv($output, [$row['code'], $this->safeCell($row['name']), $row['amount']]);
                }
                fputcsv($output, ['Total '.strtolower($label), $statement['total_'.$key]]);
            }
            fputcsv($output, ['Profit/(loss)', $statement['profit']]);
            fclose($output);
        }, "profit-and-loss-aed-{$filters['from']}-{$filters['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{Organization, string}
     */
    private function balanceSheetContext(Request $request): array
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $asOf = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']])['as_of'];

        return [$organization, $asOf];
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
