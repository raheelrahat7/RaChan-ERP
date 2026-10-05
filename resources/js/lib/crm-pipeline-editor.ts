import type { Stage } from '../types/crm-pipeline';

export type StageGroups = {
    initial: Stage | null;
    additional: Stage[];
    won: Stage[];
    lost: Stage[];
};

const byPosition = (a: Stage, b: Stage): number =>
    a.position - b.position || a.id - b.id;

/** Splits a pipeline's stages the way the editor lays them out. */
export function groupStages(stages: Stage[]): StageGroups {
    const sorted = [...stages].sort(byPosition);
    const initial = sorted.find((stage) => stage.is_initial) ?? null;

    return {
        initial,
        additional: sorted.filter(
            (stage) =>
                stage !== initial &&
                (stage.type === 'normal' || stage.type === 'on_hold'),
        ),
        won: sorted.filter((stage) => stage.type === 'won'),
        lost: sorted.filter((stage) => stage.type === 'lost'),
    };
}

/**
 * Moves one stage within its list and reassigns the list's own set of positions,
 * so stages outside the list keep their place. Returns only the stages that changed.
 */
export function moveWithin(
    list: Stage[],
    stageId: number,
    to: number,
): { id: number; position: number }[] {
    const from = list.findIndex((stage) => stage.id === stageId);
    const target = Math.max(0, Math.min(list.length - 1, to));
    if (from === -1 || from === target) {
        return [];
    }
    const slots = list.map((stage) => stage.position).sort((a, b) => a - b);
    const next = [...list];
    const [moved] = next.splice(from, 1);
    next.splice(target, 0, moved);

    return next
        .map((stage, index) => ({ id: stage.id, position: slots[index] }))
        .filter(
            (change) =>
                list.find((stage) => stage.id === change.id)?.position !==
                change.position,
        );
}

export type Slice = { id: number; color: string; points: string };

/** Slices of a downward-pointing funnel, one per stage, top width `width`. */
export function funnelSlices(
    stages: Pick<Stage, 'id' | 'color'>[],
    width = 100,
    height = 60,
): Slice[] {
    const count = stages.length;
    if (!count) {
        return [];
    }
    const widthAt = (y: number): number => width * (1 - y / height);

    return stages.map((stage, index) => {
        const top = (height / count) * index;
        const bottom = (height / count) * (index + 1);
        const topInset = (width - widthAt(top)) / 2;
        const bottomInset = (width - widthAt(bottom)) / 2;
        const round = (n: number): number => Math.round(n * 100) / 100;

        return {
            id: stage.id,
            color: stage.color,
            points: [
                [topInset, top],
                [width - topInset, top],
                [width - bottomInset, bottom],
                [bottomInset, bottom],
            ]
                .map(([x, y]) => `${round(Math.max(0, x))},${round(y)}`)
                .join(' '),
        };
    });
}
