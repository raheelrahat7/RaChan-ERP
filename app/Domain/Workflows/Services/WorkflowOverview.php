<?php

namespace App\Domain\Workflows\Services;

use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Workflows\Actions\ManageReferenceWorkflows;
use App\Domain\Workflows\Models\WorkflowPipeline;
use App\Domain\Workflows\Models\WorkflowRecord;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkflowOverview
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function index(Organization $org, User $actor, array $filters): array
    {
        $pipelines = WorkflowPipeline::where('organization_id', $org->id)->with('stages')->orderBy('position')->get()->filter(fn ($pipeline) => app(WorkflowAccess::class)->allows($org, $actor, $pipeline));
        $query = WorkflowRecord::where('organization_id', $org->id)->whereIn('pipeline_id', $pipelines->pluck('id'))
            ->when($filters['pipeline_id'] ?? null, fn ($q, $id) => $q->where('pipeline_id', $id))
            ->when($filters['stage_id'] ?? null, fn ($q, $id) => $q->where('stage_id', $id))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->whereIn('pipeline_id', $pipelines->where('kind', $kind)->pluck('id')))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'));
        $query->where(fn ($q) => $q->whereNull('document_id')->orWhereIn('document_id', app(DocumentAccess::class)->query($org, $actor)->select('id')));
        $counts = (clone $query)->selectRaw('pipeline_id, stage_id, COUNT(*) as total')->groupBy('pipeline_id', 'stage_id')->get();

        return ['pipelines' => $pipelines->values(), 'records' => $query->with(['pipeline', 'stage'])->latest('id')->paginate(50)->withQueryString(), 'stageCounts' => $counts, 'kinds' => ManageReferenceWorkflows::KINDS, 'detailFields' => ManageReferenceWorkflows::DETAIL_FIELDS, 'fieldTypes' => ['text', 'number', 'date', 'checkbox', 'select'], 'sourceInvoiceStatuses' => ['draft', 'posted', 'partial', 'paid', 'void'], 'canConfigure' => $actor->can('manageSettings', $org)];
    }

    /** @return array<string, mixed> */
    public function show(Organization $org, User $actor, int $id): array
    {
        $record = WorkflowRecord::where('organization_id', $org->id)->with(['pipeline', 'stage'])->findOrFail($id);
        app(WorkflowAccess::class)->record($org, $actor, $record);
        $history = DB::table('reference_workflow_histories')->where('organization_id', $org->id)->where('record_id', $id)->latest('id')->paginate(100);
        foreach ($history as $row) {
            $row->snapshot = json_decode($row->snapshot, true);
        }

        return ['record' => $record, 'history' => $history, 'canEdit' => app(WorkflowAccess::class)->allows($org, $actor, $record->pipeline, true), 'invoice' => $record->invoice_id && $actor->can('viewFinance', $org) ? Invoice::where('organization_id', $org->id)->findOrFail($record->invoice_id)->only('id', 'reference', 'status', 'total', 'currency') : null];
    }
}
