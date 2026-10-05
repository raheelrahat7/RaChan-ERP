import type { Deal, DealKind, DealStage, StageTotal } from '../types/crm-deals';

export const KIND_LABELS: Record<DealKind, string> = {
    off_plan: 'Off plan',
    secondary: 'Secondary',
    resale: 'Resale',
    listing: 'Listing',
};

export function kindLabel(kind: string): string {
    return KIND_LABELS[kind as DealKind] ?? kind.replaceAll('_', ' ');
}

export function sortedStages(stages: DealStage[]): DealStage[] {
    return [...stages].sort((a, b) => a.position - b.position || a.id - b.id);
}

export function groupDealsByStage(
    stages: DealStage[],
    deals: Deal[],
): { stage: DealStage; deals: Deal[] }[] {
    return sortedStages(stages).map((stage) => ({
        stage,
        deals: deals.filter((deal) => deal.stage_id === stage.id),
    }));
}

/** Per-stage counts and amount sums, for when the server sends no totals. */
export function computeStageTotals(
    stages: DealStage[],
    deals: Deal[],
): StageTotal[] {
    return sortedStages(stages).map((stage) => {
        const inStage = deals.filter((deal) => deal.stage_id === stage.id);
        const priced = inStage.filter((deal) => deal.amount !== null);

        return {
            id: stage.id,
            count: inStage.length,
            amount: priced.length
                ? priced.reduce((sum, deal) => sum + (deal.amount ?? 0), 0)
                : inStage.length
                  ? 0
                  : null,
        };
    });
}

/** Case-insensitive match on title, contact, assignee and deal type. */
export function searchDeals(deals: Deal[], query: string): Deal[] {
    const needle = query.trim().toLowerCase();
    if (!needle) {
        return deals;
    }

    return deals.filter((deal) =>
        [
            deal.title,
            deal.contact?.name,
            deal.assignee?.name,
            kindLabel(deal.kind),
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(needle),
    );
}
