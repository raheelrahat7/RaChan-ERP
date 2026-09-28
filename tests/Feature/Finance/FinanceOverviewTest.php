<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Invoice;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_page_shows_current_tenant_finance_overview(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => '=Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        Invoice::create(['organization_id' => $organization->id, 'reference' => 'INV-OVERVIEW', 'status' => 'posted', 'issued_on' => today()->subDay(), 'due_on' => today()->subDay(), 'subtotal' => 700, 'total' => 700, 'currency' => 'AED']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Vendor']);
        VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'BIL-OVERVIEW', 'description' => 'Service', 'status' => 'posted', 'bill_date' => today(), 'due_on' => today()->addDay(), 'total' => 300, 'currency' => 'AED']);
        $bank = BankAccount::forceCreate(['organization_id' => $organization->id, 'name' => 'Main', 'currency' => 'AED']);
        BankStatementLine::create(['organization_id' => $organization->id, 'bank_account_id' => $bank->id, 'imported_by' => $manager->id, 'import_batch' => Str::uuid(), 'external_id' => 'OVERVIEW-ROW', 'occurred_on' => today(), 'description' => 'Receipt', 'amount' => 700]);
        $other = Organization::factory()->create();
        Invoice::create(['organization_id' => $other->id, 'reference' => 'OTHER-INV', 'status' => 'posted', 'issued_on' => today(), 'subtotal' => 900, 'total' => 900, 'currency' => 'AED']);

        $this->actingAs($manager)->get(route('accounting.index'))->assertInertia(fn (Assert $page) => $page->component('finance/Accounting')->where('overview.receivables', '700.00')->where('overview.payables', '300.00')->where('overview.overdue_receivables', '700.00')->where('overview.overdue_payables', '0.00')->where('overview.unmatched_bank_lines', 1)->where('overview.unsettled_bank_lines', 0)->where('overview.current_period.name', '=Current'));
        $csv = $this->actingAs($manager)->get(route('accounting.overview.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Receivables,700.00,Overdue,700.00', $csv);
        $this->assertStringContainsString("\"Current period\",'=Current", $csv);
        $historicalDate = today()->subDay()->toDateString();
        $this->actingAs($manager)->get(route('accounting.index', ['as_of' => $historicalDate]))->assertInertia(fn (Assert $page) => $page->where('overview.as_of', $historicalDate)->where('overview.payables', '0.00')->where('asOf', $historicalDate));
        $historicalCsv = $this->actingAs($manager)->get(route('accounting.overview.export', ['as_of' => $historicalDate]))->assertOk()->streamedContent();
        $this->assertStringContainsString('"As of",'.$historicalDate, $historicalCsv);
        $this->actingAs($manager)->get(route('accounting.overview.export', ['as_of' => 'not-a-date']))->assertSessionHasErrors('as_of');
    }
}
