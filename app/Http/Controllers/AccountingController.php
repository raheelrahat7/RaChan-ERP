<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Queries\FinanceOverview;
use App\Domain\Accounting\Queries\PeriodCloseReadiness;
use App\Domain\Accounting\Queries\TrialBalance;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\JournalEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingController extends Controller
{
    public function index(Request $request, TrialBalance $trialBalance, PeriodCloseReadiness $readiness, FinanceOverview $overview): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? null;

        return Inertia::render('finance/Accounting', [
            'overview' => $overview->for($organization, $asOf ?? today()->toDateString()),
            'accounts' => $trialBalance->forOrganization($organization, $asOf),
            'asOf' => $asOf ?? '',
            'periods' => AccountingPeriod::where('organization_id', $organization->id)->orderByDesc('starts_on')->get()->map(function (AccountingPeriod $period) use ($readiness): array {
                $report = $readiness->for($period);

                return [
                    ...$period->only('id', 'name', 'status'),
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                    'has_ledger_blocker' => $report['has_ledger_blocker'],
                    'ledger_blockers' => collect($report['checks'])->where('status', 'attention')->pluck('label')->values()->all(),
                ];
            }),
            'entries' => JournalEntry::where('organization_id', $organization->id)->with('lines.account')->latest()->paginate(20),
            'canManage' => $request->user()->can('manageFinance', $organization),
            'canManagePeriods' => $request->user()->can('update', $organization),
            'canReopenPeriods' => $request->user()->can('reopenAccountingPeriod', $organization),
        ]);
    }

    public function exportTrialBalance(Request $request, TrialBalance $trialBalance): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? null;

        return response()->streamDownload(function () use ($trialBalance, $organization, $asOf): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            if ($asOf) {
                fputcsv($output, ['As of', $asOf]);
            }
            fputcsv($output, ['Code', 'Account', 'Type', 'Debits AED', 'Credits AED', 'Status']);
            foreach ($trialBalance->forOrganization($organization, $asOf) as $account) {
                // Account names are user-controlled; keep spreadsheet software from treating them as formulas.
                $name = preg_match('/^[\s]*[=+\-@]/u', $account['name']) ? "'".$account['name'] : $account['name'];
                fputcsv($output, [$account['code'], $name, $account['type'], $account['debit'], $account['credit'], $account['is_active'] ? 'Active' : 'Inactive']);
            }
            fclose($output);
        }, $asOf ? "trial-balance-aed-{$asOf}.csv" : 'trial-balance-aed.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportOverview(Request $request, FinanceOverview $overview): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? today()->toDateString();
        $report = $overview->for($organization, $asOf);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Finance overview AED', 'As of', $report['as_of']]);
            fputcsv($output, ['Receivables', $report['receivables'], 'Overdue', $report['overdue_receivables']]);
            fputcsv($output, ['Payables', $report['payables'], 'Overdue', $report['overdue_payables']]);
            fputcsv($output, ['Unmatched bank rows', $report['unmatched_bank_lines']]);
            fputcsv($output, ['Matched bank rows awaiting settlement', $report['unsettled_bank_lines']]);
            fputcsv($output, ['Current period', $this->safeCsvCell($report['current_period']['name'] ?? null), 'Status', $report['current_period']['status'] ?? 'none', 'Ends on', $report['current_period']['ends_on'] ?? '']);
            fclose($output);
        }, "finance-overview-aed-{$asOf}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function storeAccount(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.-]+$/', Rule::unique('ledger_accounts', 'code')->where('organization_id', $organization->id)], 'name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])]]);
        $account = LedgerAccount::create(['organization_id' => $organization->id, ...$input]);
        $audit->handle($organization, $request->user(), 'accounting.account.created', $account);

        return back();
    }

    public function updateAccount(Request $request, LedgerAccount $account, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $account->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['name' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']]);
        $account->update($input);
        $audit->handle($organization, $request->user(), 'accounting.account.updated', $account);

        return back();
    }

    public function storePeriod(Request $request, AccountingLedger $ledger): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('update', $organization);
        $input = $request->validate(['name' => ['required', 'string', 'max:255'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on']]);
        $ledger->createPeriod($organization, $request->user(), $input);

        return back();
    }

    public function closePeriod(Request $request, AccountingPeriod $period, AccountingLedger $ledger): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $period->organization_id === $organization->id, 404);
        $this->authorize('update', $organization);
        $ledger->closePeriod($organization, $request->user(), $period);

        return back();
    }

    public function reopenPeriod(Request $request, AccountingPeriod $period, AccountingLedger $ledger): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $period->organization_id === $organization->id, 404);
        $this->authorize('reopenAccountingPeriod', $organization);
        $ledger->reopenPeriod($organization, $request->user(), $period);

        return back();
    }

    public function postJournal(Request $request, AccountingLedger $ledger): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['posted_on' => ['required', 'date'], 'description' => ['required', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:2', 'max:100'], 'lines.*.ledger_account_id' => ['required', 'integer'], 'lines.*.company_id' => ['nullable', 'integer'], 'lines.*.branch_id' => ['nullable', 'integer'], 'lines.*.cost_centre_id' => ['nullable', 'integer'], 'lines.*.description' => ['nullable', 'string', 'max:255'], 'lines.*.debit' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'], 'lines.*.credit' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2']]);
        $ledger->post($organization, $request->user(), $input['posted_on'], $input['description'], $input['lines']);

        return back();
    }

    public function postOpeningBalance(Request $request, AccountingLedger $ledger): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate([
            'posted_on' => ['required', 'date'],
            'source_reference' => ['required', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.ledger_account_id' => ['required', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'lines.*.credit' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ]);
        $ledger->postOpeningBalance($organization, $request->user(), $input['posted_on'], trim($input['source_reference']), $input['lines']);

        return back();
    }

    public function reverseJournal(Request $request, JournalEntry $entry, AccountingLedger $ledger): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $entry->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['posted_on' => ['required', 'date']]);
        $ledger->reverse($organization, $request->user(), $entry, $input['posted_on']);

        return back();
    }

    private function safeCsvCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
