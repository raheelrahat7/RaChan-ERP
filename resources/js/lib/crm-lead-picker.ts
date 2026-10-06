export type QualifiedLead = {
    id: number;
    name: string;
    stage: { id: number; name: string } | null;
    assignee: { id: number; name: string } | null;
    amount: string | number | null;
    permissions: { read: boolean; edit: boolean };
};

/** The picker only has a display name, so the last word becomes the last name. */
export function splitName(name: string): {
    first_name: string;
    last_name: string;
} {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length <= 1) {
        return { first_name: parts[0] ?? '', last_name: '' };
    }

    return {
        first_name: parts.slice(0, -1).join(' '),
        last_name: parts[parts.length - 1],
    };
}
