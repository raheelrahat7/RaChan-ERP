<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Crm\Services\StageEntryRules;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PipelineOverview
{
    /** @return array<string, mixed> */
    public function configuration(Organization $org): array
    {
        return ['pipelines' => Pipeline::where('organization_id', $org->id)->with(['stages', 'reasons'])->orderByDesc('is_default')->orderBy('id')->get()];
    }

    /**
     * @param  array<string, int|null>  $filters
     * @return array<string, mixed>
     */
    public function leads(Organization $org, array $filters, ?User $actor = null): array
    {
        $pipelines = $this->configuration($org)['pipelines'];
        $pipelineId = (int) ($filters['pipeline_id'] ?? $pipelines->firstWhere('is_default', true)?->id);
        $pipeline = $pipelines->firstWhere('id', $pipelineId);
        if (! $pipeline) {
            throw ValidationException::withMessages(['pipeline_id' => 'Select a pipeline in your organization.']);
        }
        $stageId = ! empty($filters['stage_id']) ? (int) $filters['stage_id'] : null;
        $assigneeId = ! empty($filters['assignee_id']) ? (int) $filters['assignee_id'] : null;
        if ($stageId && ! $pipeline->stages->contains('id', $stageId)) {
            throw ValidationException::withMessages(['stage_id' => 'Select a stage in this pipeline.']);
        }
        $visibility = app(LeadVisibility::class);
        $assigneeId = $actor ? $visibility->assigneeFilter($org, $actor, $assigneeId) : $assigneeId;
        if (! $actor && $assigneeId && ! $org->users()->where('users.id', $assigneeId)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Select an organization member.']);
        }
        $query = CrmLead::where('organization_id', $org->id)->where('pipeline_id', $pipelineId)->when($assigneeId, fn ($q) => $q->where('assigned_to', $assigneeId));
        if ($actor) {
            $visibility->scope($query, $org, $actor);
        }
        $counts = (clone $query)->selectRaw('current_stage_id, count(*) as total')->groupBy('current_stage_id')->pluck('total', 'current_stage_id');
        $role = $actor?->organizations()->whereKey($org->id)->value('organization_user.role');
        $rules = app(StageEntryRules::class);
        $won = $pipeline->stages->first(fn ($stage) => $stage->active && $stage->type === 'won');
        $leadRows = $query->when($stageId, fn ($q) => $q->where('current_stage_id', $stageId))->with(['assignee:id,name', 'listing:id,reference', 'stage', 'history.actor:id,name'])->latest()->get();
        $leads = [];
        foreach ($leadRows as $lead) {
            $leads[] = [
                ...$lead->only('id', 'first_name', 'last_name', 'email', 'phone', 'company', 'source', 'status', 'notes', 'project_name', 'campaign_name', 'meta_form_id', 'meta_form_name', 'pipeline_id', 'current_stage_id', 'lost_reason_id'),
                'listing' => $lead->listing?->only('id', 'reference'),
                'transitionOptions' => $pipeline->stages->map(function ($stage) use ($lead, $role, $pipeline, $rules) {
                    $reasons = [];
                    if (! $pipeline->active) {
                        $reasons[] = 'The pipeline is inactive.';
                    }
                    if (! $stage->active) {
                        $reasons[] = 'The destination stage is inactive.';
                    }
                    if ($lead->converted_at) {
                        $reasons[] = 'Converted leads cannot change stage.';
                    }
                    if ($lead->current_stage_id === $stage->id) {
                        $reasons[] = 'The lead is already in this stage.';
                    }
                    if ($lead->stage->type === 'lost' && in_array($stage->type, ['won', 'lost'])) {
                        $reasons[] = 'Reopen a lost lead to a nonterminal stage first.';
                    }

                    return ['stage_id' => $stage->id, 'reasons' => [...$reasons, ...$rules->reasons($lead, $stage, $role)]];
                }),
                'conversionBlockedReason' => $lead->converted_at ? null : (! $pipeline->active ? 'The pipeline is inactive.' : ($lead->stage->type === 'lost' ? 'Reopen this lost lead before converting it.' : (! $won ? 'Configure an active Won stage before converting leads.' : implode(' ', $rules->reasons($lead, $lead->stage->type === 'won' && $lead->stage->active ? $lead->stage : $won, $role, ! ($lead->stage->type === 'won' && $lead->stage->active)))))),
                'converted' => $lead->converted_at !== null,
                'assigned_to' => $lead->assigned_to,
                'assignee' => $lead->assignee?->only('id', 'name'),
                'stage' => $lead->stage?->only('id', 'name', 'type', 'color', 'active'),
                'history' => $lead->history->map(fn ($history) => [...$history->only('id', 'changed_at', 'snapshot', 'notes'), 'actor' => $history->actor?->name]),
            ];
        }

        return ['pipelines' => $pipelines, 'leads' => $leads, 'filters' => ['pipeline_id' => $pipelineId, 'stage_id' => $stageId, 'assignee_id' => $assigneeId], 'stageCounts' => $pipeline->stages->map(fn ($stage) => [...$stage->only('id', 'name', 'type', 'color', 'active'), 'count' => (int) ($counts[$stage->id] ?? 0)])];
    }
}
