<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Queries\OutstandingBalances;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OutstandingBalancesController extends Controller
{
    public function __invoke(Request $request, OutstandingBalances $query): Response
    {
        [$organization, $asOf, $scope] = $this->context($request);

        return Inertia::render('finance/OutstandingBalances', ['filters' => ['as_of' => $asOf, 'scope' => $scope], 'report' => $query->for($organization, $asOf, $scope)]);
    }

    public function export(Request $request, OutstandingBalances $query): StreamedResponse
    {
        [$organization, $asOf, $scope] = $this->context($request);
        $report = $query->for($organization, $asOf, $scope);

        return response()->streamDownload(function () use ($report, $asOf, $scope): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Outstanding balances AED', 'As of', $asOf, 'Due status', str_replace('_', ' ', $scope)]);
            fputcsv($output, ['Type', 'Reference', 'Party', 'Document date', 'Due date', 'Original amount', 'Paid as of', 'Credited as of', 'Outstanding', 'Days overdue', 'Currency']);
            foreach (['receivables' => 'Receivable', 'payables' => 'Payable'] as $key => $type) {
                foreach ($report[$key] as $row) {
                    fputcsv($output, [$type, $this->safeCell($row['reference']), $this->safeCell($row['party']), $row['document_date'], $row['due_on'], $row['total'], $row['paid'], $row['credited'], $row['balance'], $row['days_overdue'], $row['currency']]);
                }
            }
            fputcsv($output, ['Total receivables', $report['total_receivables']]);
            fputcsv($output, ['Total payables', $report['total_payables']]);
            fputcsv($output, ['Overdue receivables', $report['overdue_receivables'], 'Count', $report['overdue_receivables_count']]);
            fputcsv($output, ['Overdue payables', $report['overdue_payables'], 'Count', $report['overdue_payables_count']]);
            fclose($output);
        }, "outstanding-balances-aed-{$asOf}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{Organization, string, string}
     */
    private function context(Request $request): array
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $filters = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'scope' => ['nullable', 'in:all,overdue,not_overdue']]);
        $asOf = $filters['as_of'] ?? today()->toDateString();
        $scope = $filters['scope'] ?? 'all';

        return [$organization, $asOf, $scope];
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
