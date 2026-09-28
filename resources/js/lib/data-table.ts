export type RowKey = string | number;

export type SortDirection = 'asc' | 'desc';

export type SortState = { key: string; direction: SortDirection } | null;

export type DataTableColumn<Row = Record<string, unknown>> = {
    key: Extract<keyof Row, string>;
    label: string;
    align?: 'start' | 'end';
    sortable?: boolean;
    class?: string;
};

export function nextSort(current: SortState, key: string): SortState {
    if (!current || current.key !== key) {
        return { key, direction: 'asc' };
    }

    return current.direction === 'asc' ? { key, direction: 'desc' } : null;
}

export function ariaSort(
    current: SortState,
    key: string,
): 'ascending' | 'descending' | 'none' {
    if (!current || current.key !== key) {
        return 'none';
    }

    return current.direction === 'asc' ? 'ascending' : 'descending';
}

export function toggleOne(selected: RowKey[], key: RowKey): RowKey[] {
    return selected.includes(key)
        ? selected.filter((item) => item !== key)
        : [...selected, key];
}

export function toggleAll(selected: RowKey[], visible: RowKey[]): RowKey[] {
    const allSelected =
        visible.length > 0 && visible.every((key) => selected.includes(key));
    if (allSelected) {
        return selected.filter((key) => !visible.includes(key));
    }

    return [...new Set([...selected, ...visible])];
}

export function selectionState(
    selected: RowKey[],
    visible: RowKey[],
): boolean | 'indeterminate' {
    const count = visible.filter((key) => selected.includes(key)).length;
    if (count === 0) {
        return false;
    }

    return count === visible.length ? true : 'indeterminate';
}

export function pruneSelection(
    selected: RowKey[],
    visible: RowKey[],
): RowKey[] {
    const kept = selected.filter((key) => visible.includes(key));

    return kept.length === selected.length ? selected : kept;
}
