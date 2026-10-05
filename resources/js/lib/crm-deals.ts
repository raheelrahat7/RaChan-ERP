import type {
    CategoryOption,
    Deal,
    DealPipeline,
    DealStage,
    StageCount,
    StageTotal,
} from '../types/crm-deals';

export function sortedStages(stages: DealStage[]): DealStage[] {
    return [...stages].sort((a, b) => a.position - b.position || a.id - b.id);
}

export function categoryLabel(
    code: string,
    options: CategoryOption[] = [],
): string {
    return (
        options.find((option) => option.code === code)?.name ??
        code.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase())
    );
}

export function contactName(
    deal: Pick<Deal, 'first_name' | 'last_name' | 'company'>,
): string {
    const name = [deal.first_name, deal.last_name]
        .filter(Boolean)
        .join(' ')
        .trim();

    return name || deal.company || '';
}

/** Amount as a number, or null when hidden or empty. */
export function amountOf(deal: Pick<Deal, 'amount'>): number | null {
    if (
        deal.amount === undefined ||
        deal.amount === null ||
        deal.amount === ''
    ) {
        return null;
    }
    const value = Number(deal.amount);

    return Number.isFinite(value) ? value : null;
}

export function groupDealsByStage(
    stages: DealStage[],
    deals: Deal[],
): { stage: DealStage; deals: Deal[] }[] {
    return sortedStages(stages).map((stage) => ({
        stage,
        deals: deals.filter((deal) => deal.current_stage_id === stage.id),
    }));
}

/**
 * Stage counts from the server's grouped counts for one pipeline. Amounts appear only when the
 * server supplies them; summing a single page of deals would be wrong, so it is never guessed.
 */
export function stageTotalsFromCounts(
    pipeline: DealPipeline,
    counts: StageCount[],
): StageTotal[] {
    return sortedStages(pipeline.stages).map((stage) => {
        const row = counts.find(
            (item) =>
                Number(item.pipeline_id) === pipeline.id &&
                Number(item.current_stage_id) === stage.id,
        );
        const amount = row?.amount;

        return {
            id: stage.id,
            count: row ? Number(row.total) : 0,
            amount:
                amount === undefined || amount === null ? null : Number(amount),
        };
    });
}

/** Per-stage counts and amount sums over the given deals, for previews with complete data. */
export function computeStageTotals(
    stages: DealStage[],
    deals: Deal[],
): StageTotal[] {
    return sortedStages(stages).map((stage) => {
        const inStage = deals.filter(
            (deal) => deal.current_stage_id === stage.id,
        );
        const priced = inStage
            .map(amountOf)
            .filter((value): value is number => value !== null);

        return {
            id: stage.id,
            count: inStage.length,
            amount: priced.length
                ? priced.reduce((sum, value) => sum + value, 0)
                : inStage.length
                  ? 0
                  : null,
        };
    });
}

/** Case-insensitive match on title, contact, assignee and category name. */
export function searchDeals(
    deals: Deal[],
    query: string,
    categories: CategoryOption[] = [],
): Deal[] {
    const needle = query.trim().toLowerCase();
    if (!needle) {
        return deals;
    }

    return deals.filter((deal) =>
        [
            deal.title,
            contactName(deal),
            deal.assignee?.name,
            categoryLabel(deal.category, categories),
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(needle),
    );
}

/** Pipelines where the user may add deals. */
export function creatablePipelines(pipelines: DealPipeline[]): DealPipeline[] {
    return pipelines.filter(
        (pipeline) =>
            pipeline.active && (pipeline.permissions?.add?.length ?? 0) > 0,
    );
}

/** Active stages a deal can be moved to from its current stage. */
export function moveTargets(
    stages: DealStage[],
    currentStageId: number,
): DealStage[] {
    return sortedStages(stages).filter(
        (stage) => stage.active && stage.id !== currentStageId,
    );
}
