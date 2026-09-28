<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Actions\ManageCustomerRefund;
use App\Domain\Finance\Models\CustomerRefund;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerRefundController extends Controller
{
    public function store(Request $request, Invoice $invoice, ManageCustomerRefund $refunds): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($invoice->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate([
            'credit_note_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'posted_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $refunds->request($organization, $request->user(), $invoice, (int) $input['credit_note_id'], $input['amount'], $input['posted_on'], $input['reason']);

        return back();
    }

    public function approve(Request $request, CustomerRefund $refund, ManageCustomerRefund $refunds): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($refund->organization_id === $organization->id, 404);
        $refunds->approve($organization, $request->user(), $refund);

        return back();
    }

    public function reject(Request $request, CustomerRefund $refund, ManageCustomerRefund $refunds): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($refund->organization_id === $organization->id, 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $refunds->reject($organization, $request->user(), $refund, $reason);

        return back();
    }

    public function reverse(Request $request, CustomerRefund $refund, ManageCustomerRefund $refunds): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($refund->organization_id === $organization->id, 404);
        $input = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);
        $refunds->reverse($organization, $request->user(), $refund, $input['posted_on'], $input['reason']);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }
}
