export type WorkflowKind = 'estimate' | 'invoice' | 'document' | 'recruitment';
export type WorkflowStage = {
    id: number;
    name: string;
    type: 'normal' | 'success' | 'failure';
    color: string;
    position: number;
    active: boolean;
    is_initial: boolean;
    allowed_from_stage_ids?: number[] | null;
    entry_roles?: string[] | null;
    required_fields?: string[] | null;
    source_statuses?: string[] | null;
};
export type WorkflowPipeline = {
    id: number;
    kind: WorkflowKind;
    name: string;
    active: boolean;
    stages: WorkflowStage[];
    field_definitions?: { key: string }[] | null;
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

/** crypto.randomUUID needs a secure context, so plain-http hosts fall back to getRandomValues. */
export function operationKey(): string {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

export type StageRules = {
    allowed_from_stage_ids: number[] | null;
    entry_roles: string[] | null;
    required_fields: string[] | null;
    source_statuses: string[] | null;
    is_initial: boolean;
};
export const ENTRY_ROLES = [
    'owner',
    'administrator',
    'manager',
    'member',
    'viewer',
];
export const INVOICE_STATUSES = ['draft', 'posted', 'partial', 'paid', 'void'];
/** Detail fields that make sense per workflow kind (the server accepts the full list). */
const KIND_FIELDS: Record<WorkflowKind, string[]> = {
    estimate: ['valid_until', 'lines', 'currency', 'notes'],
    invoice: ['notes'],
    document: ['notes'],
    recruitment: [
        'name',
        'email',
        'phone',
        'job_title',
        'location',
        'resume_reference',
        'interview_notes',
        'offer_reference',
        'joined_on',
        'notes',
    ],
};

/** Fields a stage can require: the kind's built-in details plus the pipeline's custom fields. */
export function requirableFields(
    kind: WorkflowKind,
    detailFields: string[],
    customKeys: string[],
): { value: string; label: string }[] {
    const builtin = KIND_FIELDS[kind].filter((key) =>
        detailFields.includes(key),
    );

    return [
        ...builtin.map((key) => ({
            value: key,
            label: key.replaceAll('_', ' '),
        })),
        ...customKeys.map((key) => ({
            value: `custom:${key}`,
            label: key.replaceAll('_', ' '),
        })),
    ];
}

/** Toggle a value in a list, returning null when the list becomes empty (meaning "no restriction"). */
export function toggleRule<T>(list: T[] | null, value: T): T[] | null {
    const current = list ?? [];
    const next = current.includes(value)
        ? current.filter((item) => item !== value)
        : [...current, value];

    return next.length ? next : null;
}

export function stageRulesFrom(stage?: Partial<StageRules> | null): StageRules {
    return {
        allowed_from_stage_ids: stage?.allowed_from_stage_ids ?? null,
        entry_roles: stage?.entry_roles ?? null,
        required_fields: stage?.required_fields?.length
            ? stage.required_fields
            : null,
        source_statuses: stage?.source_statuses?.length
            ? stage.source_statuses
            : null,
        is_initial: stage?.is_initial ?? false,
    };
}

/** Request fields: empty restrictions go as null/[] exactly as the validator allows. */
export function stageRulesPayload(
    rules: StageRules,
    kind: WorkflowKind,
): Record<string, unknown> {
    return {
        is_initial: rules.is_initial,
        allowed_from_stage_ids: rules.allowed_from_stage_ids,
        entry_roles: rules.entry_roles,
        required_fields: rules.required_fields ?? [],
        source_statuses: kind === 'invoice' ? rules.source_statuses : null,
    };
}
