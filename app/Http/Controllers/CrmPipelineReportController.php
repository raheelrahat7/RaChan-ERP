<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Queries\PipelineReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmPipelineReportController extends Controller
{
    public function __invoke(Request $request, PipelineReport $report): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);

        return Inertia::render('crm/PipelineReport', $report->handle($org, $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'pipeline_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
        ]), $request->user()));
    }
}
