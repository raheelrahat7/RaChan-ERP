<?php

namespace App\Http\Controllers;

use App\Domain\RealEstate\Actions\ManageOffPlanProjectDetails;
use App\Domain\RealEstate\Queries\OffPlanOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OffPlanDataController extends Controller
{
    public function index(Request $request, OffPlanOverview $overview, ManageOffPlanProjectDetails $details): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'workflow_status' => ['nullable', 'string', 'max:40'], 'page' => ['nullable', 'integer', 'min:1']]);
        $canManage = $request->user()->can('manageCrm', $org);
        $projects = $overview->query($org, $filters)->paginate(30)->withQueryString();
        $projects->through(fn ($row) => $overview->serialize($row, $canManage));

        return response()->json(['projects' => $projects, 'workflowStatuses' => $details->statuses($org), 'statusCounts' => $overview->statusCounts($org, $filters), 'filters' => $filters, 'permissions' => ['read' => true, 'create' => $canManage, 'configure' => $canManage]]);
    }

    public function show(Request $request, int $project, OffPlanOverview $overview, ManageOffPlanProjectDetails $details): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $row = $overview->query($org, [])->where('project.id', $project)->first();
        abort_unless($row !== null, 404);

        return response()->json(['project' => $overview->serialize($row, $request->user()->can('manageCrm', $org)), 'workflowStatuses' => $details->statuses($org)]);
    }

    public function update(Request $request, int $project, ManageOffPlanProjectDetails $details, OffPlanOverview $overview): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $details->update($org, $request->user(), $project, $request->all());
        $row = $overview->query($org, [])->where('project.id', $project)->first();
        abort_unless($row !== null, 404);

        return response()->json(['project' => $overview->serialize($row, true)]);
    }

    public function saveStatus(Request $request, ManageOffPlanProjectDetails $details, ?int $status = null): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $saved = $details->saveStatus($org, $request->user(), $request->all(), $status);

        return response()->json(['workflowStatus' => $saved], $status ? 200 : 201);
    }
}
