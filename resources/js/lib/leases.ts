export type LeaseTab =
    | 'draft'
    | 'active'
    | 'renewal_due'
    | 'renewed'
    | 'moved_out';
export const LEASE_TABS: { key: LeaseTab; label: string }[] = [
    { key: 'draft', label: 'Draft' },
    { key: 'active', label: 'Active' },
    { key: 'renewal_due', label: 'Renewal due' },
    { key: 'renewed', label: 'Renewed' },
    { key: 'moved_out', label: 'Moved out' },
];

export type LeaseRow = {
    id: number;
    reference: string;
    version: number;
    status: string;
    starts_on: string;
    ends_on: string;
    rent_amount: string | null;
    currency?: string | null;
    tenancy_number: string | null;
    renewal_due_on: string | null;
    last_renewed_on: string | null;
    advance_amount: string | null;
    tenant: { id: number; name: string } | null;
    unit: { id: number; number: string } | null;
    security_deposit: {
        id: number;
        required_amount: string;
        due_on: string | null;
        notes: string | null;
    } | null;
    ejari: {
        id: number;
        status: string;
        ejari_number: string | null;
        registered_on: string | null;
        expires_on: string | null;
    } | null;
    cheques: {
        id: number;
        cheque_number: string;
        amount: string;
        due_on: string | null;
        status: string;
    }[];
    move_out: {
        id: number;
        status: string;
        scheduled_on: string | null;
        completed_at: string | null;
    } | null;
    renewal_due: boolean;
    permissions: {
        read: boolean;
        edit: boolean;
        renew: boolean;
        schedule_move_out: boolean;
        manage_deposit: boolean;
        manage_cheques: boolean;
    };
};

export type DetailForm = {
    tenancy_number: string;
    renewal_due_on: string;
    advance_amount: string | number;
};
export type RenewForm = {
    ends_on: string;
    rent_amount: string | number;
    renewal_due_on: string;
};

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value).trim()
        : '';
const orNull = (value: unknown): string | null => text(value) || null;

export function detailForm(lease?: Partial<LeaseRow> | null): DetailForm {
    return {
        tenancy_number: lease?.tenancy_number ?? '',
        renewal_due_on: lease?.renewal_due_on ?? '',
        advance_amount: lease?.advance_amount ?? '',
    };
}

/** Only changed fields plus the version; blanks clear a field. */
export function detailBody(
    lease: LeaseRow,
    form: DetailForm,
): Record<string, unknown> {
    const before = detailForm(lease);
    const body: Record<string, unknown> = { expected_version: lease.version };
    for (const key of [
        'tenancy_number',
        'renewal_due_on',
        'advance_amount',
    ] as const) {
        if (text(form[key]) !== text(before[key])) {
            body[key] = orNull(form[key]);
        }
    }

    return body;
}

export function hasChanges(body: Record<string, unknown>): boolean {
    return Object.keys(body).some((key) => key !== 'expected_version');
}

export function renewForm(lease?: Partial<LeaseRow> | null): RenewForm {
    return {
        ends_on: '',
        rent_amount: lease?.rent_amount ?? '',
        renewal_due_on: '',
    };
}

/** The new end date must be later than the current one; the server checks too. */
export function renewError(
    lease: Pick<LeaseRow, 'ends_on'>,
    form: Pick<RenewForm, 'ends_on'>,
): boolean {
    return (
        text(form.ends_on) === '' ||
        text(form.ends_on) <= lease.ends_on.slice(0, 10)
    );
}

export function renewBody(
    lease: LeaseRow,
    form: RenewForm,
): Record<string, unknown> {
    const body: Record<string, unknown> = {
        expected_version: lease.version,
        ends_on: text(form.ends_on),
    };
    const rent = orNull(form.rent_amount);
    if (rent !== null && rent !== text(lease.rent_amount)) {
        body.rent_amount = rent;
    }
    const due = orNull(form.renewal_due_on);
    if (due !== null) {
        body.renewal_due_on = due;
    }

    return body;
}

export function leaseQuery(q: string, tab: LeaseTab | '', page = 1): string {
    const params = new URLSearchParams();
    if (q.trim()) {
        params.set('q', q.trim());
    }
    if (tab) {
        params.set('tab', tab);
    }
    if (page > 1) {
        params.set('page', String(page));
    }

    return params.toString();
}

/** Days until a date (negative when past), counted in whole days from `now`. */
export function daysUntil(date: string | null, now: number): number | null {
    if (!date) {
        return null;
    }
    const time = Date.parse(`${date.slice(0, 10)}T00:00:00Z`);
    if (Number.isNaN(time)) {
        return null;
    }
    const today = Date.parse(
        new Date(now).toISOString().slice(0, 10) + 'T00:00:00Z',
    );

    return Math.round((time - today) / 86_400_000);
}

export function renewalText(
    lease: Pick<LeaseRow, 'renewal_due_on' | 'ends_on'>,
    now: number,
): string {
    const days = daysUntil(lease.renewal_due_on ?? lease.ends_on, now);
    if (days === null) {
        return '—';
    }

    return days < 0 ? `${-days}d overdue` : `${days}d`;
}

export function moneyText(
    amount: string | null | undefined,
    currency?: string | null,
): string {
    return amount ? `${currency ?? 'AED'} ${amount}` : '—';
}
