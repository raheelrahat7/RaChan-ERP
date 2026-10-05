export type FilterClause = {
    field: string;
    operator: string;
    value: string;
    to?: string;
};
export type FilterState = {
    q: string;
    assignee_id: number | null;
    filters: FilterClause[];
};
export type FilterPreset = {
    id: string;
    name: string;
    builtin: boolean;
    state: FilterState;
};
type FieldLike = { key: string; group: string };
type StorageLike = Pick<Storage, 'getItem' | 'setItem'>;

const FIELDS_KEY = 'crm-lead-filter-fields';
const PRESETS_KEY = 'crm-lead-filter-presets';

function read<T>(storage: StorageLike | null, key: string): T | null {
    try {
        const raw = storage?.getItem(key);

        return raw ? (JSON.parse(raw) as T) : null;
    } catch {
        return null;
    }
}

function write(storage: StorageLike | null, key: string, value: unknown): void {
    try {
        storage?.setItem(key, JSON.stringify(value));
    } catch {
        // Storage can be unavailable (private mode); preferences are optional.
    }
}

/** Fields shown in the filter panel; null means "use defaults". */
export function loadFieldSelection(
    storage: StorageLike | null,
): string[] | null {
    const saved = read<unknown>(storage, FIELDS_KEY);

    return Array.isArray(saved) && saved.every((key) => typeof key === 'string')
        ? saved
        : null;
}

export function saveFieldSelection(
    storage: StorageLike | null,
    keys: string[] | null,
): void {
    write(storage, FIELDS_KEY, keys);
}

export function defaultFieldKeys(catalog: FieldLike[]): string[] {
    return catalog.slice(0, 12).map((field) => field.key);
}

/** Drops saved keys that no longer exist in the catalog, preserving catalog order. */
export function visibleFieldKeys(
    catalog: FieldLike[],
    selection: string[] | null,
): string[] {
    if (selection === null) {
        return defaultFieldKeys(catalog);
    }
    const chosen = new Set(selection);

    return catalog
        .filter((field) => chosen.has(field.key))
        .map((field) => field.key);
}

export function builtinPresets(userId: number | null): FilterPreset[] {
    const empty: FilterState = { q: '', assignee_id: null, filters: [] };
    const presets: FilterPreset[] = [
        { id: 'all', name: 'All leads', builtin: true, state: empty },
    ];
    if (userId !== null) {
        presets.push({
            id: 'mine',
            name: 'My leads',
            builtin: true,
            state: { ...empty, assignee_id: userId },
        });
    }

    return presets;
}

export function loadPresets(storage: StorageLike | null): FilterPreset[] {
    const saved = read<unknown>(storage, PRESETS_KEY);
    if (!Array.isArray(saved)) {
        return [];
    }

    return saved.filter(
        (item): item is FilterPreset =>
            typeof item?.id === 'string' &&
            typeof item?.name === 'string' &&
            Array.isArray(item?.state?.filters),
    );
}

export function savePreset(
    storage: StorageLike | null,
    name: string,
    state: FilterState,
): FilterPreset[] {
    const trimmed = name.trim().slice(0, 60);
    const existing = loadPresets(storage);
    if (!trimmed) {
        return existing;
    }
    const preset: FilterPreset = {
        id: `p-${trimmed.toLowerCase()}`,
        name: trimmed,
        builtin: false,
        state: structuredClone(state),
    };
    const next = [...existing.filter((item) => item.id !== preset.id), preset];
    write(storage, PRESETS_KEY, next);

    return next;
}

export function deletePreset(
    storage: StorageLike | null,
    id: string,
): FilterPreset[] {
    const next = loadPresets(storage).filter((item) => item.id !== id);
    write(storage, PRESETS_KEY, next);

    return next;
}
