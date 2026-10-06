export type Choice = { value: string; label: string; active: boolean };
export type ChoiceMap = Record<string, Choice[]>;
export type RequirementData = {
    type: string | null;
    purpose: string | null;
    unit_category: string | null;
    emirate: string | null;
    property_type: string | null;
    furnishing: string | null;
    rent_frequency: string | null;
    timeline: string | null;
    completion_status: string | null;
    payment_method: string | null;
    financing_status: string | null;
    language: string | null;
    amenities: string[];
    temperature: string | null;
    size_min: string | null;
    size_max: string | null;
    budget_min: string | null;
    budget_max: string | null;
    down_payment_percent: string | null;
    roi_percent: string | null;
    bedrooms_min: number | null;
    bedrooms_max: number | null;
    bathrooms_min: number | null;
    lead_score: number | null;
    location: string | null;
    size_unit: string | null;
    budget_currency: string | null;
    handover_on: string | null;
    preferences: string | null;
};
export type Requirement = {
    id: number | null;
    lead_id: number;
    version: number;
    data: RequirementData;
    permissions: { read: boolean; edit: boolean };
};
export type RequirementConfiguration = {
    id: number | null;
    version: number;
    choices: ChoiceMap;
    permissions: { read: boolean; edit: boolean };
};
/** Form values: everything is text except the amenities list. */
export type RequirementDraft = Record<string, string | string[]>;

export const CHOICE_FIELDS = [
    'type',
    'purpose',
    'unit_category',
    'emirate',
    'property_type',
    'furnishing',
    'rent_frequency',
    'timeline',
    'completion_status',
    'payment_method',
    'financing_status',
    'language',
    'amenities',
    'temperature',
] as const;
export const INTEGER_FIELDS = [
    'bedrooms_min',
    'bedrooms_max',
    'bathrooms_min',
    'lead_score',
];
export const DECIMAL_FIELDS = [
    'size_min',
    'size_max',
    'budget_min',
    'budget_max',
    'down_payment_percent',
    'roi_percent',
];
export const TEXT_FIELDS = [
    'location',
    'size_unit',
    'budget_currency',
    'handover_on',
    'preferences',
];
const SCALAR_CHOICE_FIELDS = CHOICE_FIELDS.filter(
    (field) => field !== 'amenities',
);

export const FIELD_LABELS: Record<string, string> = {
    type: 'Requirement type',
    purpose: 'Purpose',
    unit_category: 'Unit category',
    emirate: 'Emirate',
    property_type: 'Property type',
    furnishing: 'Furnishing preference',
    rent_frequency: 'Rent frequency',
    timeline: 'Timeline',
    completion_status: 'Ready / off-plan',
    payment_method: 'Payment method',
    financing_status: 'Financing status',
    language: 'Preferred language',
    amenities: 'Amenities',
    temperature: 'Temperature',
};

export function draftFrom(data: RequirementData): RequirementDraft {
    const draft: RequirementDraft = {};
    for (const [key, value] of Object.entries(data)) {
        draft[key] = Array.isArray(value)
            ? value.map(String)
            : value === null || value === undefined
              ? ''
              : String(value);
    }

    return draft;
}

function coerce(key: string, raw: string | string[]): unknown {
    if (key === 'amenities') {
        return Array.isArray(raw) ? raw : [];
    }
    const text = String(raw).trim();
    if (text === '') {
        return null;
    }
    if (INTEGER_FIELDS.includes(key)) {
        return Number(text);
    }
    if (key === 'budget_currency') {
        return text.toUpperCase();
    }

    return text;
}

/** Only the keys that changed, so a save never rewrites untouched values. */
export function payloadFrom(
    original: RequirementData,
    draft: RequirementDraft,
): Record<string, unknown> {
    const before = draftFrom(original);
    const changes: Record<string, unknown> = {};
    for (const key of Object.keys(before)) {
        if (!(key in draft)) {
            continue;
        }
        const next = draft[key];
        const normalised = Array.isArray(next) ? next : String(next).trim();
        if (JSON.stringify(normalised) !== JSON.stringify(before[key])) {
            changes[key] = coerce(key, next);
        }
    }

    return changes;
}

/** Active choices plus an archived one that is already selected, so it stays visible. */
export function selectableChoices(
    choices: Choice[] | undefined,
    current: string | string[] | undefined,
): Choice[] {
    const selected = Array.isArray(current)
        ? current
        : current
          ? [current]
          : [];

    return (choices ?? []).filter(
        (choice) => choice.active || selected.includes(choice.value),
    );
}

export function choiceLabel(
    choices: Choice[] | undefined,
    value: string | null | undefined,
): string {
    if (!value) {
        return '—';
    }

    return choices?.find((choice) => choice.value === value)?.label ?? value;
}

/** Server keys look like "data.budget_max"; the form wants the bare field name. */
export function fieldErrors(
    errors: Record<string, string>,
): Record<string, string> {
    const mapped: Record<string, string> = {};
    for (const [key, message] of Object.entries(errors)) {
        mapped[key.replace(/^data\./, '').replace(/\.\d+$/, '')] ??= message;
    }

    return mapped;
}

export function isScalarChoice(field: string): boolean {
    return (SCALAR_CHOICE_FIELDS as readonly string[]).includes(field);
}

/** A short line for the tab header, e.g. "Buyer · hot · AED 1.5M–2.5M". */
export function requirementSummary(
    data: RequirementData,
    choices: ChoiceMap,
): string {
    const parts = [
        choiceLabel(choices.type, data.type),
        choiceLabel(choices.temperature, data.temperature),
    ].filter((part) => part !== '—');
    if (data.budget_min || data.budget_max) {
        parts.push(
            `${data.budget_currency ?? ''} ${data.budget_min ?? ''}–${data.budget_max ?? ''}`.trim(),
        );
    }

    return parts.join(' · ');
}

export type ChoiceDraft = { value: string; label: string; active: boolean };

/** Lowercase code from a label, matching the server's ^[a-z][a-z0-9_]*$ rule. */
export function choiceCode(label: string): string {
    const code = label
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    return /^[a-z]/.test(code) ? code.slice(0, 60) : '';
}

export function moveChoice(
    list: ChoiceDraft[],
    index: number,
    direction: -1 | 1,
): ChoiceDraft[] {
    const target = index + direction;
    if (target < 0 || target >= list.length) {
        return list;
    }
    const next = [...list];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
}
