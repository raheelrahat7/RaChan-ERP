<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Queries\PeriodCloseReadiness;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PeriodCloseReadinessController extends Controller
{
    public function __invoke(Request $request, AccountingPeriod $period, PeriodCloseReadiness $query): Response
    {
        $this->authorizePeriod($request, $period);

        return Inertia::render('finance/PeriodCloseReadiness', $query->for($period));
    }

    public function export(Request $request, AccountingPeriod $period, PeriodCloseReadiness $query): StreamedResponse
    {
        $this->authorizePeriod($request, $period);
        $report = $query->for($period);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Period close readiness', $this->safeCell($report['period']['name'])]);
            fputcsv($output, ['Starts on', $report['period']['starts_on'], 'Ends on', $report['period']['ends_on'], 'Status', $report['period']['status']]);
            fputcsv($output, ['Detailed journals', $report['journal_count'], 'Debits AED', $report['debits'], 'Credits AED', $report['credits']]);
            fputcsv($output, []);
            fputcsv($output, ['Check', 'Count', 'Status', 'Detail']);
            foreach ($report['checks'] as $check) {
                fputcsv($output, [$this->safeCell($check['label']), $check['count'], $check['status'], $this->safeCell($check['detail'])]);
            }
            fclose($output);
        }, "period-close-readiness-{$period->id}-{$period->ends_on->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizePeriod(Request $request, AccountingPeriod $period): void
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $period->organization_id === $organization->id, 404);
        $this->authorize('viewFinance', $organization);
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
