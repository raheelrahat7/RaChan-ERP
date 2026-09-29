<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ImportLeads;
use App\Domain\Crm\Models\LeadImportBatch;
use App\Domain\Crm\Models\Pipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CrmLeadImportController extends Controller
{
    public function index(Request $request, ImportLeads $imports): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        Gate::authorize('manageCrm', $org);
        $batch = LeadImportBatch::where('organization_id', $org->id)->where('user_id', $request->user()->id)->latest()->first();

        return Inertia::render('crm/LeadImport', [
            'batch' => $batch ? ['id' => $batch->id, 'headers' => $batch->headers, 'preview' => array_slice($batch->rows, 0, 10), 'row_count' => count($batch->rows), 'summary' => $batch->summary, 'errors' => $batch->errors, 'committed_at' => $batch->committed_at] : null,
            'targets' => $imports->targets($org, $request->user()),
            'pipelines' => Pipeline::where('organization_id', $org->id)->where('active', true)->get(['id', 'name']),
        ]);
    }

    public function preview(Request $request, ImportLeads $imports): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $data = $request->validate(['file' => ['required', 'file']]);
        $imports->preview($org, $request->user(), $data['file']);

        return back();
    }

    public function commit(Request $request, LeadImportBatch $batch, ImportLeads $imports): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $imports->commit($org, $request->user(), $batch, $request->all());

        return back();
    }
}
