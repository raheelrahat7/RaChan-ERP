export type CatalogKind =
    | 'taxes'
    | 'units'
    | 'detail-templates'
    | 'company-details'
    | 'mailboxes'
    | 'products'
    | 'other-settings';
export type CatalogRecord = {
    id: number;
    kind: CatalogKind;
    code: string;
    name: string;
    active: boolean | number;
    position: number;
    settings: Record<string, unknown>;
    /** Versioned kinds (other settings) require expected_version on update. */
    version?: number;
};
export type CatalogField = {
    key: string;
    label: string;
    type: 'text' | 'number' | 'email' | 'textarea' | 'select' | 'lines';
    required?: boolean;
    options?: { value: string; label: string }[];
};
export type CatalogSection = {
    key: CatalogKind;
    title: string;
    addLabel: string;
    summaryLabel: string;
    fields: CatalogField[];
};
export type CatalogForm = {
    code: string;
    name: string;
    active: boolean;
    position: number;
    settings: Record<string, string>;
};

export const CATALOG_SECTIONS: CatalogSection[] = [
    {
        key: 'taxes',
        title: 'Taxes',
        addLabel: 'Add tax',
        summaryLabel: 'Rate',
        fields: [
            { key: 'rate', label: 'Rate (%)', type: 'number', required: true },
            { key: 'description', label: 'Description', type: 'textarea' },
        ],
    },
    {
        key: 'units',
        title: 'Units of measurement',
        addLabel: 'Add unit',
        summaryLabel: 'Symbol',
        fields: [
            { key: 'symbol', label: 'Symbol', type: 'text', required: true },
            {
                key: 'precision',
                label: 'Decimal places',
                type: 'number',
                required: true,
            },
        ],
    },
    {
        key: 'detail-templates',
        title: 'Contact or company details templates',
        addLabel: 'Add template',
        summaryLabel: 'Fields',
        fields: [
            {
                key: 'entity',
                label: 'Applies to',
                type: 'select',
                required: true,
                options: [
                    { value: 'contact', label: 'Contact' },
                    { value: 'company', label: 'Company' },
                ],
            },
            {
                key: 'fields',
                label: 'Field keys (one per line)',
                type: 'lines',
                required: true,
            },
        ],
    },
    {
        key: 'company-details',
        title: 'My company details',
        addLabel: 'Add company details',
        summaryLabel: 'Legal name',
        fields: [
            {
                key: 'legal_name',
                label: 'Legal name',
                type: 'text',
                required: true,
            },
            { key: 'address', label: 'Address', type: 'textarea' },
            { key: 'phone', label: 'Phone', type: 'text' },
            { key: 'email', label: 'Email', type: 'email' },
            {
                key: 'tax_registration_number',
                label: 'Tax registration number',
                type: 'text',
            },
        ],
    },
    {
        key: 'mailboxes',
        title: 'Mailboxes',
        addLabel: 'Add mailbox',
        summaryLabel: 'Email',
        fields: [
            { key: 'email', label: 'Email', type: 'email', required: true },
            { key: 'display_name', label: 'Display name', type: 'text' },
            { key: 'reply_to', label: 'Reply-to', type: 'email' },
        ],
    },
    {
        key: 'other-settings',
        title: 'Other settings',
        addLabel: 'Add setting',
        summaryLabel: 'Value',
        fields: [
            { key: 'value', label: 'Value', type: 'text', required: true },
            { key: 'description', label: 'Description', type: 'textarea' },
        ],
    },
    {
        key: 'products',
        title: 'Products',
        addLabel: 'Add product',
        summaryLabel: 'Price',
        fields: [
            { key: 'sku', label: 'SKU', type: 'text' },
            { key: 'price', label: 'Price', type: 'number', required: true },
            {
                key: 'currency',
                label: 'Currency',
                type: 'text',
                required: true,
            },
            { key: 'unit_id', label: 'Unit', type: 'select' },
            { key: 'tax_id', label: 'Tax', type: 'select' },
        ],
    },
];

export function findCatalogSection(key: string): CatalogSection | null {
    return CATALOG_SECTIONS.find((section) => section.key === key) ?? null;
}

export function isActive(row: { active: boolean | number }): boolean {
    return row.active === true || row.active === 1;
}

/** Codes are lowercase identifiers fixed at creation. */
export function catalogCode(name: string): string {
    const code = name
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return /^[a-z]/.test(code) ? code.slice(0, 100) : '';
}

function textOf(value: unknown): string {
    if (Array.isArray(value)) {
        return value.map(textOf).join('\n');
    }

    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

export function formFor(
    section: CatalogSection,
    record?: CatalogRecord,
): CatalogForm {
    const settings: Record<string, string> = {};
    for (const field of section.fields) {
        const fallback =
            field.key === 'currency'
                ? 'AED'
                : field.key === 'entity'
                  ? 'contact'
                  : field.key === 'precision'
                    ? '2'
                    : '';
        settings[field.key] = record
            ? textOf(record.settings[field.key])
            : fallback;
    }

    return {
        code: record?.code ?? '',
        name: record?.name ?? '',
        active: record ? isActive(record) : true,
        position: record?.position ?? 100,
        settings,
    };
}

/** Request body: blank optional fields become null, numbers become numbers, lines become a list. */
export function buildPayload(
    section: CatalogSection,
    form: CatalogForm,
    editingCode?: string,
): Record<string, unknown> {
    const settings: Record<string, unknown> = {};
    for (const field of section.fields) {
        // Number inputs hand back numbers, not strings, so normalise first.
        const raw = String(form.settings[field.key] ?? '');
        if (field.type === 'lines') {
            settings[field.key] = raw
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean);
        } else if (raw.trim() === '') {
            settings[field.key] = field.required ? raw : null;
        } else if (field.type === 'number' || field.key.endsWith('_id')) {
            settings[field.key] = Number(raw);
        } else {
            settings[field.key] = raw.trim();
        }
    }

    return {
        code: editingCode ?? (form.code || catalogCode(form.name)),
        name: form.name.trim(),
        active: form.active,
        position: Number(form.position) || 0,
        settings,
    };
}

/** One-line description of a record for the table. */
export function catalogSummary(
    section: CatalogSection,
    record: CatalogRecord,
): string {
    const s = record.settings;
    switch (section.key) {
        case 'taxes':
            return `${textOf(s.rate)}%`;
        case 'units':
            return textOf(s.symbol);
        case 'detail-templates':
            return `${textOf(s.entity)}: ${Array.isArray(s.fields) ? s.fields.length : 0}`;
        case 'company-details':
            return textOf(s.legal_name);
        case 'mailboxes':
            return textOf(s.email);
        case 'other-settings':
            return textOf(s.value);
        default:
            return `${textOf(s.currency)} ${textOf(s.price)}`.trim();
    }
}
