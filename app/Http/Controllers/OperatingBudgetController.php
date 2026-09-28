<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Actions\ManageOperatingBudget;
use App\Domain\Finance\Models\OperatingBudget;
use App\Domain\Finance\Queries\OperatingBudgetReport;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperatingBudgetController extends Controller
{
    public function __invoke(Request $request, OperatingBudgetReport $report): Response
    {
        [$org, $year, $month] = $this->context($request);
        $data = $report->for($org, $year, $month);
        $draft = $data['draft'];
        $pending = $data['pending'];
        foreach (['draft' => $draft, 'pending' => $pending] as $key => $budget) {
            $data[$key] = $budget ? [
                'id' => $budget->id,
                'year' => $budget->year,
                'version' => $budget->version,
                'status' => $budget->status,
                'effective_from' => $budget->effective_from?->toDateString(),
                'reason' => $budget->reason,
                'rejection_reason' => $budget->rejection_reason,
                'submitted_by' => $budget->submitted_by,
                'submitter_name' => $budget->submitter?->name,
                'lines' => $budget->lines->groupBy('ledger_account_id')->map(fn ($lines): array => [
                    'account_id' => (int) $lines->first()->ledger_account_id,
                    'account' => $lines->first()->account?->only('id', 'code', 'name'),
                    'monthly_amounts' => $lines->sortBy('month')->mapWithKeys(fn ($line): array => [$line->month - 1 => $line->amount])->all(),
                ])->values(),
            ] : null;
        }

        return Inertia::render('finance/OperatingBudgets', [
            'filters' => ['year' => $year, 'through_month' => $month],
            'report' => $data,
            'canManage' => $request->user()->can('manageFinance', $org),
            'canReviewPending' => ($request->user()->hasOrganizationRole($org, OrganizationRole::Owner) || $request->user()->hasOrganizationRole($org, OrganizationRole::Administrator)) && ($pending?->submitted_by !== $request->user()->id),
            'canCreate' => $request->user()->can('manageFinance', $org) && ! $draft && ! $pending,
        ]);
    }

    public function export(Request $request, OperatingBudgetReport $report): StreamedResponse
    {
        [$org, $year, $month] = $this->context($request);
        $data = $report->for($org, $year, $month);

        return response()->streamDownload(function () use ($data, $year, $month): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Operating budget AED', 'Year', $year, 'Through month', $month, 'Approved version', $data['selected_version'] ?? 'None']);
            fputcsv($output, ['Account code', 'Account name', 'Budget through month', 'Actual through month', 'Remaining', 'Variance actual minus budget']);
            foreach ($data['rows'] as $row) {
                fputcsv($output, [$this->safeCell($row['code']), $this->safeCell($row['name']), $row['budget_to_date'], $row['actual_to_date'], $row['remaining'], $row['variance_to_date']]);
            }
            fputcsv($output, ['Totals', '', $data['totals']['budget_to_date'], $data['totals']['actual_to_date'], $data['totals']['remaining'], $data['totals']['variance_to_date']]);
            fputcsv($output, []);
            fputcsv($output, ['Monthly detail', 'Account code', 'Account name', 'Budget', 'Actual', 'Variance', 'Approved version']);
            foreach ($data['rows'] as $row) {
                foreach ($row['months'] as $monthNumber => $values) {
                    fputcsv($output, [$monthNumber, $this->safeCell($row['code']), $this->safeCell($row['name']), $values['budget'], $values['actual'] ?? '', $values['variance'] ?? '', $values['version'] ?? '']);
                }
            }
            fclose($output);
        }, "operating-budget-aed-{$year}-through-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, ManageOperatingBudget $budgets): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorize('manageFinance', $org);
        $input = $request->validate(['year' => ['required', 'integer', 'min:'.today()->year, 'max:9999'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);
        $budgets->createDraft($org, $request->user(), (int) $input['year'], $input['effective_from'], $input['reason']);

        return back();
    }

    public function saveAccount(Request $request, OperatingBudget $budget, ManageOperatingBudget $budgets): RedirectResponse
    {
        $org = $this->budgetOrganization($request, $budget);
        $this->authorize('manageFinance', $org);
        $input = $request->validate([
            'account_id' => ['required', 'integer'],
            'monthly_amounts' => ['required', 'array:0,1,2,3,4,5,6,7,8,9,10,11', 'size:12'],
            'monthly_amounts.*' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
        ]);
        $budgets->saveAccount($org, $request->user(), $budget, (int) $input['account_id'], array_values($input['monthly_amounts']));

        return back();
    }

    public function removeAccount(Request $request, OperatingBudget $budget, int $account, ManageOperatingBudget $budgets): RedirectResponse
    {
        $org = $this->budgetOrganization($request, $budget);
        $this->authorize('manageFinance', $org);
        $budgets->removeAccount($org, $request->user(), $budget, $account);

        return back();
    }

    public function rebase(Request $request, OperatingBudget $budget, ManageOperatingBudget $budgets): RedirectResponse
    {
        $org = $this->budgetOrganization($request, $budget);
        $this->authorize('manageFinance', $org);
        $effectiveFrom = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d']])['effective_from'];
        $budgets->rebase($org, $request->user(), $budget, $effectiveFrom);

        return back();
    }

    public function submit(Request $request, OperatingBudget $budget, ManageOperatingBudget $budgets): RedirectResponse
    {
        $org = $this->budgetOrganization($request, $budget);
        $this->authorize('manageFinance', $org);
        $budgets->submit($org, $request->user(), $budget);

        return back();
    }

    public function approve(Request $request, OperatingBudget $budget, ManageOperatingBudget $budgets): RedirectResponse
    {
        $org = $this->budgetOrganization($request, $budget);
        $budgets->approve($org, $request->user(), $budget);

        return back();
    }

    public function reject(Request $request, OperatingBudget $budget, ManageOperatingBudget $budgets): RedirectResponse
    {
        $org = $this->budgetOrganization($request, $budget);
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $budgets->reject($org, $request->user(), $budget, $input['reason']);

        return back();
    }

    /** @return array{Organization, int, int} */
    private function context(Request $request): array
    {
        $org = $this->organization($request);
        $this->authorize('viewFinance', $org);
        $filters = $request->validate(['year' => ['nullable', 'integer', 'min:1900', 'max:9999'], 'through_month' => ['nullable', 'integer', 'between:1,12']]);

        return [$org, (int) ($filters['year'] ?? today()->year), (int) ($filters['through_month'] ?? today()->month)];
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }

    private function budgetOrganization(Request $request, OperatingBudget $budget): Organization
    {
        $org = $this->organization($request);
        abort_unless($budget->organization_id === $org->id, 404);

        return $org;
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
