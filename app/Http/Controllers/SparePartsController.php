<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Actions\ManageSpareParts;
use App\Domain\Operations\Actions\ManageStockTransfers;
use App\Domain\Operations\Actions\RecordStockReceiptCost;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Queries\SparePartsOverview;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SparePartsController extends Controller
{
    public function transfer(Request $request, ManageStockTransfers $transfers): RedirectResponse
    {
        $input = $request->validate(['spare_part_id' => ['required', 'integer'], 'source_store_id' => ['required', 'integer'], 'destination_store_id' => ['required', 'integer'], 'quantity' => ['required', 'string'], 'reference' => ['required', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid']]);
        $transfers->transfer($this->organization($request), $request->user(), $input);

        return back();
    }

    public function reverseTransfer(Request $request, StockMovement $movement, ManageStockTransfers $transfers): RedirectResponse
    {
        $input = $request->validate(['reference' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid']]);
        $transfers->reverse($this->organization($request), $request->user(), $movement, $input);

        return back();
    }

    public function receiptCost(Request $request, StockMovement $movement, RecordStockReceiptCost $costs): RedirectResponse
    {
        $input = $request->validate(['amount' => ['required', 'string'], 'currency' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000']]);
        $costs->handle($this->organization($request), $request->user(), $movement, $input);

        return back();
    }

    public function index(Request $request, SparePartsOverview $overview): Response
    {
        $filters = $request->validate(['spare_part_id' => ['nullable', 'integer'], 'stock_store_id' => ['nullable', 'integer'], 'maintenance_request_id' => ['nullable', 'integer']]);

        return Inertia::render('operations/SpareParts', $overview->for($this->organization($request), $request->user(), $filters));
    }

    public function part(Request $request, ManageSpareParts $stock): RedirectResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'unit' => ['required', 'string', 'max:30']]);
        $stock->catalogue($this->organization($request), $request->user(), $input);

        return back();
    }

    public function store(Request $request, ManageSpareParts $stock): RedirectResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255']]);
        $stock->catalogue($this->organization($request), $request->user(), $input, true);

        return back();
    }

    public function movement(Request $request, ManageSpareParts $stock): RedirectResponse
    {
        $input = $request->validate(['type' => ['required', 'in:receipt,issue,return,reversal'], 'operation_key' => ['required', 'uuid'], 'spare_part_id' => ['nullable', 'integer'], 'stock_store_id' => ['nullable', 'integer'], 'maintenance_request_id' => ['nullable', 'integer'], 'related_movement_id' => ['nullable', 'integer'], 'quantity' => ['nullable', 'string', 'max:20'], 'reference' => ['required', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $stock->movement($this->organization($request), $request->user(), $input);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }
}
