export type SelectionOption = {
    id: number;
    list_key: string;
    code: string | null;
    name: string;
    position: number;
    active: boolean | number;
};

/** Lists the backend accepts, in the order they appear on the page. */
export const SELECTION_LISTS: { key: string; label: string }[] = [
    { key: 'sources', label: 'Lead sources' },
    { key: 'deal_categories', label: 'Deal categories' },
    { key: 'deal_types', label: 'Deal types' },
    { key: 'contact_types', label: 'Contact types' },
    { key: 'company_types', label: 'Company types' },
    { key: 'company_sizes', label: 'Company sizes' },
    { key: 'industries', label: 'Industries' },
    { key: 'salutations', label: 'Salutations' },
    { key: 'call_statuses', label: 'Call statuses' },
    { key: 'deal_statuses', label: 'Deal statuses' },
    { key: 'deal_scenarios', label: 'Deal scenarios' },
];

/** Lists whose options carry a permanent code and start from built-in defaults. */
export const CODED_LISTS = [
    'deal_categories',
    'deal_statuses',
    'deal_scenarios',
];

export function listLabel(key: string): string {
    return SELECTION_LISTS.find((list) => list.key === key)?.label ?? key;
}

export function optionsFor(
    list: string,
    options: SelectionOption[],
): SelectionOption[] {
    return options
        .filter((option) => option.list_key === list)
        .sort((a, b) => a.position - b.position || a.id - b.id);
}

export function nextPosition(options: SelectionOption[]): number {
    return (
        options.reduce((max, option) => Math.max(max, option.position), 0) + 1
    );
}

/** Category codes are lowercase identifiers that cannot change once saved. */
export function codeFromName(name: string): string {
    const code = name
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    return /^[a-z]/.test(code) ? code.slice(0, 24) : '';
}
