export type PermissionValue = string | boolean;
export type PermissionRow = {
    key: string;
    label: string;
    kind: 'level' | 'toggle';
    hint?: string;
};
export type PermissionGroup = {
    key: string;
    label: string;
    rows: PermissionRow[];
};
export type PermissionRole = {
    id: number;
    name: string;
    members: { id: number; name: string }[];
};
export type PermissionValues = Record<number, Record<string, PermissionValue>>;

export const LEVELS = [
    { value: 'deny', label: 'Deny access' },
    { value: 'own', label: 'Their own items' },
    { value: 'department', label: 'Their department' },
    { value: 'all', label: 'All' },
] as const;

export function cellKey(group: string, row: string): string {
    return `${group}.${row}`;
}

export function levelLabel(value: PermissionValue | undefined): string {
    return (
        LEVELS.find((level) => level.value === value)?.label ?? 'Deny access'
    );
}

export function getCell(
    values: PermissionValues,
    roleId: number,
    key: string,
    kind: PermissionRow['kind'],
): PermissionValue {
    return values[roleId]?.[key] ?? (kind === 'toggle' ? false : 'deny');
}

/** Returns new values with one cell changed; the input is left untouched. */
export function setCell(
    values: PermissionValues,
    roleId: number,
    key: string,
    value: PermissionValue,
): PermissionValues {
    return { ...values, [roleId]: { ...values[roleId], [key]: value } };
}

/** Number of cells whose effective value differs between two snapshots. */
export function countChanges(
    groups: PermissionGroup[],
    roles: PermissionRole[],
    before: PermissionValues,
    after: PermissionValues,
): number {
    let changes = 0;
    for (const role of roles) {
        for (const group of groups) {
            for (const row of group.rows) {
                const key = cellKey(group.key, row.key);
                if (
                    getCell(before, role.id, key, row.kind) !==
                    getCell(after, role.id, key, row.kind)
                ) {
                    changes++;
                }
            }
        }
    }

    return changes;
}

/** Keeps groups whose label or rows match; a group label match keeps all its rows. */
export function filterGroups(
    groups: PermissionGroup[],
    query: string,
): PermissionGroup[] {
    const needle = query.trim().toLowerCase();
    if (!needle) {
        return groups;
    }

    return groups
        .map((group) =>
            group.label.toLowerCase().includes(needle)
                ? group
                : {
                      ...group,
                      rows: group.rows.filter((row) =>
                          row.label.toLowerCase().includes(needle),
                      ),
                  },
        )
        .filter((group) => group.rows.length > 0);
}

export function toggleCollapsed(collapsed: string[], key: string): string[] {
    return collapsed.includes(key)
        ? collapsed.filter((item) => item !== key)
        : [...collapsed, key];
}
