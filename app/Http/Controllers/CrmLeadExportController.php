<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Queries\ExportLeads;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmLeadExportController extends Controller
{
    public function index(Request $request, ExportLeads $exports): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return Inertia::render('crm/LeadExport', ['columns' => $exports->columns($org, $request->user())]);
    }

    public function download(Request $request, ExportLeads $exports): StreamedResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $data = $request->validate(['columns' => ['required', 'array', 'min:1', 'max:100'], 'columns.*' => ['required', 'string', 'max:100']]);

        return $exports->download($org, $request->user(), $data['columns']);
    }
}
