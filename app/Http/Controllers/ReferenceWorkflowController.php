<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Actions\CreateInvoiceFromEstimate;
use App\Domain\Workflows\Actions\ManageReferenceWorkflows;
use App\Domain\Workflows\Services\WorkflowOverview;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceWorkflowController extends Controller
{
    public function index(Request $request, WorkflowOverview $overview): JsonResponse
    {
        $data = $request->validate(['pipeline_id' => ['nullable', 'integer'], 'stage_id' => ['nullable', 'integer'], 'kind' => ['nullable', 'in:estimate,invoice,document,recruitment'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($overview->index($this->organization($request), $request->user(), $data));
    }

    public function show(Request $request, int $record, WorkflowOverview $overview): JsonResponse
    {
        return response()->json($overview->show($this->organization($request), $request->user(), $record));
    }

    public function save(Request $request, ManageReferenceWorkflows $workflows, ?int $record = null): JsonResponse
    {
        return response()->json(['record' => $workflows->save($this->organization($request), $request->user(), $request->all(), $record)]);
    }

    public function move(Request $request, int $record, ManageReferenceWorkflows $workflows): JsonResponse
    {
        return response()->json(['record' => $workflows->move($this->organization($request), $request->user(), $record, $request->all())]);
    }

    public function pipeline(Request $request, ManageReferenceWorkflows $workflows, ?int $pipeline = null): JsonResponse
    {
        return response()->json(['pipeline' => $workflows->pipeline($this->organization($request), $request->user(), $request->all(), $pipeline)]);
    }

    public function stage(Request $request, int $pipeline, ManageReferenceWorkflows $workflows, ?int $stage = null): JsonResponse
    {
        return response()->json(['stage' => $workflows->stage($this->organization($request), $request->user(), $pipeline, $request->all(), $stage)]);
    }

    public function invoice(Request $request, int $record, CreateInvoiceFromEstimate $estimates): JsonResponse
    {
        return response()->json(['invoice' => $estimates->handle($this->organization($request), $request->user(), $record, $request->all())]);
    }

    private function organization(Request $request): Organization
    {
        return $request->user()->currentOrganization ?? abort(404);
    }
}
