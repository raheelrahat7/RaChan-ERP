<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Actions\AccountingLedger;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\CorporateTaxReturn;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CorporateTaxReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_year_defaults_to_current_year_and_accepts_an_explicit_year(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 22));
        $organization = Organization::factory()->create(['corporate_tax_profile' => 'resident_mainland', 'corporate_tax_year_start_month' => 4]);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);

        $this->actingAs($manager)->get(route('accounting.corporate-tax'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/CorporateTaxReturn')
            ->where('from', '2026-04-01')->where('to', '2027-03-31')->where('dueOn', '2027-12-31'));
        $this->get(route('accounting.corporate-tax', ['year' => 2025]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('from', '2025-04-01')->where('to', '2026-03-31'));
        $this->get(route('accounting.corporate-tax', ['year' => '']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('from', '2026-04-01'));
    }

    public function test_mainland_and_qfz_preparations_apply_the_approved_rates(): void
    {
        $organization = Organization::factory()->create(['corporate_tax_profile' => 'resident_mainland']);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Tax year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $income = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'income']);
        $cash = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
        app(AccountingLedger::class)->post($organization, $manager, '2026-06-01', 'Income', [['ledger_account_id' => $cash->id, 'debit' => 500000, 'credit' => 0], ['ledger_account_id' => $income->id, 'debit' => 0, 'credit' => 500000]]);
        $base = ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'revenue' => 500000, 'exempt_income' => 0, 'non_deductible_expenses' => 0, 'other_adjustments' => 0, 'qualifying_income' => 0, 'non_qualifying_income' => 0, 'non_qualifying_revenue' => 0, 'small_business_relief_elected' => false, 'prior_revenue_threshold_confirmed' => true, 'qfz_conditions_confirmed' => false, 'adjustment_notes' => 'Reviewed'];
        $this->actingAs($manager)->post(route('accounting.corporate-tax.store'), $base)->assertRedirect();
        $this->assertSame('11250.00', CorporateTaxReturn::sole()->tax_payable);
        $organization->update(['corporate_tax_profile' => 'qualifying_free_zone']);
        $manager->unsetRelation('currentOrganization');
        CorporateTaxReturn::query()->delete();
        $this->actingAs($manager)->post(route('accounting.corporate-tax.store'), [...$base, 'qualifying_income' => 400000, 'non_qualifying_income' => 100000, 'non_qualifying_revenue' => 20000, 'qfz_conditions_confirmed' => true])->assertRedirect();
        $return = CorporateTaxReturn::sole();
        $this->assertTrue($return->qfz_de_minimis_met);
        $this->assertSame('9000.00', $return->tax_payable);
        $bankLedger = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1010', 'name' => 'Bank', 'type' => 'asset', 'is_active' => true]);
        $bank = BankAccount::create(['organization_id' => $organization->id, 'name' => 'Bank', 'currency' => 'AED', 'ledger_account_id' => $bankLedger->id]);
        $this->actingAs($manager)->post(route('accounting.corporate-tax.approve', $return), ['posted_on' => '2026-12-31'])->assertForbidden();
        $this->actingAs($owner)->post(route('accounting.corporate-tax.approve', $return), ['posted_on' => '2026-12-31'])->assertRedirect();
        $return->refresh();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $return->provision_journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '5400')->sole()->id, 'debit' => 9000]);
        $this->actingAs($owner)->post(route('accounting.corporate-tax.file', $return), ['filed_on' => '2026-12-31', 'fta_reference' => 'CT-2026-1'])->assertRedirect();
        $csv = $this->actingAs($manager)->get(route('accounting.corporate-tax.export', ['return_id' => $return->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Corporate tax preparation",2026-01-01,2026-12-31,Due,2027-09-30', $csv);
        $this->assertStringContainsString('"tax payable",9000.00', $csv);
        $this->actingAs($manager)->post(route('accounting.corporate-tax.pay', $return), ['posted_on' => '2026-12-31', 'bank_account_id' => $bank->id])->assertRedirect();
        $return->refresh();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $return->payment_journal_entry_id, 'ledger_account_id' => $bankLedger->id, 'credit' => 9000]);
        $this->actingAs($manager)->post(route('accounting.corporate-tax.provision.reverse', $return), ['posted_on' => '2026-12-31'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.corporate-tax.payment.reverse', $return), ['posted_on' => '2026-12-31'])->assertRedirect();
        $this->actingAs($manager)->post(route('accounting.corporate-tax.provision.reverse', $return), ['posted_on' => '2026-12-31'])->assertRedirect();
    }

    public function test_closed_period_and_tenant_boundaries_are_enforced(): void
    {
        $organization = Organization::factory()->create(['corporate_tax_profile' => 'resident_mainland']);
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Closed', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'closed']);
        $return = CorporateTaxReturn::create(['organization_id' => $organization->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'profile' => 'resident_mainland', 'accounting_profit' => 500000, 'revenue' => 500000, 'taxable_income' => 500000, 'tax_payable' => 11250, 'adjustment_notes' => 'Reviewed', 'prepared_by' => $owner->id]);
        $this->actingAs($owner)->post(route('accounting.corporate-tax.approve', $return), ['posted_on' => '2026-12-31'])->assertStatus(422);

        $other = Organization::factory()->create(['corporate_tax_profile' => 'resident_mainland']);
        $otherOwner = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($otherOwner, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($otherOwner)->get(route('accounting.corporate-tax.export', ['return_id' => $return->id]))->assertNotFound();
        $this->actingAs($otherOwner)->post(route('accounting.corporate-tax.file', $return), ['filed_on' => '2027-01-01', 'fta_reference' => 'FOREIGN'])->assertForbidden();
    }
}
