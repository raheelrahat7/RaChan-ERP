export type PartyType = 'owners' | 'developers';
export type PartyRecord = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    reference: string | null;
    payment_terms: string | null;
    commission_notes: string | null;
    version: number;
    listings?: {
        id: number;
        reference: string;
        purpose: string;
        status: string;
        price: string;
        currency: string | null;
    }[];
    offplan_projects?: {
        id: number;
        code: string;
        name: string;
        workflow_status: string | null;
    }[];
    permissions: { read: boolean; edit: boolean };
};
export type PartyForm = {
    name: string;
    email: string;
    phone: string;
    reference: string;
    payment_terms: string;
    commission_notes: string;
};

const FIELDS = [
    'name',
    'email',
    'phone',
    'reference',
    'payment_terms',
    'commission_notes',
] as const;

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value).trim()
        : '';

export function partyForm(party?: Partial<PartyRecord> | null): PartyForm {
    return {
        name: party?.name ?? '',
        email: party?.email ?? '',
        phone: party?.phone ?? '',
        reference: party?.reference ?? '',
        payment_terms: party?.payment_terms ?? '',
        commission_notes: party?.commission_notes ?? '',
    };
}

/** Create body: the name always, optional fields only when filled in. */
export function createBody(form: PartyForm): Record<string, unknown> {
    const body: Record<string, unknown> = {};
    for (const key of FIELDS) {
        const value = text(form[key]);
        if (key === 'name' || value !== '') {
            body[key] = value;
        }
    }

    return body;
}

/** Only changed fields plus the version; a blank clears an optional field. */
export function updateBody(
    party: PartyRecord,
    form: PartyForm,
): Record<string, unknown> {
    const before = partyForm(party);
    const body: Record<string, unknown> = { expected_version: party.version };
    for (const key of FIELDS) {
        if (text(form[key]) !== text(before[key])) {
            body[key] =
                key === 'name' ? text(form[key]) : text(form[key]) || null;
        }
    }

    return body;
}

export function hasChanges(body: Record<string, unknown>): boolean {
    return Object.keys(body).some((key) => key !== 'expected_version');
}

export function partyQuery(q: string, page = 1): string {
    const params = new URLSearchParams();
    if (q.trim()) {
        params.set('q', q.trim());
    }
    if (page > 1) {
        params.set('page', String(page));
    }

    return params.toString();
}

export const SINGULAR: Record<PartyType, string> = {
    owners: 'owner',
    developers: 'developer',
};

/** First line of a long note, for table cells. */
export function shorten(value: string | null | undefined, max = 60): string {
    const line = (value ?? '').split('\n')[0].trim();
    if (line === '') {
        return '—';
    }

    return line.length > max ? `${line.slice(0, max - 1)}…` : line;
}
