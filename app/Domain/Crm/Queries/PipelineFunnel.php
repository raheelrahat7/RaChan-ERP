<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\PipelineStage;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PipelineFunnel
{
    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Collection<int, LeadStageHistory>>  $histories
     * @return list<array<string, mixed>>
     */
    public function handle(Organization $org, Collection $histories, Carbon $start, Carbon $cutoff, ?int $pipelineId): array
    {
        $moves = $histories->flatten(1)->filter(fn ($entry) => $entry->from_stage_id !== null && $entry->changed_at->betweenIncluded($start, $cutoff));
        $sourcePipelines = PipelineStage::query()->join('crm_pipelines as pipeline', 'pipeline.id', '=', 'crm_pipeline_stages.pipeline_id')
            ->where('pipeline.organization_id', $org->id)->whereIn('crm_pipeline_stages.id', $moves->pluck('from_stage_id')->unique())
            ->pluck('crm_pipeline_stages.pipeline_id', 'crm_pipeline_stages.id');
        $rows = [];
        $exits = [];
        foreach ($moves as $entry) {
            $snapshot = $entry->snapshot ?? [];
            $sourcePipeline = (int) ($sourcePipelines->get($entry->from_stage_id) ?? 0);
            $sourceKey = $sourcePipeline.'|'.$entry->from_stage_id.'|'.($snapshot['from_pipeline'] ?? '').'|'.($snapshot['from'] ?? '');
            $key = $sourceKey.'|'.$entry->pipeline_id.'|'.$entry->to_stage_id.'|'.($snapshot['pipeline'] ?? '').'|'.($snapshot['to'] ?? '');
            $exits[$sourceKey] = ($exits[$sourceKey] ?? 0) + 1;
            $rows[$key] ??= [
                'key' => $key,
                'from_pipeline' => $snapshot['from_pipeline'] ?? 'Unrecorded pipeline',
                'from_stage' => $snapshot['from'] ?? 'Unrecorded stage',
                'to_pipeline' => $snapshot['pipeline'] ?? 'Unrecorded pipeline',
                'to_stage' => $snapshot['to'] ?? 'Unrecorded stage',
                'from_pipeline_id' => $sourcePipeline,
                'to_pipeline_id' => $entry->pipeline_id,
                'source_key' => $sourceKey,
                'moves' => 0,
                'lead_ids' => [],
            ];
            $rows[$key]['moves']++;
            $rows[$key]['lead_ids'][$entry->lead_id] = true;
        }

        return array_values(collect($rows)
            ->filter(fn ($row) => ! $pipelineId || $row['from_pipeline_id'] === $pipelineId || $row['to_pipeline_id'] === $pipelineId)
            ->map(fn ($row) => [
                'key' => $row['key'],
                'from_pipeline' => $row['from_pipeline'],
                'from_stage' => $row['from_stage'],
                'to_pipeline' => $row['to_pipeline'],
                'to_stage' => $row['to_stage'],
                'moves' => $row['moves'],
                'leads' => count($row['lead_ids']),
                'share_of_source_exits' => round(100 * $row['moves'] / $exits[$row['source_key']], 1),
            ])
            ->sortBy([['moves', 'desc'], ['from_pipeline', 'asc'], ['from_stage', 'asc'], ['to_stage', 'asc']])
            ->values()
            ->all());
    }
}
