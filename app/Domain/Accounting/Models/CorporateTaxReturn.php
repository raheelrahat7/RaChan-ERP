<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'starts_on', 'ends_on', 'profile', 'accounting_profit', 'revenue', 'exempt_income', 'non_deductible_expenses', 'other_adjustments', 'qualifying_income', 'non_qualifying_income', 'non_qualifying_revenue', 'small_business_relief_elected', 'prior_revenue_threshold_confirmed', 'qfz_conditions_confirmed', 'qfz_de_minimis_met', 'taxable_income', 'tax_payable', 'adjustment_notes', 'prepared_by', 'status', 'approved_by', 'provision_journal_entry_id', 'provision_reversal_journal_entry_id', 'filed_on', 'fta_reference', 'filed_by', 'bank_account_id', 'payment_journal_entry_id', 'payment_reversal_journal_entry_id'])]
class CorporateTaxReturn extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'filed_on' => 'date', 'small_business_relief_elected' => 'boolean', 'prior_revenue_threshold_confirmed' => 'boolean', 'qfz_conditions_confirmed' => 'boolean', 'qfz_de_minimis_met' => 'boolean'];
    }
}
