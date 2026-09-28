<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorBillWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_post_and_fully_pay_a_property_allocated_vendor_bill(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Marina', 'type' => 'residential']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Cool Air']);

        $this->actingAs($manager)->post(route('vendor-bills.store'), ['vendor_id' => $vendor->id, 'property_id' => $property->id, 'description' => 'AC service', 'bill_date' => today()->toDateString(), 'due_on' => today()->addWeek()->toDateString(), 'total' => 1000, 'accounting_treatment' => 'operating_expense'])->assertRedirect();
        $bill = VendorBill::sole();
        $this->actingAs($manager)->post(route('vendor-bills.post', $bill))->assertRedirect();
        $this->actingAs($manager)->post(route('vendor-bills.pay', $bill), ['amount' => 400])->assertRedirect();
        $this->actingAs($manager)->post(route('vendor-bills.pay', $bill), ['amount' => 600])->assertRedirect();

        $this->assertDatabaseHas('vendor_bills', ['id' => $bill->id, 'status' => 'paid']);
        $this->assertDatabaseCount('vendor_bill_payments', 2);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('journal_lines', 6);
        $this->assertDatabaseHas('journal_lines', ['ledger_account_id' => LedgerAccount::where('code', '5000')->sole()->id, 'debit' => 1000]);
    }

    public function test_a_draft_bill_can_be_classified_as_a_capital_asset_before_posting(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Vendor']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'BIL-CAPEX', 'description' => 'New equipment', 'bill_date' => today(), 'total' => 1200]);

        $this->actingAs($manager)->post(route('vendor-bills.post', $bill))->assertStatus(422);
        $this->actingAs($manager)->put(route('vendor-bills.treatment.update', $bill), ['accounting_treatment' => 'capital_asset'])->assertRedirect();
        $this->actingAs($manager)->post(route('vendor-bills.post', $bill))->assertRedirect();
        $this->assertDatabaseHas('journal_lines', ['ledger_account_id' => LedgerAccount::where('code', '1500')->sole()->id, 'debit' => 1200]);
    }
}
