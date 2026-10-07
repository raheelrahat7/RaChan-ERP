import type { Choice } from '@/lib/crm-requirements';

export type MatchListing = {
    id: number;
    reference: string;
    status: string;
    purpose: string;
    market_segment: string | null;
    price: string | null;
    currency: string | null;
    unit: {
        id: number;
        number: string;
        type: string;
        area: string | null;
        area_unit: string | null;
    } | null;
    property: { id: number; name: string; city: string | null } | null;
    community: string | null;
};
export type LeadMatch = {
    id: number;
    lead_id: number;
    listing_id: number;
    version: number;
    listing: MatchListing;
    match_percent: number;
    match_percent_override: number | null;
    score_source: 'automatic' | 'manual';
    matched_on: string[];
    evaluated_on: string[];
    shared: boolean;
    viewing_status: string;
    viewing_at: string | null;
    notes: string | null;
    permissions: { read: boolean; edit: boolean; delete: boolean };
};
export type MatchCandidate = {
    listing: MatchListing;
    match_percent: number;
    matched_on: string[];
    evaluated_on: string[];
    permissions: { read: boolean; add: boolean };
};
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
};
export type MatchForm = {
    /** Text from the form, or a number when the number input has been edited. */
    override: string | number;
    shared: boolean;
    viewing_status: string;
    viewing_at: string;
    notes: string;
};

export const CRITERIA_LABELS: Record<string, string> = {
    purpose: 'Purpose',
    property_type: 'Property type',
    location: 'Location',
    budget: 'Budget',
    size: 'Size',
};

/** "3 of 4 criteria" — the percentage alone hides how much it was judged on. */
export function criteriaText(matched: string[], evaluated: string[]): string {
    return evaluated.length ? `${matched.length}/${evaluated.length}` : '—';
}

export function scoreTone(percent: number): 'high' | 'medium' | 'low' {
    return percent >= 75 ? 'high' : percent >= 40 ? 'medium' : 'low';
}

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/** ISO timestamp → value for a datetime-local input, in the browser's timezone. */
export function toLocalInput(iso: string | null): string {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** datetime-local value → ISO 8601 with timezone, or null when blank or invalid. */
export function fromLocalInput(local: string): string | null {
    if (!local.trim()) {
        return null;
    }
    const date = new Date(local);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

export function matchForm(match?: LeadMatch): MatchForm {
    return {
        override:
            match?.match_percent_override === null ||
            match?.match_percent_override === undefined
                ? ''
                : String(match.match_percent_override),
        shared: match?.shared ?? false,
        viewing_status: match?.viewing_status ?? 'not_scheduled',
        viewing_at: toLocalInput(match?.viewing_at ?? null),
        notes: match?.notes ?? '',
    };
}

/** Number inputs hand back numbers, so normalise before trimming. */
function overrideValue(text: string | number): number | null {
    const trimmed = String(text ?? '').trim();

    return trimmed === '' ? null : Number(trimmed);
}

export function createBody(
    listingId: number,
    form: MatchForm,
): Record<string, unknown> {
    return {
        listing_id: listingId,
        match_percent_override: overrideValue(form.override),
        shared: form.shared,
        viewing_status: form.viewing_status,
        viewing_at: fromLocalInput(form.viewing_at),
        notes: form.notes.trim() || null,
    };
}

/** Only changed fields, plus the match's own version. */
export function updateBody(
    match: LeadMatch,
    form: MatchForm,
): Record<string, unknown> {
    const before = matchForm(match);
    const body: Record<string, unknown> = { expected_version: match.version };
    if (String(form.override ?? '').trim() !== before.override) {
        body.match_percent_override = overrideValue(form.override);
    }
    if (form.shared !== before.shared) {
        body.shared = form.shared;
    }
    if (form.viewing_status !== before.viewing_status) {
        body.viewing_status = form.viewing_status;
    }
    if (form.viewing_at !== before.viewing_at) {
        body.viewing_at = fromLocalInput(form.viewing_at);
    }
    if (form.notes.trim() !== before.notes.trim()) {
        body.notes = form.notes.trim() || null;
    }

    return body;
}

export function hasChanges(body: Record<string, unknown>): boolean {
    return Object.keys(body).some((key) => key !== 'expected_version');
}

/** Scheduled needs a date; the server enforces it, this just avoids a wasted round trip. */
export function needsDate(status: string, viewingAt: string): boolean {
    return status === 'scheduled' && viewingAt.trim() === '';
}

export function statusChoices(choices: Choice[], current: string): Choice[] {
    return choices.filter(
        (choice) => choice.active || choice.value === current,
    );
}

export function priceText(listing: MatchListing): string {
    return listing.price
        ? `${listing.currency ?? ''} ${listing.price}`.trim()
        : '—';
}
