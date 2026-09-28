<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\CorporateTaxReturn;
use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Accounting\Models\FixedAssetReview;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PeriodCloseReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_period_ledger_and_operational_review_items(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $period = AccountingPeriod::create(['organization_id' => $organization->id, 'name' => '=September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        JournalEntry::create(['organization_id' => $organization->id, 'accounting_period_id' => $period->id, 'reference' => 'LEGACY-1', 'event' => 'legacy', 'posted_on' => '2026-09-05', 'debit_total' => 100, 'credit_total' => 100, 'currency' => 'AED']);
        Invoice::create(['organization_id' => $organization->id, 'reference' => 'DRAFT-INV', 'status' => 'draft', 'subtotal' => 100, 'total' => 100]);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Vendor']);
        VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'DRAFT-BILL', 'description' => 'Service', 'status' => 'draft', 'bill_date' => '2026-09-08', 'total' => 50]);
        $bank = BankAccount::forceCreate(['organization_id' => $organization->id, 'name' => 'Main', 'currency' => 'AED']);
        BankStatementLine::create(['organization_id' => $organization->id, 'bank_account_id' => $bank->id, 'imported_by' => $manager->id, 'import_batch' => Str::uuid(), 'external_id' => 'ROW-1', 'occurred_on' => '2026-09-10', 'description' => 'Receipt', 'amount' => 100]);

        $this->actingAs($manager)->get(route('accounting.periods.close-readiness', $period))->assertInertia(fn (Assert $page) => $page->component('finance/PeriodCloseReadiness')->where('journal_count', 1)->where('debits', '0.00')->where('credits', '0.00')->where('has_ledger_blocker', true)->where('checks.1.count', 1)->where('checks.2.count', 1)->where('checks.3.count', 1)->where('checks.4.count', 1));
        $csv = $this->actingAs($manager)->get(route('accounting.periods.close-readiness.export', $period))->assertOk()->streamedContent();
        $this->assertStringContainsString("\"Period close readiness\",'=September", $csv);
        $this->assertStringContainsString('"Starts on",2026-09-01,"Ends on",2026-09-30', $csv);
        $this->assertStringContainsString('"Detailed journals",1,"Debits AED",0.00,"Credits AED",0.00', $csv);
        $this->assertStringContainsString('"Unallocated legacy journal summaries",1,attention', $csv);
    }

    public function test_it_enforces_finance_access_and_tenant_scope(): void
    {
        $organization = Organization::factory()->create();
        $period = AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actingAs($outsider)->get(route('accounting.periods.close-readiness', $period))->assertForbidden();
        $this->actingAs($outsider)->get(route('accounting.periods.close-readiness.export', $period))->assertForbidden();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($manager)->get(route('accounting.periods.close-readiness', $period))->assertNotFound();
        $this->actingAs($manager)->get(route('accounting.periods.close-readiness.export', $period))->assertNotFound();
    }

    public function test_year_end_asset_review_checks_are_advisory(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $period = AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'December', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
        $asset = FixedAsset::create(['organization_id' => $organization->id, 'opening_source_reference' => 'OPEN', 'reference' => 'FA-YE', 'name' => 'Year-end asset', 'asset_class' => 'Equipment', 'classification' => 'ias16_cost_model', 'cost' => 1200, 'residual_value' => 0, 'useful_life_months' => 60, 'available_for_use_on' => '2026-01-01', 'created_by' => $manager->id]);

        $this->actingAs($manager)->get(route('accounting.periods.close-readiness', $period))->assertInertia(fn (Assert $page) => $page->where('has_ledger_blocker', false)->where('checks.6.key', 'missing_asset_reviews')->where('checks.6.count', 1)->where('checks.6.status', 'information')->where('checks.7.count', 0));

        FixedAssetReview::create(['organization_id' => $organization->id, 'fixed_asset_id' => $asset->id, 'review_year' => 2026, 'reviewed_on' => '2026-12-31', 'outcome' => 'change_required', 'residual_value_snapshot' => 0, 'useful_life_months_snapshot' => 60, 'depreciation_method' => 'straight_line', 'impairment_assessment_required' => true, 'notes' => 'Review required', 'reviewed_by' => $manager->id]);
        $this->actingAs($manager)->get(route('accounting.periods.close-readiness', $period))->assertInertia(fn (Assert $page) => $page->where('has_ledger_blocker', false)->where('checks.6.count', 0)->where('checks.7.key', 'asset_review_follow_up')->where('checks.7.count', 1)->where('checks.7.status', 'information'));
    }

    public function test_tax_year_end_reports_corporate_tax_follow_up_as_advisory(): void
    {
        $organization = Organization::factory()->create(['corporate_tax_profile' => 'resident_mainland', 'corporate_tax_year_start_month' => 1]);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $period = AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'December', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
        CorporateTaxReturn::create(['organization_id' => $organization->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'profile' => 'resident_mainland', 'accounting_profit' => 500000, 'revenue' => 500000, 'taxable_income' => 500000, 'tax_payable' => 11250, 'adjustment_notes' => 'Reviewed', 'prepared_by' => $manager->id]);

        $this->actingAs($manager)->get(route('accounting.periods.close-readiness', $period))->assertInertia(fn (Assert $page) => $page
            ->where('has_ledger_blocker', false)
            ->where('checks.8.key', 'corporate_tax_preparation')->where('checks.8.count', 1)->where('checks.8.status', 'information')
            ->where('checks.9.key', 'corporate_tax_filing')->where('checks.9.count', 1)
            ->where('checks.10.key', 'corporate_tax_payment')->where('checks.10.count', 1));
    }
}
