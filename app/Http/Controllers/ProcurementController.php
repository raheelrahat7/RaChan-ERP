<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Procurement\Actions\ProcurementWorkflow;
use App\Domain\Procurement\Models\ProcurementQuotation;
use App\Domain\Procurement\Models\ProcurementRfq;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Models\MaintenanceVendor;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProcurementController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewOperations', $organization);

        return Inertia::render('procurement/Index', [
            'requests' => PurchaseRequest::where('organization_id', $organization->id)->with('lines')->latest()->get(),
            'rfqs' => ProcurementRfq::where('organization_id', $organization->id)->latest()->get(),
            'quotations' => ProcurementQuotation::where('organization_id', $organization->id)->latest()->get(),
            'orders' => PurchaseOrder::where('organization_id', $organization->id)->latest()->get(),
            'vendors' => MaintenanceVendor::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'properties' => Property::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('manageOperations', $organization),
            'canManageFinance' => $request->user()->can('manageFinance', $organization),
            'userId' => $request->user()->id,
        ]);
    }

    public function storeRequest(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageOperations', $organization);
        $input = $request->validate(['purpose' => ['required', 'string', 'max:255'], 'property_id' => ['nullable', 'integer'], 'lines' => ['required', 'array', 'min:1', 'max:50'], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:9999999999'], 'lines.*.unit' => ['required', 'string', 'max:32']]);
        if ($input['property_id'] ?? null) {
            Property::where('organization_id', $organization->id)->findOrFail((int) $input['property_id']);
        }
        DB::transaction(function () use ($input, $organization, $request, $audit): void {
            $purchaseRequest = PurchaseRequest::create(['organization_id' => $organization->id, 'property_id' => $input['property_id'] ?? null, 'requested_by' => $request->user()->id, 'reference' => 'PR-'.Str::upper(Str::random(10)), 'purpose' => $input['purpose']]);
            $purchaseRequest->lines()->createMany($input['lines']);
            $audit->handle($organization, $request->user(), 'procurement.request.created', $purchaseRequest);
        });

        return back();
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $purchaseRequest->organization_id === $organization->id, 404);
        $this->authorize('manageOperations', $organization);
        abort_unless($purchaseRequest->status === 'draft', 422);
        $purchaseRequest->update(['status' => 'submitted']);
        $audit->handle($organization, $request->user(), 'procurement.request.submitted', $purchaseRequest);

        return back();
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest, ProcurementWorkflow $workflow): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $purchaseRequest->organization_id === $organization->id, 404);
        $this->authorize('manageOperations', $organization);
        $workflow->approve($purchaseRequest, $organization, $request->user());

        return back();
    }

    public function storeRfq(Request $request, PurchaseRequest $purchaseRequest, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $purchaseRequest->organization_id === $organization->id, 404);
        $this->authorize('manageOperations', $organization);
        $input = $request->validate(['vendor_id' => ['required', 'integer'], 'due_on' => ['nullable', 'date']]);
        MaintenanceVendor::where('organization_id', $organization->id)->findOrFail((int) $input['vendor_id']);
        abort_unless($purchaseRequest->status === 'approved', 422);
        abort_if(ProcurementRfq::where('purchase_request_id', $purchaseRequest->id)->where('vendor_id', $input['vendor_id'])->exists(), 422);
        $rfq = ProcurementRfq::create(['organization_id' => $organization->id, 'purchase_request_id' => $purchaseRequest->id, 'vendor_id' => $input['vendor_id'], 'due_on' => $input['due_on'] ?? null, 'reference' => 'RFQ-'.Str::upper(Str::random(10))]);
        $audit->handle($organization, $request->user(), 'procurement.rfq.sent', $rfq);

        return back();
    }

    public function storeQuotation(Request $request, ProcurementRfq $rfq, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $rfq->organization_id === $organization->id, 404);
        $this->authorize('manageOperations', $organization);
        $input = $request->validate(['vendor_reference' => ['nullable', 'string', 'max:255'], 'total' => ['required', 'numeric', 'min:0.01', 'max:99999999999999.99']]);
        abort_unless($rfq->status === 'sent' && PurchaseRequest::where('organization_id', $organization->id)->whereKey($rfq->purchase_request_id)->where('status', 'approved')->exists(), 422);
        abort_if(ProcurementQuotation::where('rfq_id', $rfq->id)->exists(), 422);
        $quote = ProcurementQuotation::create(['organization_id' => $organization->id, 'rfq_id' => $rfq->id, ...$input]);
        $rfq->update(['status' => 'quoted']);
        $audit->handle($organization, $request->user(), 'procurement.quotation.received', $quote);

        return back();
    }

    public function issueOrder(Request $request, ProcurementQuotation $quotation, ProcurementWorkflow $workflow): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $quotation->organization_id === $organization->id, 404);
        $this->authorize('manageOperations', $organization);
        $workflow->issueOrder($quotation, $organization, $request->user());

        return back();
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, ProcurementWorkflow $workflow): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $purchaseOrder->organization_id === $organization->id, 404);
        $this->authorize('manageOperations', $organization);
        $note = $request->validate(['receipt_note' => ['required', 'string', 'max:5000']])['receipt_note'];
        $workflow->receive($purchaseOrder, $organization, $request->user(), $note);

        return back();
    }

    public function convertToBill(Request $request, PurchaseOrder $purchaseOrder, ProcurementWorkflow $workflow): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $purchaseOrder->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['bill_date' => ['required', 'date'], 'due_on' => ['nullable', 'date', 'after_or_equal:bill_date']]);
        $workflow->convertToBill($purchaseOrder, $organization, $request->user(), $input['bill_date'], $input['due_on'] ?? null);

        return back();
    }
}
