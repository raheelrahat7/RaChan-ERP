<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\CorporateTaxReturn;
use App\Domain\Accounting\Queries\FinancialStatements;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;

class PrepareCorporateTaxReturn
{
    public function __construct(private FinancialStatements $statements, private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Organization $organization, User $actor, array $input): CorporateTaxReturn
    {
        abort_unless($organization->corporate_tax_profile !== null, 422, 'Configure the corporate-tax profile first.');
        $existing = CorporateTaxReturn::where('organization_id', $organization->id)->where('starts_on', $input['starts_on'])->where('ends_on', $input['ends_on'])->first();
        abort_if($existing?->provision_journal_entry_id && ! $existing->provision_reversal_journal_entry_id, 422, 'Reverse the active corporate-tax provision before recalculating.');
        $profit = (float) $this->statements->profitAndLoss($organization, $input['starts_on'], $input['ends_on'])['profit'];
        $adjusted = round($profit - (float) $input['exempt_income'] + (float) $input['non_deductible_expenses'] + (float) $input['other_adjustments'], 2);
        $taxable = max(0, $adjusted);
        $tax = 0.0;
        $deMinimis = null;

        if ($organization->corporate_tax_profile === 'resident_mainland') {
            $relief = $input['small_business_relief_elected'];
            abort_if($relief && ((float) $input['revenue'] > 3000000 || ! $input['prior_revenue_threshold_confirmed']), 422, 'Small Business Relief requires revenue at or below AED 3 million in this and all prior tax periods.');
            $taxable = $relief ? 0 : $taxable;
            $tax = round(max(0, $taxable - 375000) * .09, 2);
        } else {
            abort_unless($input['qfz_conditions_confirmed'], 422, 'QFZ conditions must be confirmed for this period.');
            abort_if(abs(((float) $input['qualifying_income'] + (float) $input['non_qualifying_income']) - $adjusted) > .01, 422, 'Qualifying and non-qualifying income must equal adjusted accounting income.');
            $limit = min((float) $input['revenue'] * .05, 5000000);
            $deMinimis = (float) $input['non_qualifying_revenue'] <= $limit;
            $taxable = $deMinimis ? max(0, (float) $input['non_qualifying_income']) : max(0, $adjusted);
            $tax = round($deMinimis ? $taxable * .09 : max(0, $taxable - 375000) * .09, 2);
        }

        $return = CorporateTaxReturn::updateOrCreate(['organization_id' => $organization->id, 'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on']], [...$input, 'profile' => $organization->corporate_tax_profile, 'accounting_profit' => $profit, 'qfz_de_minimis_met' => $deMinimis, 'taxable_income' => $taxable, 'tax_payable' => $tax, 'prepared_by' => $actor->id, 'status' => 'prepared', 'approved_by' => null, 'filed_on' => null, 'fta_reference' => null, 'filed_by' => null, 'provision_journal_entry_id' => null, 'provision_reversal_journal_entry_id' => null, 'bank_account_id' => null, 'payment_journal_entry_id' => null, 'payment_reversal_journal_entry_id' => null]);
        $this->audit->handle($organization, $actor, 'accounting.corporate_tax_return.prepared', $return, ['tax_payable' => $tax]);

        return $return;
    }
}
