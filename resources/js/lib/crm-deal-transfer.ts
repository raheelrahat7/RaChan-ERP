import type { DealPipeline, DealStage } from '@/types/crm-deals';

/** Other pipelines the person may create deals in; the server makes the final access check. */
export function transferTargets(
    pipelines: DealPipeline[],
    currentPipelineId: number,
): DealPipeline[] {
    return pipelines.filter(
        (pipeline) =>
            pipeline.id !== currentPipelineId &&
            pipeline.active !== false &&
            (pipeline.permissions?.add?.length ?? 0) > 0,
    );
}

export function transferStages(
    pipelines: DealPipeline[],
    pipelineId: number,
): DealStage[] {
    return (
        pipelines.find((pipeline) => pipeline.id === pipelineId)?.stages ?? []
    )
        .filter((stage) => stage.active)
        .sort((a, b) => a.position - b.position);
}

export function transferBody(input: {
    expectedVersion: number;
    pipelineId: number;
    stageId: number;
    notes: string;
    lostReason: string;
    confirmed: boolean;
}): Record<string, unknown> {
    return {
        expected_version: input.expectedVersion,
        pipeline_id: input.pipelineId,
        stage_id: input.stageId,
        confirmed: input.confirmed,
        ...(input.notes.trim() ? { notes: input.notes.trim() } : {}),
        ...(input.lostReason.trim()
            ? { lost_reason: input.lostReason.trim() }
            : {}),
    };
}
