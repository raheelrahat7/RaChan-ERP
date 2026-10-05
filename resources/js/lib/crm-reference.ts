export type ReferenceKind = 'currencies' | 'locations' | 'numbering';
export type ReferenceSection = {
    key: string;
    kind: ReferenceKind;
    title: string;
    addLabel: string;
    /** Numbering sections are pinned to one template kind. */
    numberingKind?: 'document' | 'invoice';
};
export type ReferenceRecord = Record<string, unknown> & {
    id: number;
    active: boolean | number;
};

export const REFERENCE_SECTIONS: ReferenceSection[] = [
    {
        key: 'currency',
        kind: 'currencies',
        title: 'Currency',
        addLabel: 'Add currency',
    },
    {
        key: 'locations',
        kind: 'locations',
        title: 'Locations',
        addLabel: 'Add location',
    },
    {
        key: 'num-documents',
        kind: 'numbering',
        title: 'Auto numbering for documents',
        addLabel: 'Add template',
        numberingKind: 'document',
    },
    {
        key: 'num-invoices',
        kind: 'numbering',
        title: 'Auto numbering for invoices',
        addLabel: 'Add template',
        numberingKind: 'invoice',
    },
];

export function findReferenceSection(key: string): ReferenceSection | null {
    return REFERENCE_SECTIONS.find((section) => section.key === key) ?? null;
}

/** MySQL booleans arrive as 0/1. */
export function isActive(row: { active: boolean | number }): boolean {
    return row.active === true || row.active === 1;
}

/** Records belonging to a section (numbering is split by template kind). */
export function sectionRows(
    section: ReferenceSection,
    records: ReferenceRecord[],
): ReferenceRecord[] {
    return section.numberingKind
        ? records.filter((row) => row.kind === section.numberingKind)
        : records;
}

/** Fields the endpoint requires, filled from a record or sensible defaults. */
export function formDefaults(
    section: ReferenceSection,
    record?: ReferenceRecord,
): Record<string, string | number | boolean | null> {
    const flag = (key: string, fallback: boolean): boolean =>
        record ? record[key] === true || record[key] === 1 : fallback;
    const text = (key: string, fallback = ''): string => {
        const value = record?.[key];

        return typeof value === 'string' || typeof value === 'number'
            ? String(value)
            : fallback;
    };

    if (section.kind === 'currencies') {
        return {
            code: text('code'),
            name: text('name'),
            exchange_rate: text('exchange_rate', '1'),
            face_value: Number(text('face_value', '1')),
            is_base: flag('is_base', false),
            is_reporting: flag('is_reporting', false),
            active: flag('active', true),
        };
    }
    if (section.kind === 'locations') {
        return {
            name: text('name'),
            type: text('type', 'country'),
            parent_id:
                record?.parent_id != null ? Number(record.parent_id) : null,
            active: flag('active', true),
        };
    }

    return {
        kind: section.numberingKind ?? text('kind', 'document'),
        prefix: text('prefix'),
        padding: Number(text('padding', '5')),
        next_number: Number(text('next_number', '1')),
        include_year: flag('include_year', false),
        active: flag('active', true),
    };
}

const PARENT_TYPE = { region: 'country', city: 'region' } as const;

/** Active locations that may be the parent of a location of this type. */
export function parentOptions(
    locations: ReferenceRecord[],
    type: string,
): ReferenceRecord[] {
    const wanted = PARENT_TYPE[type as keyof typeof PARENT_TYPE];

    return wanted
        ? locations.filter((row) => row.type === wanted && isActive(row))
        : [];
}

/** Preview of the next generated number, e.g. INV-2026-00042. */
export function numberPreview(
    prefix: string,
    padding: number,
    next: number,
    includeYear: boolean,
    year: number,
): string {
    const digits = String(Math.max(0, next)).padStart(
        Math.min(12, Math.max(1, padding)),
        '0',
    );

    return [prefix, includeYear ? String(year) : '', digits]
        .filter((part) => part !== '')
        .join('-');
}
