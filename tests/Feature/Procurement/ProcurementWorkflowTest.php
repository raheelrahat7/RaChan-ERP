<?php

namespace Tests\Feature\Procurement;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Procurement\Models\ProcurementQuotation;
use App\Domain\Procurement\Models\ProcurementRfq;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_to_vendor_bill_requires_separate_approval_and_receipt(): void
    {
        $organization = Organization::factory()->create();
        $requester = User::factory()->create(['current_organization_id' => $organization->id]);
        $approver = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($requester, ['role' => OrganizationRole::Manager->value]);
        $organization->users()->attach($approver, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Marina', 'type' => 'residential']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Cool Air']);

        $this->actingAs($requester)->post(route('procurement.requests.store'), ['purpose' => 'Cooling repair', 'property_id' => $property->id, 'lines' => [['description' => 'Compressor', 'quantity' => 2, 'unit' => 'each']]])->assertRedirect();
        $purchaseRequest = PurchaseRequest::sole();
        $this->assertDatabaseCount('purchase_request_lines', 1);
        $this->actingAs($requester)->post(route('procurement.requests.submit', $purchaseRequest))->assertRedirect();
        $this->actingAs($requester)->post(route('procurement.requests.approve', $purchaseRequest))->assertStatus(422);
        $this->actingAs($approver)->post(route('procurement.requests.approve', $purchaseRequest))->assertRedirect();
        $this->actingAs($requester)->post(route('procurement.rfqs.store', $purchaseRequest), ['vendor_id' => $vendor->id])->assertRedirect();
        $rfq = ProcurementRfq::sole();
        $this->actingAs($requester)->post(route('procurement.quotations.store', $rfq), ['total' => 1500])->assertRedirect();
        $quote = ProcurementQuotation::sole();
        $this->actingAs($requester)->post(route('procurement.orders.store', $quote))->assertRedirect();
        $order = PurchaseOrder::sole();
        $this->actingAs($requester)->post(route('procurement.orders.bill', $order), ['bill_date' => today()->toDateString()])->assertStatus(422);
        $this->actingAs($requester)->post(route('procurement.orders.receive', $order), ['receipt_note' => 'Two compressors received'])->assertRedirect();
        $this->actingAs($requester)->post(route('procurement.orders.bill', $order), ['bill_date' => today()->toDateString()])->assertRedirect();

        $this->assertDatabaseHas('vendor_bills', ['vendor_id' => $vendor->id, 'property_id' => $property->id, 'total' => 1500, 'status' => 'draft']);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'billed', 'vendor_bill_id' => VendorBill::sole()->id]);
        $this->actingAs($requester)->post(route('procurement.orders.bill', $order), ['bill_date' => today()->toDateString()])->assertStatus(422);
    }

    public function test_cross_organization_records_and_vendors_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $foreignVendor = MaintenanceVendor::create(['organization_id' => $other->id, 'name' => 'Other vendor']);
        $purchaseRequest = PurchaseRequest::create(['organization_id' => $other->id, 'requested_by' => $manager->id, 'reference' => 'PR-OTHER', 'purpose' => 'Other', 'status' => 'approved']);

        $this->actingAs($manager)->post(route('procurement.requests.approve', $purchaseRequest))->assertNotFound();
        $this->actingAs($manager)->post(route('procurement.rfqs.store', $purchaseRequest), ['vendor_id' => $foreignVendor->id])->assertNotFound();
        $localRequest = PurchaseRequest::create(['organization_id' => $organization->id, 'requested_by' => $manager->id, 'reference' => 'PR-LOCAL', 'purpose' => 'Local', 'status' => 'approved']);
        $this->actingAs($manager)->post(route('procurement.rfqs.store', $localRequest), ['vendor_id' => $foreignVendor->id])->assertNotFound();
    }
}
