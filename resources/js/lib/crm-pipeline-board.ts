import type { Pipeline, Stage, TransitionOption } from '../types/crm-pipeline';

export type BoardLead = {
    id: number;
    first_name: string;
    last_name: string;
    company: string | null;
    email: string | null;
    phone: string | null;
    pipeline_id: number;
    current_stage_id: number;
    converted: boolean;
    transitionOptions?: TransitionOption[];
    custom_fields?: Record<string, unknown>;
    assignee: { id: number; name: string } | null;
};

export function boardColumns(pipeline: Pipeline, leads: BoardLead[]) {
    return pipeline.stages.map((stage) => ({
        stage,
        leads: leads.filter(
            (lead) =>
                lead.pipeline_id === pipeline.id &&
                lead.current_stage_id === stage.id,
        ),
    }));
}

export function canMoveOnBoard(
    pipeline: Pipeline,
    lead: BoardLead,
    canManage: boolean,
): boolean {
    return (
        canManage &&
        pipeline.active &&
        !lead.converted &&
        lead.pipeline_id === pipeline.id
    );
}

export function canDropOnStage(
    pipeline: Pipeline,
    lead: BoardLead,
    target: Stage,
    canManage: boolean,
): boolean {
    if (
        !canMoveOnBoard(pipeline, lead, canManage) ||
        !target.active ||
        target.id === lead.current_stage_id ||
        !pipeline.stages.some((stage) => stage.id === target.id)
    )
        return false;
    if (
        lead.transitionOptions?.find((option) => option.stage_id === target.id)
            ?.reasons.length
    )
        return false;
    const current = pipeline.stages.find(
        (stage) => stage.id === lead.current_stage_id,
    );
    if (!current) return false;
    return (
        current.type !== 'lost' ||
        (target.type !== 'lost' && target.type !== 'won')
    );
}

/** Readable text color (near-black or white) for a stage's hex background. */
export function readableOn(hex: string): string {
    const match = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
    if (!match) {
        return '#1f1a17';
    }
    const [r, g, b] = [0, 2, 4].map((i) =>
        parseInt(match[1].slice(i, i + 2), 16),
    );
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

    return luminance > 0.6 ? '#1f1a17' : '#ffffff';
}
