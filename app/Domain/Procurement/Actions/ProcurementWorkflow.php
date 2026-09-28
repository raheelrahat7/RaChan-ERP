<?php

namespace App\Domain\Procurement\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Procurement\Models\ProcurementQuotation;
use App\Domain\Procurement\Models\ProcurementRfq;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcurementWorkflow
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function approve(PurchaseRequest $purchaseRequest, Organization $organization, User $actor): void
    {
        DB::transaction(function () use ($purchaseRequest, $organization, $actor): void {
            $request = PurchaseRequest::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($purchaseRequest->id);
            abort_unless($request->status === 'submitted' && $request->requested_by !== $actor->id, 422);
            $request->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->handle($organization, $actor, 'procurement.request.approved', $request);
        });
    }

    public function issueOrder(ProcurementQuotation $quotation, Organization $organization, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($quotation, $organization, $actor): PurchaseOrder {
            $quote = ProcurementQuotation::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($quotation->id);
            $rfq = ProcurementRfq::where('organization_id', $organization->id)->findOrFail($quote->rfq_id);
            $request = PurchaseRequest::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($rfq->purchase_request_id);
            abort_unless($request->status === 'approved' && $quote->status === 'received', 422);
            abort_if(PurchaseOrder::where('purchase_request_id', $request->id)->exists(), 422);
            $order = PurchaseOrder::create(['organization_id' => $organization->id, 'purchase_request_id' => $request->id, 'quotation_id' => $quote->id, 'vendor_id' => $rfq->vendor_id, 'reference' => 'PO-'.Str::upper(Str::random(10)), 'total' => $quote->total, 'currency' => $quote->currency]);
            $request->update(['status' => 'ordered']);
            $quote->update(['status' => 'accepted']);
            ProcurementQuotation::whereIn('rfq_id', ProcurementRfq::where('purchase_request_id', $request->id)->pluck('id'))->where('id', '!=', $quote->id)->update(['status' => 'declined']);
            $this->audit->handle($organization, $actor, 'procurement.order.issued', $order);

            return $order;
        });
    }

    public function receive(PurchaseOrder $purchaseOrder, Organization $organization, User $actor, string $note): void
    {
        DB::transaction(function () use ($purchaseOrder, $organization, $actor, $note): void {
            $order = PurchaseOrder::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($purchaseOrder->id);
            abort_unless($order->status === 'issued', 422);
            $order->update(['status' => 'received', 'received_on' => today(), 'receipt_note' => $note]);
            $this->audit->handle($organization, $actor, 'procurement.order.received', $order);
        });
    }

    public function convertToBill(PurchaseOrder $purchaseOrder, Organization $organization, User $actor, string $billDate, ?string $dueOn): VendorBill
    {
        return DB::transaction(function () use ($purchaseOrder, $organization, $actor, $billDate, $dueOn): VendorBill {
            $order = PurchaseOrder::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($purchaseOrder->id);
            abort_unless($order->status === 'received' && $order->vendor_bill_id === null, 422);
            $request = PurchaseRequest::where('organization_id', $organization->id)->findOrFail($order->purchase_request_id);
            $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $order->vendor_id, 'property_id' => $request->property_id, 'reference' => 'BIL-'.Str::upper(Str::random(10)), 'description' => 'Purchase order '.$order->reference.': '.$request->purpose, 'bill_date' => $billDate, 'due_on' => $dueOn, 'total' => $order->total, 'currency' => $order->currency]);
            $order->update(['status' => 'billed', 'vendor_bill_id' => $bill->id]);
            $this->audit->handle($organization, $actor, 'procurement.order.billed', $order, ['vendor_bill_id' => $bill->id]);

            return $bill;
        });
    }
}
