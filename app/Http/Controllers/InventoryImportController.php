<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Actions\ImportInventory;
use App\Domain\Inventory\Models\InventoryImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InventoryImportController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        Gate::authorize('manageInventory', $org);

        return Inertia::render('inventory/Imports', ['batches' => InventoryImportBatch::where('organization_id', $org->id)->where('user_id', $request->user()->id)->latest()->paginate(10)]);
    }

    public function preview(Request $request, ImportInventory $imports): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:properties,units'], 'file' => ['required', 'file']]);
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $imports->preview($org, $request->user(), $data['kind'], $request->file('file'));

        return back();
    }

    public function commit(Request $request, int $batch, ImportInventory $imports): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $imports->commit($org, $request->user(), $batch);

        return back();
    }
}
