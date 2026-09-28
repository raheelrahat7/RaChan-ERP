<?php

namespace Tests\Feature\Finance;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmContact;
use App\Models\Invoice;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OutstandingBalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconstructs_outstanding_balances_at_the_selected_date(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => '=Supplier']);
        $contact = CrmContact::create(['organization_id' => $organization->id, 'first_name' => '=Amina', 'last_name' => 'Khan']);
        $invoice = Invoice::create(['organization_id' => $organization->id, 'contact_id' => $contact->id, 'reference' => '=INV-1', 'status' => 'paid', 'issued_on' => '2026-09-01', 'due_on' => '2026-09-05', 'subtotal' => 1000, 'total' => 1000]);
        Payment::create(['organization_id' => $organization->id, 'invoice_id' => $invoice->id, 'reference' => 'PAY-1', 'amount' => 250, 'currency' => 'AED', 'received_on' => '2026-09-08']);
        Payment::create(['organization_id' => $organization->id, 'invoice_id' => $invoice->id, 'reference' => 'PAY-2', 'amount' => 750, 'currency' => 'AED', 'received_on' => '2026-09-20']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'BIL-1', 'description' => 'Service', 'status' => 'partial', 'bill_date' => '2026-09-02', 'total' => 500]);
        VendorBillPayment::create(['organization_id' => $organization->id, 'vendor_bill_id' => $bill->id, 'reference' => 'VPM-1', 'amount' => 100, 'paid_on' => '2026-09-09']);
        $this->actingAs($manager)->get(route('accounting.outstanding-balances', ['as_of' => '2026-09-10']))->assertInertia(fn (Assert $page) => $page->component('finance/OutstandingBalances')->where('report.receivables.0.reference', '=INV-1')->where('report.receivables.0.party', '=Amina Khan')->where('report.receivables.0.paid', '250.00')->where('report.receivables.0.balance', '750.00')->where('report.receivables.0.days_overdue', 5)->where('report.payables.0.party', '=Supplier')->where('report.payables.0.balance', '400.00')->where('report.payables.0.days_overdue', 0)->where('report.total_receivables', '750.00')->where('report.total_payables', '400.00')->where('report.overdue_receivables_count', 1)->where('report.overdue_receivables', '750.00')->where('report.overdue_payables_count', 0)->where('report.overdue_payables', '0.00'));
        $csv = $this->actingAs($manager)->get(route('accounting.outstanding-balances.export', ['as_of' => '2026-09-10']))->assertOk()->streamedContent();
        $this->assertStringContainsString("Receivable,'=INV-1", $csv);
        $this->assertStringContainsString("'=Amina Khan", $csv);
        $this->assertStringContainsString("Payable,BIL-1,'=Supplier", $csv);
        $this->actingAs($manager)->get(route('accounting.outstanding-balances', ['as_of' => '2026-09-10', 'scope' => 'overdue']))->assertInertia(fn (Assert $page) => $page->where('filters.scope', 'overdue')->has('report.receivables', 1)->has('report.payables', 0)->where('report.total_payables', '0.00'));
        $this->actingAs($manager)->get(route('accounting.outstanding-balances', ['as_of' => '2026-09-10', 'scope' => 'not_overdue']))->assertInertia(fn (Assert $page) => $page->has('report.receivables', 0)->has('report.payables', 1)->where('report.total_receivables', '0.00'));
    }

    public function test_it_filters_non_outstanding_documents_and_requires_finance_access(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        foreach ([['DRAFT', 'draft', '2026-09-01'], ['FUTURE', 'posted', '2026-09-11']] as [$reference, $status, $issued]) {
            Invoice::create(['organization_id' => $organization->id, 'reference' => $reference, 'status' => $status, 'issued_on' => $issued, 'subtotal' => 100, 'total' => 100]);
        }
        $settled = Invoice::create(['organization_id' => $organization->id, 'reference' => 'SETTLED', 'status' => 'paid', 'issued_on' => '2026-09-01', 'subtotal' => 100, 'total' => 100]);
        Payment::create(['organization_id' => $organization->id, 'invoice_id' => $settled->id, 'reference' => 'PAY-SETTLED', 'amount' => 100, 'currency' => 'AED', 'received_on' => '2026-09-02']);
        $other = Organization::factory()->create();
        Invoice::create(['organization_id' => $other->id, 'reference' => 'OTHER', 'status' => 'posted', 'issued_on' => '2026-09-01', 'subtotal' => 100, 'total' => 100]);
        $this->actingAs($manager)->get(route('accounting.outstanding-balances', ['as_of' => '2026-09-10']))->assertInertia(fn (Assert $page) => $page->has('report.receivables', 0));
        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actingAs($outsider)->get(route('accounting.outstanding-balances'))->assertForbidden();
        $this->actingAs($outsider)->get(route('accounting.outstanding-balances.export'))->assertForbidden();
        $this->actingAs($manager)->get(route('accounting.outstanding-balances', ['scope' => 'late']))->assertSessionHasErrors('scope');
    }
}
