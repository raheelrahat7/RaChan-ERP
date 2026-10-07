export type Choice = { value: string; label: string; active: boolean };
export type CommercialKind = 'offer' | 'contract';
export type CommercialRecord = {
    id: number;
    lead_id: number;
    kind: CommercialKind;
    reference: string | null;
    title: string;
    party_name: string | null;
    status: string;
    amount: string | null;
    currency: string | null;
    submitted_on: string | null;
    signed_on: string | null;
    notes: string | null;
    deal_id: number | null;
    version: number;
    permissions: { view: boolean; edit: boolean };
};
export type CommercialSettings = {
    statuses: Record<CommercialKind, Choice[]>;
    version: number;
    permissions: { read: boolean; edit: boolean };
};
export type AccountingDocument = {
    kind: 'estimate' | 'invoice';
    id: number;
    reference: string;
    title: string | null;
    status: string;
    amount: string | null;
    vat: string | null;
    total: string | null;
    currency: string | null;
    version: number | null;
    permissions: { view: boolean; edit: boolean };
};
export type CommercialForm = {
    kind: CommercialKind;
    title: string;
    reference: string;
    party_name: string;
    status: string;
    amount: string | number;
    currency: string;
    submitted_on: string;
    signed_on: string;
    notes: string;
    deal_id: string | number;
};

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value).trim()
        : '';

export function commercialForm(
    kind: CommercialKind,
    record?: CommercialRecord,
    firstStatus = 'draft',
): CommercialForm {
    return {
        kind: record?.kind ?? kind,
        title: record?.title ?? '',
        reference: record?.reference ?? '',
        party_name: record?.party_name ?? '',
        status: record?.status ?? firstStatus,
        amount: record?.amount ?? '',
        currency: record?.currency ?? '',
        submitted_on: record?.submitted_on ?? '',
        signed_on: record?.signed_on ?? '',
        notes: record?.notes ?? '',
        deal_id: record?.deal_id ?? '',
    };
}

const orNull = (value: unknown): string | null => text(value) || null;

/** The server wants amount and currency together; the form checks first to save a round trip. */
export function amountNeedsCurrency(
    form: Pick<CommercialForm, 'amount' | 'currency'>,
): boolean {
    return (text(form.amount) === '') !== (text(form.currency) === '');
}

export function createBody(form: CommercialForm): Record<string, unknown> {
    return {
        kind: form.kind,
        title: text(form.title),
        reference: orNull(form.reference),
        party_name: orNull(form.party_name),
        status: form.status,
        amount: orNull(form.amount),
        currency: orNull(form.currency)?.toUpperCase() ?? null,
        submitted_on: orNull(form.submitted_on),
        signed_on: orNull(form.signed_on),
        notes: orNull(form.notes),
        deal_id: text(form.deal_id) === '' ? null : Number(form.deal_id),
    };
}

/** Only changed fields; kind never changes after create. */
export function updateBody(
    record: CommercialRecord,
    form: CommercialForm,
): Record<string, unknown> {
    const before = commercialForm(record.kind, record);
    const body: Record<string, unknown> = { expected_version: record.version };
    const after = createBody(form);
    const was = createBody(before);
    for (const key of Object.keys(after)) {
        if (key === 'kind') {
            continue;
        }
        if (JSON.stringify(after[key]) !== JSON.stringify(was[key])) {
            body[key] = after[key];
        }
    }

    return body;
}

export function hasChanges(body: Record<string, unknown>): boolean {
    return Object.keys(body).some((key) => key !== 'expected_version');
}

/** Active statuses plus an archived one the record already has, so editing never forces a change. */
export function statusOptions(choices: Choice[], current: string): Choice[] {
    return choices.filter(
        (choice) => choice.active || choice.value === current,
    );
}

export function statusLabel(choices: Choice[], value: string): string {
    return (
        choices.find((choice) => choice.value === value)?.label ??
        value.replaceAll('_', ' ')
    );
}

export function money(amount: string | null, currency: string | null): string {
    return amount ? `${currency ?? ''} ${amount}`.trim() : '—';
}

/** Sum of document totals per currency, never mixed across currencies. */
export function totalsByCurrency(
    documents: Pick<AccountingDocument, 'total' | 'currency'>[],
): Record<string, string> {
    const cents: Record<string, number> = {};
    for (const document of documents) {
        if (document.total && document.currency) {
            cents[document.currency] =
                (cents[document.currency] ?? 0) +
                Math.round(Number(document.total) * 100);
        }
    }

    return Object.fromEntries(
        Object.entries(cents).map(([currency, value]) => [
            currency,
            (value / 100).toFixed(2),
        ]),
    );
}
