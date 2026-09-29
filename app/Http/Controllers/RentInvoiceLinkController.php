<?php

namespace App\Http\Controllers;

use App\Domain\Leasing\Actions\ManageRentInvoiceLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RentInvoiceLinkController extends Controller
{
    public function store(Request $request, int $lease, ManageRentInvoiceLink $links): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $request->user()->belongsToOrganization($org), 404);
        $input = $request->validate(['invoice_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $links->link($org, $request->user(), $lease, (int) $input['invoice_id'], $input['reason']);

        return back();
    }

    public function destroy(Request $request, int $lease, int $invoice, ManageRentInvoiceLink $links): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $request->user()->belongsToOrganization($org), 404);
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $links->unlink($org, $request->user(), $lease, $invoice, $input['reason']);

        return back();
    }
}
