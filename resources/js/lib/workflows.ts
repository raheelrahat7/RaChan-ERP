export type WorkflowKind = 'estimate' | 'invoice' | 'document' | 'recruitment';
export type WorkflowStage = {
    id: number;
    name: string;
    type: 'normal' | 'success' | 'failure';
    color: string;
    position: number;
    active: boolean;
    is_initial: boolean;
};
export type WorkflowPipeline = {
    id: number;
    kind: WorkflowKind;
    name: string;
    active: boolean;
    stages: WorkflowStage[];
};
export type WorkflowRecord = {
    id: number;
    title: string;
    reference: string | null;
    pipeline_id: number;
    stage_id: number;
    assigned_to: number;
    version: number;
    closed_at: string | null;
    invoice_id: number | null;
    details: Record<string, unknown>;
};
export type EstimateLine = {
    description: string;
    quantity: string;
    unit_price: string;
};

export const WORKFLOW_KINDS: { key: WorkflowKind; label: string }[] = [
    { key: 'estimate', label: 'Estimates' },
    { key: 'invoice', label: 'Invoices' },
    { key: 'document', label: 'Documents' },
    { key: 'recruitment', label: 'Recruitment' },
];

/** Estimates and recruitment can be started here; the others are linked from their own screens. */
export function canCreateKind(kind: WorkflowKind): boolean {
    return kind === 'estimate' || kind === 'recruitment';
}

export function activeStages(pipeline: WorkflowPipeline): WorkflowStage[] {
    return [...pipeline.stages]
        .filter((stage) => stage.active)
        .sort((a, b) => a.position - b.position || a.id - b.id);
}

export function groupByStage(
    stages: WorkflowStage[],
    records: WorkflowRecord[],
): Map<number, WorkflowRecord[]> {
    const groups = new Map<number, WorkflowRecord[]>(
        stages.map((stage) => [stage.id, []]),
    );
    for (const record of records) {
        groups.get(record.stage_id)?.push(record);
    }

    return groups;
}

export function moveTargets(
    stages: WorkflowStage[],
    currentStageId: number,
): WorkflowStage[] {
    return stages.filter(
        (stage) => stage.active && stage.id !== currentStageId,
    );
}

/** Failing a record or reopening a closed one needs a written reason. */
export function needsReason(
    record: Pick<WorkflowRecord, 'closed_at'>,
    target: Pick<WorkflowStage, 'type'> | undefined,
): boolean {
    return !!target && (target.type === 'failure' || record.closed_at !== null);
}

/** Decimal-string line total in cents (mirrors the server's half-up rounding). */
export function lineTotalCents(line: EstimateLine): number {
    const toHundredths = (value: string): number => {
        const [whole = '0', fraction = ''] = value.trim().split('.');

        return (
            Number(whole) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))
        );
    };
    const quantity = toHundredths(line.quantity);
    const price = toHundredths(line.unit_price);

    return Number.isFinite(quantity * price)
        ? Math.floor((quantity * price + 50) / 100)
        : 0;
}

export function estimateTotal(lines: EstimateLine[]): string {
    const cents = lines.reduce((sum, line) => sum + lineTotalCents(line), 0);

    return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}

export function operationKey(): string {
    return crypto.randomUUID();
}
