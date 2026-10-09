export type ProjectStatus = {
    code: string;
    name: string;
    position: number;
    active: boolean;
    id: number | null;
    version: number | null;
};
export type ProjectRow = {
    id: number;
    code: string;
    name: string;
    developer_name: string;
    emirate: string;
    location: string | null;
    status: string;
    workflow_status: string | null;
    launch_on: string | null;
    handover_on: string | null;
    completion_on: string | null;
    assigned_broker_id: number | null;
    assigned_broker_name: string | null;
    commission_rate: string | null;
    version: number;
    units_total: number;
    units_available: number;
    units_sold: number;
    starting_price_aed: string | null;
    permissions: { read: boolean; edit: boolean; configure_statuses: boolean };
};
export type ProjectForm = {
    workflow_status: string;
    launch_on: string;
    handover_on: string;
    completion_on: string;
    assigned_broker_id: string;
    commission_rate: string | number;
};

const FIELDS = [
    'workflow_status',
    'launch_on',
    'handover_on',
    'completion_on',
    'assigned_broker_id',
    'commission_rate',
] as const;

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value).trim()
        : '';

export function projectForm(project?: Partial<ProjectRow> | null): ProjectForm {
    return {
        workflow_status: project?.workflow_status ?? '',
        launch_on: project?.launch_on ?? '',
        handover_on: project?.handover_on ?? '',
        completion_on: project?.completion_on ?? '',
        assigned_broker_id: text(project?.assigned_broker_id),
        commission_rate: project?.commission_rate ?? '',
    };
}

function value(key: (typeof FIELDS)[number], form: ProjectForm): unknown {
    const raw = text(form[key]);
    if (raw === '') {
        return null;
    }

    return key === 'assigned_broker_id' ? Number(raw) : raw;
}

/** Only changed fields plus the version; a blank clears the field (except the status). */
export function updateBody(
    project: ProjectRow,
    form: ProjectForm,
): Record<string, unknown> {
    const before = projectForm(project);
    const body: Record<string, unknown> = { expected_version: project.version };
    for (const key of FIELDS) {
        if (key === 'workflow_status' && text(form[key]) === '') {
            continue;
        }
        if (text(form[key]) !== text(before[key])) {
            body[key] = value(key, form);
        }
    }

    return body;
}

export function hasChanges(body: Record<string, unknown>): boolean {
    return Object.keys(body).some((key) => key !== 'expected_version');
}

/** Handover cannot come before launch; the server enforces it too. */
export function datesOrderError(
    form: Pick<ProjectForm, 'launch_on' | 'handover_on'>,
): boolean {
    return (
        text(form.launch_on) !== '' &&
        text(form.handover_on) !== '' &&
        text(form.handover_on) < text(form.launch_on)
    );
}

export function statusLabel(
    statuses: ProjectStatus[],
    code: string | null | undefined,
): string {
    if (!code) {
        return '—';
    }

    return (
        statuses.find((status) => status.code === code)?.name ??
        code.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase())
    );
}

/** Statuses a project can be moved to: active ones plus the one it already has. */
export function statusChoices(
    statuses: ProjectStatus[],
    current: string | null | undefined,
): ProjectStatus[] {
    return [...statuses]
        .filter((status) => status.active || status.code === current)
        .sort((a, b) => a.position - b.position);
}

export function countFor(
    counts: { code: string; total: number }[],
    code: string,
): number {
    return counts.find((row) => row.code === code)?.total ?? 0;
}

/** Share of units sold, for the progress bar; 0 when there are no units. */
export function soldPercent(
    project: Pick<ProjectRow, 'units_total' | 'units_sold'>,
): number {
    return project.units_total > 0
        ? Math.round((project.units_sold / project.units_total) * 100)
        : 0;
}

export function projectQuery(q: string, status: string, page = 1): string {
    const params = new URLSearchParams();
    if (q.trim()) {
        params.set('q', q.trim());
    }
    if (status) {
        params.set('workflow_status', status);
    }
    if (page > 1) {
        params.set('page', String(page));
    }

    return params.toString();
}

export function moneyText(amount: string | null | undefined): string {
    return amount ? `AED ${amount}` : '—';
}
