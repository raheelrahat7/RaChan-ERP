<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\ManageCorporateTaxReturn;
use App\Domain\Accounting\Actions\PrepareCorporateTaxReturn;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\CorporateTaxReturn;
use App\Domain\Accounting\Queries\FinancialStatements;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorporateTaxReturnController extends Controller
{
    public function index(Request $request, FinancialStatements $statements): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null && $organization->corporate_tax_profile !== null, 404);
        $this->authorize('viewFinance', $organization);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2023,2100']])['year'] ?? today()->year);
        $start = today()->setYear($year)->setMonth($organization->corporate_tax_year_start_month)->startOfMonth();
        if ($start->year < $year) {
            $start = $start->addYear();
        } $end = $start->addYear()->subDay();

        $return = CorporateTaxReturn::where('organization_id', $organization->id)->where('starts_on', $start)->first();

        return Inertia::render('finance/CorporateTaxReturn', ['profile' => $organization->corporate_tax_profile, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'dueOn' => $end->addMonthsNoOverflow(9)->toDateString(), 'profit' => $statements->profitAndLoss($organization, $start->toDateString(), $end->toDateString())['profit'], 'taxReturn' => $return, 'bankAccounts' => BankAccount::where('organization_id', $organization->id)->where('currency', 'AED')->whereNotNull('ledger_account_id')->get(['id', 'name']), 'canManage' => $request->user()->can('manageFinance', $organization), 'canApprove' => $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner)]);
    }

    public function export(Request $request): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $return = CorporateTaxReturn::where('organization_id', $organization->id)->findOrFail((int) $request->validate(['return_id' => ['required', 'integer']])['return_id']);

        return response()->streamDownload(function () use ($return): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($out, ['Corporate tax preparation', $return->starts_on->toDateString(), $return->ends_on->toDateString(), 'Due', $return->ends_on->addMonthsNoOverflow(9)->toDateString()]);
            foreach (['profile', 'status', 'accounting_profit', 'revenue', 'exempt_income', 'non_deductible_expenses', 'other_adjustments', 'qualifying_income', 'non_qualifying_income', 'non_qualifying_revenue', 'qfz_de_minimis_met', 'taxable_income', 'tax_payable', 'fta_reference'] as $field) {
                $value = (string) ($return->{$field} ?? '');
                fputcsv($out, [str_replace('_', ' ', $field), preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value]);
            }
            fputcsv($out, ['adjustment notes', preg_match('/^[\s]*[=+\-@]/u', $return->adjustment_notes) ? "'".$return->adjustment_notes : $return->adjustment_notes]);
            fclose($out);
        }, "corporate-tax-{$return->starts_on->year}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, PrepareCorporateTaxReturn $prepare): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'], 'revenue' => ['required', 'numeric', 'min:0'], 'exempt_income' => ['required', 'numeric', 'min:0'], 'non_deductible_expenses' => ['required', 'numeric', 'min:0'], 'other_adjustments' => ['required', 'numeric'], 'qualifying_income' => ['required', 'numeric'], 'non_qualifying_income' => ['required', 'numeric'], 'non_qualifying_revenue' => ['required', 'numeric', 'min:0'], 'small_business_relief_elected' => ['required', 'boolean'], 'prior_revenue_threshold_confirmed' => ['required', 'boolean'], 'qfz_conditions_confirmed' => ['required', 'boolean'], 'adjustment_notes' => ['required', 'string', 'max:5000']]);
        $prepare->handle($organization, $request->user(), $input);

        return back();
    }

    public function approve(Request $request, CorporateTaxReturn $taxReturn, ManageCorporateTaxReturn $manage): RedirectResponse
    {
        [$organization, $date] = $this->lifecycle($request, $taxReturn, true);
        $manage->approve($organization, $request->user(), $taxReturn, $date);

        return back();
    }

    public function file(Request $request, CorporateTaxReturn $taxReturn, ManageCorporateTaxReturn $manage): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $taxReturn->organization_id === $organization->id && $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner), 403);
        $input = $request->validate(['filed_on' => ['required', 'date'], 'fta_reference' => ['required', 'string', 'max:100']]);
        $manage->file($organization, $request->user(), $taxReturn, $input);

        return back();
    }

    public function pay(Request $request, CorporateTaxReturn $taxReturn, ManageCorporateTaxReturn $manage): RedirectResponse
    {
        [$organization, $date] = $this->lifecycle($request, $taxReturn);
        $bank = (int) $request->validate(['bank_account_id' => ['required', 'integer']])['bank_account_id'];
        $manage->pay($organization, $request->user(), $taxReturn, $bank, $date);

        return back();
    }

    public function reversePayment(Request $request, CorporateTaxReturn $taxReturn, ManageCorporateTaxReturn $manage): RedirectResponse
    {
        [$organization, $date] = $this->lifecycle($request, $taxReturn);
        $manage->reversePayment($organization, $request->user(), $taxReturn, $date);

        return back();
    }

    public function reverseProvision(Request $request, CorporateTaxReturn $taxReturn, ManageCorporateTaxReturn $manage): RedirectResponse
    {
        [$organization, $date] = $this->lifecycle($request, $taxReturn);
        $manage->reverseProvision($organization, $request->user(), $taxReturn, $date);

        return back();
    }

    /**
     * @return array{Organization, string}
     */
    private function lifecycle(Request $request, CorporateTaxReturn $return, bool $owner = false): array
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $return->organization_id === $organization->id, 404);
        $owner ? abort_unless($request->user()->hasOrganizationRole($organization, OrganizationRole::Owner), 403) : $this->authorize('manageFinance', $organization);

        return [$organization, $request->validate(['posted_on' => ['required', 'date']])['posted_on']];
    }
}
