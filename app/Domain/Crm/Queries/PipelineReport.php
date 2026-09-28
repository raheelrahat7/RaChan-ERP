<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PipelineReport
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(Organization $org, array $input, ?User $actor = null): array
    {
        $start = Carbon::parse($input['from_date'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $end = Carbon::parse($input['to_date'] ?? now()->toDateString())->endOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['to_date' => 'The end date must be on or after the start date.']);
        }
        $cutoff = $end->copy()->min(now());
        $pipelineId = ! empty($input['pipeline_id']) ? (int) $input['pipeline_id'] : null;
        $assigneeId = ! empty($input['assignee_id']) ? (int) $input['assignee_id'] : null;
        $pipelines = Pipeline::where('organization_id', $org->id)->orderBy('name')->get();
        if ($pipelineId && ! $pipelines->contains('id', $pipelineId)) {
            throw ValidationException::withMessages(['pipeline_id' => 'Select an organization pipeline.']);
        }
        $visibility = app(LeadVisibility::class);
        $assigneeId = $actor ? $visibility->assigneeFilter($org, $actor, $assigneeId) : $assigneeId;
        if (! $actor && $assigneeId && ! $org->users()->where('users.id', $assigneeId)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Select an organization member.']);
        }
        $leadQuery = CrmLead::where('organization_id', $org->id)->when($assigneeId, fn ($q) => $q->where('assigned_to', $assigneeId))->where(fn ($q) => $q->where('created_at', '<=', $cutoff)->orWhereNull('created_at'));
        if ($actor) {
            $visibility->scope($leadQuery, $org, $actor);
        }
        $leads = $leadQuery->with('assignee:id,name')->get();
        $histories = LeadStageHistory::where('organization_id', $org->id)->whereIn('lead_id', $leads->pluck('id'))->where('changed_at', '<=', $cutoff)->orderBy('changed_at')->orderBy('id')->get()->groupBy('lead_id');
        $stageMovements = app(PipelineFunnel::class)->handle($org, $histories, $start, $cutoff, $pipelineId);
        if ($pipelineId) {
            $leads = $leads->filter(function (CrmLead $lead) use ($histories, $pipelineId): bool {
                $last = $histories->get($lead->id)?->last();

                return $last ? $last->pipeline_id === $pipelineId : $lead->pipeline_id === $pipelineId;
            });
        }
        $summary = ['total' => $leads->count(), 'open' => 0, 'won' => 0, 'lost' => 0, 'unknown' => 0, 'created' => 0, 'converted' => 0, 'cohort_converted' => 0];
        $agents = [];
        $reasons = [];
        $durations = [];
        foreach ($leads as $lead) {
            $history = $histories->get($lead->id, collect());
            $last = $history->last();
            $type = $last?->snapshot['to_type'] ?? null;
            $outcome = match ($type) {
                'won' => 'won', 'lost' => 'lost', 'normal', 'on_hold' => 'open', default => 'unknown'
            };
            $summary[$outcome]++;
            $created = $lead->created_at && $lead->created_at->betweenIncluded($start, $cutoff);
            $converted = $lead->converted_at && $lead->converted_at->betweenIncluded($start, $cutoff);
            $summary['created'] += (int) $created;
            $summary['converted'] += (int) $converted;
            $summary['cohort_converted'] += (int) ($created && $converted);
            $agentKey = $lead->assigned_to ?? 'unassigned';
            $agents[$agentKey] ??= ['key' => (string) $agentKey, 'name' => $lead->assignee->name ?? 'Unassigned', 'total' => 0, 'open' => 0, 'won' => 0, 'lost' => 0, 'unknown' => 0, 'converted' => 0, 'created' => 0, 'cohort_converted' => 0];
            $agents[$agentKey]['total']++;
            $agents[$agentKey][$outcome]++;
            $agents[$agentKey]['converted'] += (int) $converted;
            $agents[$agentKey]['created'] += (int) $created;
            $agents[$agentKey]['cohort_converted'] += (int) ($created && $converted);
            if ($outcome === 'lost') {
                $reasonName = $last->snapshot['lost_reason'] ?? 'Unrecorded reason';
                $key = $last->pipeline_id.'|'.$last->lost_reason_id.'|'.$reasonName;
                $reasons[$key] ??= ['key' => $key, 'pipeline' => $last->snapshot['pipeline'] ?? 'Unrecorded pipeline', 'reason' => $reasonName, 'count' => 0];
                $reasons[$key]['count']++;
            }
            $intervals = $history->values();
            foreach ($intervals as $index => $entry) {
                if (($pipelineId && $entry->pipeline_id !== $pipelineId) || ! in_array($entry->snapshot['to_type'] ?? null, ['normal', 'on_hold'])) {
                    continue;
                }
                $intervalStart = $entry->changed_at->copy()->max($start);
                $next = $intervals->get($index + 1);
                $intervalEnd = ($next->changed_at ?? $cutoff)->copy()->min($cutoff);
                if ($intervalEnd->lte($intervalStart)) {
                    continue;
                }
                $key = ($entry->snapshot['pipeline'] ?? '').'|'.$entry->pipeline_id.'|'.$entry->to_stage_id.'|'.($entry->snapshot['to'] ?? '').'|'.($entry->snapshot['to_type'] ?? '');
                $durations[$key] ??= ['key' => $key, 'pipeline' => $entry->snapshot['pipeline'] ?? 'Unrecorded pipeline', 'stage' => $entry->snapshot['to'] ?? 'Unrecorded stage', 'type' => $entry->snapshot['to_type'], 'seconds' => 0, 'intervals' => 0, 'lead_ids' => []];
                $durations[$key]['seconds'] += $intervalStart->diffInSeconds($intervalEnd);
                $durations[$key]['intervals']++;
                $durations[$key]['lead_ids'][$lead->id] = true;
            }
        }
        $rate = fn ($row) => $row['won'] + $row['lost'] ? round(100 * $row['won'] / ($row['won'] + $row['lost']), 1) : null;
        $summary['win_rate'] = $rate($summary);
        $conversionRate = fn ($row) => $row['created'] ? round(100 * $row['cohort_converted'] / $row['created'], 1) : null;
        $summary['conversion_rate'] = $conversionRate($summary);

        return [
            'filters' => ['from_date' => $start->toDateString(), 'to_date' => $end->toDateString(), 'pipeline_id' => $pipelineId, 'assignee_id' => $assigneeId],
            'cutoff' => $cutoff->toIso8601String(),
            'assigneeScoped' => $actor && $visibility->restricted($org, $actor) && count($visibility->assigneeIds($org, $actor)) === 1,
            'limitedVisibility' => $actor && $visibility->restricted($org, $actor),
            'pipelines' => $pipelines->map->only(['id', 'name', 'active']),
            'members' => $actor && $visibility->restricted($org, $actor) ? $org->users()->whereIn('users.id', $visibility->assigneeIds($org, $actor))->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name']) : $org->users()->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name']),
            'summary' => $summary,
            'assignees' => collect($agents)->map(fn ($row) => [...$row, 'win_rate' => $rate($row), 'conversion_rate' => $conversionRate($row)])->sortBy('name')->values(),
            'lostReasons' => collect($reasons)->sortByDesc('count')->values(),
            'stageTimes' => collect($durations)->map(fn ($row) => ['key' => $row['key'], 'pipeline' => $row['pipeline'], 'stage' => $row['stage'], 'type' => $row['type'], 'leads' => count($row['lead_ids']), 'intervals' => $row['intervals'], 'total_hours' => round($row['seconds'] / 3600, 2), 'average_hours' => round($row['seconds'] / $row['intervals'] / 3600, 2)])->sortBy('stage')->values(),
            'stageMovements' => $stageMovements,
        ];
    }
}
