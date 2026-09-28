<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Actions\ManageVendorCashRefund;
use App\Domain\Finance\Models\VendorCashRefund;
use App\Domain\Finance\Queries\VendorCashRefundOverview;
use App\Models\Organization;
use App\Models\VendorBill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorCashRefundController extends Controller
{
    public function index(Request $request, VendorCashRefundOverview $overview): Response
    {
        return Inertia::render('finance/VendorCashRefunds', $overview->for($this->organization($request), $request->user()));
    }

    public function store(Request $request, VendorBill $bill, ManageVendorCashRefund $refunds): RedirectResponse
    {
        $refunds->request($this->organization($request), $request->user(), $bill, $request->only(['credit_note_id', 'amount', 'posted_on', 'reason', 'operation_key']));

        return back();
    }

    public function approve(Request $request, VendorCashRefund $refund, ManageVendorCashRefund $refunds): RedirectResponse
    {
        $refunds->approve($this->organization($request), $request->user(), $refund);

        return back();
    }

    public function reject(Request $request, VendorCashRefund $refund, ManageVendorCashRefund $refunds): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $refunds->reject($this->organization($request), $request->user(), $refund, $input['reason']);

        return back();
    }

    public function reverse(Request $request, VendorCashRefund $refund, ManageVendorCashRefund $refunds): RedirectResponse
    {
        $input = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);
        $refunds->reverse($this->organization($request), $request->user(), $refund, $input['posted_on'], $input['reason']);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }
}
