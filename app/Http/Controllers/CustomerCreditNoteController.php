<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Actions\ManageCustomerCreditNote;
use App\Domain\Finance\Models\CustomerCreditNote;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerCreditNoteController extends Controller
{
    public function store(Request $request, Invoice $invoice, ManageCustomerCreditNote $notes): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $invoice->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        $input = $request->validate(['amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'], 'reason' => ['required', 'string', 'max:2000']]);
        $notes->create($org, $request->user(), $invoice, (string) $input['amount'], $input['reason']);

        return back();
    }

    public function post(Request $request, CustomerCreditNote $creditNote, ManageCustomerCreditNote $notes): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $creditNote->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        $input = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        try {
            $notes->post($org, $request->user(), $creditNote, $input['posted_on']);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }
            throw ValidationException::withMessages(['credit_note' => $exception->getMessage()]);
        }

        return back();
    }

    public function reverse(Request $request, CustomerCreditNote $creditNote, ManageCustomerCreditNote $notes): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $creditNote->organization_id === $org->id, 404);
        $this->authorize('manageFinance', $org);
        $input = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reason' => ['required', 'string', 'max:2000']]);
        try {
            $notes->reverse($org, $request->user(), $creditNote, $input['posted_on'], $input['reason']);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }
            throw ValidationException::withMessages(['credit_note' => $exception->getMessage()]);
        }

        return back();
    }
}
