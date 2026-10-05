<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\CustomFieldValue;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Crm\Services\StageEntryRules;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PipelineOverview
{
    /** @return array<string, mixed> */
    public function configuration(Organization $org): array
    {
        return ['pipelines' => Pipeline::where('organization_id', $org->id)->with(['stages', 'reasons'])->orderByDesc('is_default')->orderBy('id')->get()];
    }

    /**
     * @param  array<string, mixed>  $filters
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
            app(LeadFilters::class)->apply($query, $org, $actor, $filters['filters'] ?? [], $filters['q'] ?? null);
        }
        $counts = (clone $query)->selectRaw('current_stage_id, count(*) as total')->groupBy('current_stage_id')->pluck('total', 'current_stage_id');
        $fields = $actor ? app(ManageCustomFields::class)->visible($org, $actor) : [];
        // Stage totals sum the first currency custom field this user can see; leads have no built-in amount.
        $amountField = collect($fields)->first(fn ($field) => $field->type === 'currency');
        $amounts = $amountField
            ? CustomFieldValue::from('crm_custom_field_values as amount_values')
                ->join('crm_leads as amount_leads', 'amount_leads.id', '=', 'amount_values.lead_id')
                ->where('amount_values.field_id', $amountField->id)
                ->whereIn('amount_values.lead_id', (clone $query)->select('id'))
                ->selectRaw('amount_leads.current_stage_id as stage_id, sum(amount_values.number_value) as total')
                ->groupBy('amount_leads.current_stage_id')->pluck('total', 'stage_id')
            : collect();
        $role = $actor?->organizations()->whereKey($org->id)->value('organization_user.role');
        $rules = app(StageEntryRules::class);
        $won = $pipeline->stages->first(fn ($stage) => $stage->active && $stage->type === 'won');
        $query->when($stageId, fn ($q) => $q->where('current_stage_id', $stageId));
        $total = (clone $query)->count();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $leadRows = $query->with(['assignee:id,name', 'listing:id,reference', 'stage', 'history.actor:id,name'])->latest()->offset(($page - 1) * 50)->limit(50)->get();
        $fieldIds = array_map(fn ($field) => $field->id, $fields);
        $fieldKeys = collect($fields)->pluck('key', 'id');
        $customValues = CustomFieldValue::where('organization_id', $org->id)
            ->whereIn('lead_id', $leadRows->pluck('id'))->whereIn('field_id', $fieldIds)->get()->groupBy('lead_id');
        $leads = [];
        foreach ($leadRows as $lead) {
            $leads[] = [
                ...$lead->only('id', 'first_name', 'last_name', 'email', 'phone', 'company', 'city', 'source', 'status', 'notes', 'project_name', 'campaign_name', 'meta_form_id', 'meta_form_name', 'pipeline_id', 'current_stage_id', 'lost_reason_id'),
                'listing' => $lead->listing?->only('id', 'reference'),
                'transitionOptions' => $this->transitionOptions($pipeline, $lead, $role),
                'conversionBlockedReason' => $lead->converted_at ? null : (! $pipeline->active ? 'The pipeline is inactive.' : ($lead->stage->type === 'lost' ? 'Reopen this lost lead before converting it.' : (! $won ? 'Configure an active Won stage before converting leads.' : implode(' ', $rules->reasons($lead, $lead->stage->type === 'won' && $lead->stage->active ? $lead->stage : $won, $role, ! ($lead->stage->type === 'won' && $lead->stage->active)))))),
                'converted' => $lead->converted_at !== null,
                'assigned_to' => $lead->assigned_to,
                'assignee' => $lead->assignee?->only('id', 'name'),
                'custom_fields' => collect($customValues->get($lead->id, []))->mapWithKeys(fn ($value) => [$fieldKeys[$value->field_id] => $value->value])->all(),
                'stage' => $lead->stage?->only('id', 'name', 'type', 'color', 'active'),
                'history' => $lead->history->map(fn ($history) => [...$history->only('id', 'changed_at', 'snapshot', 'notes'), 'actor' => $history->actor?->name]),
            ];
        }

        return ['pipelines' => $pipelines, 'leads' => $leads, 'filters' => ['pipeline_id' => $pipelineId, 'stage_id' => $stageId, 'assignee_id' => $assigneeId, 'q' => $filters['q'] ?? '', 'filters' => $filters['filters'] ?? [], 'page' => $page], 'filteredTotal' => $total, 'amountField' => $amountField ? ['key' => $amountField->key, 'name' => $amountField->name] : null, 'stageCounts' => $pipeline->stages->map(fn ($stage) => [...$stage->only('id', 'name', 'type', 'color', 'active'), 'count' => (int) ($counts[$stage->id] ?? 0), 'amount' => $amountField ? (float) ($amounts[$stage->id] ?? 0) : null])];
    }

    /** @param array<string, mixed> $filters
     * @return Builder<CrmLead>
     */
    public function filteredLeadQuery(Organization $org, User $actor, array $filters): Builder
    {
        $pipeline = Pipeline::where('organization_id', $org->id)->find(isset($filters['pipeline_id']) ? (int) $filters['pipeline_id'] : 0)
            ?? Pipeline::where('organization_id', $org->id)->where('is_default', true)->firstOrFail();
        if (! empty($filters['pipeline_id']) && $pipeline->id !== (int) $filters['pipeline_id']) {
            throw ValidationException::withMessages(['pipeline_id' => 'Select a pipeline in your organization.']);
        }
        $stageId = $filters['stage_id'] ?? null;
        if ($stageId && ! $pipeline->stages()->whereKey($stageId)->exists()) {
            throw ValidationException::withMessages(['stage_id' => 'Select a stage in this pipeline.']);
        }
        $visibility = app(LeadVisibility::class);
        $assigneeId = $visibility->assigneeFilter($org, $actor, isset($filters['assignee_id']) ? (int) $filters['assignee_id'] : null);
        $query = $visibility->scope(CrmLead::where('organization_id', $org->id)->where('pipeline_id', $pipeline->id), $org, $actor)
            ->when($stageId, fn ($query) => $query->where('current_stage_id', $stageId))
            ->when($assigneeId, fn ($query) => $query->where('assigned_to', $assigneeId));
        app(LeadFilters::class)->apply($query, $org, $actor, $filters['filters'] ?? [], $filters['q'] ?? null);

        return $query;
    }

    /** @return Collection<int, array{stage_id: mixed, reasons: list<string>}> */
    public function transitionOptions(Pipeline $pipeline, CrmLead $lead, ?string $role): Collection
    {
        $rules = app(StageEntryRules::class);

        return $pipeline->stages->map(function ($stage) use ($lead, $role, $pipeline, $rules) {
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
        });
    }
}
