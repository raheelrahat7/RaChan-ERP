export type PartyField = {
    key: string;
    name: string;
    type: string;
    required?: boolean;
    options?: string[] | null;
    tooltip?: string | null;
    value: unknown;
    editable: boolean;
};
export type FieldDraft = Record<string, string | boolean | string[]>;
export type PartyContact = {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    account_id?: number | null;
    account?: { id: number; name: string } | null;
    version: number;
    permissions?: { read: boolean; edit: boolean };
};
export type PartyCompany = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    website: string | null;
    version: number;
    contacts_count?: number;
    contacts?: PartyContact[];
    permissions?: { read: boolean; edit: boolean };
};

export function fullName(contact: {
    first_name: string;
    last_name: string;
}): string {
    return `${contact.first_name} ${contact.last_name}`.trim();
}

const NUMERIC = ['number', 'currency', 'user'];

/** Starting form values for each editable custom field. */
export function fieldDraft(fields: PartyField[]): FieldDraft {
    const draft: FieldDraft = {};
    for (const field of fields) {
        if (!field.editable) {
            continue;
        }
        const value = field.value;
        if (field.type === 'checkbox') {
            draft[field.key] = value === true;
        } else if (field.type === 'multi_select') {
            draft[field.key] = Array.isArray(value) ? value.map(String) : [];
        } else {
            draft[field.key] =
                typeof value === 'string' || typeof value === 'number'
                    ? String(value)
                    : '';
        }
    }

    return draft;
}

function coerce(field: PartyField, raw: string | boolean | string[]): unknown {
    if (field.type === 'checkbox') {
        return raw === true;
    }
    if (field.type === 'multi_select') {
        return Array.isArray(raw) ? raw : [];
    }
    const text = String(raw).trim();
    if (text === '') {
        return null;
    }

    return NUMERIC.includes(field.type) ? Number(text) : text;
}

/** Only the editable fields whose value changed, so untouched hidden values are never rewritten. */
export function fieldPayload(
    fields: PartyField[],
    draft: FieldDraft,
): Record<string, unknown> {
    const original = fieldDraft(fields);
    const changes: Record<string, unknown> = {};
    for (const field of fields) {
        if (!field.editable || !(field.key in draft)) {
            continue;
        }
        if (
            JSON.stringify(draft[field.key]) !==
            JSON.stringify(original[field.key])
        ) {
            changes[field.key] = coerce(field, draft[field.key]);
        }
    }

    return changes;
}

export function displayField(field: PartyField): string {
    const value = field.value;
    if (Array.isArray(value)) {
        return value.map(String).join(', ') || '—';
    }
    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return typeof value === 'string' || typeof value === 'number'
        ? String(value) || '—'
        : '—';
}

export function contactBody(
    form: {
        first_name: string;
        last_name: string;
        email: string;
        phone: string;
        account_id: number | null;
    },
    version: number,
    custom: Record<string, unknown>,
): Record<string, unknown> {
    return {
        expected_version: version,
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        email: form.email.trim() || null,
        phone: form.phone.trim() || null,
        account_id: form.account_id,
        ...(Object.keys(custom).length ? { custom_fields: custom } : {}),
    };
}

export function companyBody(
    form: { name: string; email: string; phone: string; website: string },
    version: number,
    custom: Record<string, unknown>,
): Record<string, unknown> {
    return {
        expected_version: version,
        name: form.name.trim(),
        email: form.email.trim() || null,
        phone: form.phone.trim() || null,
        website: form.website.trim() || null,
        ...(Object.keys(custom).length ? { custom_fields: custom } : {}),
    };
}
