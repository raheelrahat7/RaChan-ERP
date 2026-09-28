<?php

namespace Tests\Feature\Finance;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Leasing\Models\LeaseServiceCharge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnerStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_user_can_view_and_export_a_tenant_scoped_owner_statement(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $owner = Owner::create(['organization_id' => $organization->id, 'name' => 'Forty Percent Owner', 'reference' => 'OWN-40']);
        $foreignOwner = Owner::create(['organization_id' => $other->id, 'name' => 'Foreign Owner']);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Canal House', 'type' => 'residential']);
        $property->owners()->attach($owner, ['ownership_share' => 40]);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '1201', 'type' => 'apartment']);
        $lease = Lease::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'LSE-OWNER', 'status' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $invoice = Invoice::create(['organization_id' => $organization->id, 'reference' => 'INV-OWNER', 'status' => 'posted', 'accounting_treatment' => 'revenue', 'issued_on' => '2026-06-15', 'due_on' => '2026-06-30', 'subtotal' => 1000, 'total' => 1050]);
        LeaseServiceCharge::create(['organization_id' => $organization->id, 'lease_id' => $lease->id, 'invoice_id' => $invoice->id, 'category' => 'service_charge', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2026-06-30', 'net_amount' => 1000, 'due_on' => '2026-06-30', 'created_by' => $manager->id]);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Repair Co']);
        VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'property_id' => $property->id, 'reference' => 'BILL-OWNER', 'description' => 'Air conditioning repair', 'status' => 'posted', 'accounting_treatment' => 'operating_expense', 'bill_date' => '2026-06-20', 'due_on' => '2026-07-01', 'total' => 500, 'vat_amount' => 25, 'input_vat_recoverable' => true]);
        VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'property_id' => $property->id, 'reference' => 'BILL-ASSET', 'description' => 'Capital asset', 'status' => 'posted', 'accounting_treatment' => 'capital_asset', 'bill_date' => '2026-06-21', 'total' => 900]);

        $query = ['owner_id' => $owner->id, 'from' => '2026-06-01', 'to' => '2026-06-30'];
        $this->actingAs($manager)->get(route('reports.owner-statements.index', $query))->assertInertia(fn (Assert $page) => $page
            ->component('finance/OwnerStatements')
            ->where('statement.owner.name', 'Forty Percent Owner')
            ->has('statement.rows', 2)
            ->where('statement.rows.0.reference', 'INV-OWNER')
            ->where('statement.rows.0.owner_amount', 400)
            ->where('statement.rows.1.reference', 'BILL-OWNER')
            ->where('statement.rows.1.owner_amount', 190)
            ->where('statement.totals.income', '400.00')
            ->where('statement.totals.expenses', '190.00')
            ->where('statement.totals.net', '210.00'));
        $this->get(route('reports.owner-statements.export', $query))->assertOk()->assertDownload('owner-statement-'.$owner->id.'-2026-06-01-2026-06-30.csv');
        $this->get(route('reports.owner-statements.index', [...$query, 'owner_id' => $foreignOwner->id]))->assertNotFound();
    }
}
