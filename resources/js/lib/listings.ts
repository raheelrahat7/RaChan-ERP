export type WorkflowStatus = {
    code: string;
    name: string;
    position: number;
    active: boolean;
    id: number | null;
    version: number | null;
};
export type ListingRow = {
    id: number;
    version: number;
    reference: string;
    purpose: 'sale' | 'rent';
    status: 'draft' | 'active' | 'paused' | 'closed';
    workflow_status: string;
    market_segment: 'primary' | 'secondary' | null;
    price: string;
    currency?: string | null;
    price_per_sqft: string | null;
    public_url: string | null;
    unit: { id: number; number: string; type?: string | null } | null;
    property: { id: number; name: string; city?: string | null } | null;
    building: { id: number; name: string } | null;
    seller?: { id: number; name: string } | null;
    buyer?: { id: number; first_name: string; last_name: string } | null;
    valuation_price?: string | null;
    mortgage_status?: string | null;
    noc_status?: string | null;
    transfer_status?: string | null;
    permissions: { read: boolean; edit: boolean; change_status: boolean };
    [key: string]: unknown;
};

const TEXT = [
    'workflow_status',
    'listing_category',
    'unit_category',
    'emirate',
    'community',
    'sub_community',
    'trakheesi_permit',
    'dld_permit',
    'bedroom_type',
    'furnishing',
    'completion_status',
    'grade',
    'fit_out',
    'price_type',
    'price_label',
    'developer_name',
    'currency',
    'mortgage_status',
    'noc_status',
    'transfer_status',
    'handover_date',
] as const;
const DECIMAL = [
    'price',
    'price_min',
    'price_max',
    'size_sqft',
    'plot_size_sqft',
    'valuation_price',
] as const;
const INTEGER = [
    'bedrooms',
    'bathrooms',
    'balconies',
    'parking_spaces',
] as const;
const IDS = [
    'cost_centre_id',
    'owner_id',
    'developer_id',
    'broker_id',
    'buyer_contact_id',
] as const;

export type DetailForm = Record<
    | (typeof TEXT)[number]
    | (typeof DECIMAL)[number]
    | (typeof INTEGER)[number]
    | (typeof IDS)[number],
    string
> & { loading_bay: boolean; portals: string };

/** Fields only shown, and only saved, on secondary-market screens. */
export const SECONDARY_FIELDS = [
    'valuation_price',
    'mortgage_status',
    'noc_status',
    'transfer_status',
    'buyer_contact_id',
] as const;

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value).trim()
        : '';

export function detailForm(listing?: Partial<ListingRow> | null): DetailForm {
    const form: Record<string, string | boolean> = {};
    for (const key of [...TEXT, ...DECIMAL, ...INTEGER, ...IDS]) {
        form[key] = text(listing?.[key]);
    }
    form.currency = text(listing?.currency) || 'AED';
    form.workflow_status = text(listing?.workflow_status) || 'draft';
    form.loading_bay = listing?.loading_bay === true;
    form.portals = Array.isArray(listing?.portals)
        ? (listing.portals as unknown[]).map(text).join(', ')
        : '';

    return form as DetailForm;
}

function portalsOf(value: string): string[] {
    return [
        ...new Set(
            value
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean),
        ),
    ];
}

/** Request value for one field: blank becomes null, numbers become numbers. */
function fieldValue(key: string, form: DetailForm): unknown {
    if (key === 'loading_bay') {
        return form.loading_bay;
    }
    if (key === 'portals') {
        return portalsOf(form.portals);
    }
    const value = text(form[key as keyof DetailForm]);
    if (key === 'currency') {
        return value.toUpperCase() || null;
    }
    if (value === '') {
        return null;
    }

    return (INTEGER as readonly string[]).includes(key) ||
        (IDS as readonly string[]).includes(key)
        ? Number(value)
        : value;
}

const ALL_KEYS = [
    ...TEXT,
    ...DECIMAL,
    ...INTEGER,
    ...IDS,
    'loading_bay',
    'portals',
];

/** Only changed fields, with the listing's version. Secondary fields are skipped off the secondary screen. */
export function updateBody(
    listing: ListingRow,
    form: DetailForm,
    secondary: boolean,
): Record<string, unknown> {
    const before = detailForm(listing);
    const body: Record<string, unknown> = { expected_version: listing.version };
    for (const key of ALL_KEYS) {
        if (
            !secondary &&
            (SECONDARY_FIELDS as readonly string[]).includes(key)
        ) {
            continue;
        }
        const next = fieldValue(key, form);
        if (JSON.stringify(next) !== JSON.stringify(fieldValue(key, before))) {
            body[key] = next;
        }
    }

    return body;
}

export function hasChanges(body: Record<string, unknown>): boolean {
    return Object.keys(body).some((key) => key !== 'expected_version');
}

/** Optional fields to send with a create; blanks are left out entirely. */
export function createExtras(
    form: DetailForm,
    secondary: boolean,
): Record<string, unknown> {
    const body: Record<string, unknown> = {};
    for (const key of ALL_KEYS) {
        if (key === 'price' || key === 'currency' || key === 'broker_id') {
            continue;
        }
        if (
            !secondary &&
            (SECONDARY_FIELDS as readonly string[]).includes(key)
        ) {
            continue;
        }
        const value = fieldValue(key, form);
        const empty =
            value === null ||
            value === false ||
            (Array.isArray(value) && !value.length);
        if (!empty && !(key === 'workflow_status' && value === 'draft')) {
            body[key] = value;
        }
    }

    return body;
}

export type Filters = {
    q: string;
    status: string;
    workflow_status: string;
    listing_category: string;
    broker_id: string;
    cost_centre_id: string;
    emirate: string;
    community: string;
    sort: string;
};

export const emptyFilters = (): Filters => ({
    q: '',
    status: '',
    workflow_status: '',
    listing_category: '',
    broker_id: '',
    cost_centre_id: '',
    emirate: '',
    community: '',
    sort: 'latest',
});

export function filtersQuery(
    filters: Filters,
    segment: string | null,
    page = 1,
): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value !== '' && !(key === 'sort' && value === 'latest')) {
            params.set(key, value);
        }
    }
    if (segment) {
        params.set('market_segment', segment);
    }
    if (page > 1) {
        params.set('page', String(page));
    }

    return params.toString();
}

export function activeFilterCount(filters: Filters): number {
    return Object.entries(filters).filter(
        ([key, value]) =>
            value !== '' && !(key === 'sort' && value === 'latest'),
    ).length;
}

/** Tabs: active statuses in order, plus the one a filter is on even if archived. */
export function stripStatuses(
    statuses: WorkflowStatus[],
    selected: string,
): WorkflowStatus[] {
    return [...statuses]
        .filter((status) => status.active || status.code === selected)
        .sort((a, b) => a.position - b.position);
}

/** Statuses a listing can be moved to: active ones, plus the one it already has. */
export function statusChoices(
    statuses: WorkflowStatus[],
    current: string,
): WorkflowStatus[] {
    return statuses.filter(
        (status) => status.active || status.code === current,
    );
}

export function statusName(
    statuses: WorkflowStatus[],
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

export function countFor(
    summary: { code: string; total: number }[] | null | undefined,
    code: string,
): number | null {
    if (!summary) {
        return null;
    }

    return summary.find((row) => row.code === code)?.total ?? 0;
}

export function segmentLabel(
    listing: Pick<ListingRow, 'purpose' | 'market_segment'>,
): string {
    if (listing.purpose === 'rent') {
        return 'Rental';
    }

    return listing.market_segment === 'secondary'
        ? 'Resale'
        : listing.market_segment === 'primary'
          ? 'Primary sale'
          : 'Unclassified sale';
}

export function moneyText(
    amount: string | null | undefined,
    currency?: string | null,
): string {
    return amount ? `${currency ?? 'AED'} ${amount}` : '—';
}

export function personName(
    person?: { first_name: string; last_name: string } | null,
): string {
    return person ? `${person.first_name} ${person.last_name}`.trim() : '—';
}

/** Local check that matches the server rule, to save a round trip. */
export function priceRangeError(
    form: Pick<DetailForm, 'price_min' | 'price_max'>,
): boolean {
    const min = text(form.price_min);
    const max = text(form.price_max);

    return min !== '' && max !== '' && Number(min) > Number(max);
}

export function codeFromName(name: string): string {
    const code = name
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    return /^[a-z]/.test(code) ? code.slice(0, 40) : '';
}
