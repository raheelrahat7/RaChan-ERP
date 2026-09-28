<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Actions\ManageVendorCreditNote;
use App\Domain\Finance\Models\VendorCreditNote;
use App\Models\Organization;
use App\Models\VendorBill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VendorCreditNoteController extends Controller
{
    public function store(Request $request, VendorBill $bill, ManageVendorCreditNote $creditNotes): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($bill->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'posted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $creditNotes->request($organization, $request->user(), $bill, $input['amount'], $input['posted_on'], $input['reason']);

        return back();
    }

    public function approve(Request $request, VendorCreditNote $creditNote, ManageVendorCreditNote $creditNotes): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($creditNote->organization_id === $organization->id, 404);
        $creditNotes->approve($organization, $request->user(), $creditNote);

        return back();
    }

    public function reject(Request $request, VendorCreditNote $creditNote, ManageVendorCreditNote $creditNotes): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($creditNote->organization_id === $organization->id, 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $creditNotes->reject($organization, $request->user(), $creditNote, $reason);

        return back();
    }

    public function reverse(Request $request, VendorCreditNote $creditNote, ManageVendorCreditNote $creditNotes): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($creditNote->organization_id === $organization->id, 404);
        $input = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reason' => ['required', 'string', 'max:2000']]);
        $creditNotes->reverse($organization, $request->user(), $creditNote, $input['posted_on'], $input['reason']);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }
}
