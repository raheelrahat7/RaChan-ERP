import type {
    PermissionGroup,
    PermissionRole,
    PermissionValues,
} from './crm-permissions';

export type PrincipalType =
    | 'role'
    | 'user'
    | 'team'
    | 'subdepartment'
    | 'department';
export type Principal = { type: PrincipalType; id: string };
export type AccessRule = {
    pipeline_id: number;
    principal_type: PrincipalType;
    principal_id: string | number;
    permissions: Record<string, string> | null;
};
export type SectionRule = {
    principal_type: PrincipalType;
    principal_id: string | number;
    permission: string;
    enabled: boolean | number;
};
export type Named = { id: number; name: string };
export type Lookups = {
    members: Named[];
    teams: Named[];
    subdepartments: Named[];
    departments: Named[];
};
export type Pipeline = { id: number; name: string };

export const DEAL_ACTIONS: { key: string; label: string }[] = [
    { key: 'read', label: 'Read' },
    { key: 'add', label: 'Add' },
    { key: 'edit', label: 'Edit' },
    { key: 'move', label: 'Move to stage' },
    { key: 'transfer', label: 'Transfer to another pipeline' },
    { key: 'assign', label: 'Change responsible person' },
    { key: 'export', label: 'Export' },
    { key: 'amount', label: 'See amounts' },
];

export const SCOPE_LEVELS = [
    { value: 'none', label: 'No access' },
    { value: 'own', label: 'Their own deals' },
    { value: 'team', label: 'Their team' },
    { value: 'subdepartment', label: 'Their sub-department' },
    { value: 'department', label: 'Their department' },
    { value: 'organization', label: 'Everyone' },
];

/** Roles that already have organization-wide access, so a rule for them changes nothing. */
const ALWAYS_FULL = ['owner', 'administrator'];
const DEFAULT_ROLES = ['manager', 'member', 'viewer'];

export function principalKey(type: string, id: string | number): string {
    return `${type}:${id}`;
}

export function principalLabel(principal: Principal, lookups: Lookups): string {
    const list = {
        user: lookups.members,
        team: lookups.teams,
        subdepartment: lookups.subdepartments,
        department: lookups.departments,
    } as Record<string, Named[]>;
    if (principal.type === 'role') {
        return principal.id.charAt(0).toUpperCase() + principal.id.slice(1);
    }
    const name =
        list[principal.type]?.find((item) => String(item.id) === principal.id)
            ?.name ?? `#${principal.id}`;
    const prefix = {
        user: '',
        team: 'Team: ',
        subdepartment: 'Sub-department: ',
        department: 'Department: ',
    }[principal.type];

    return `${prefix}${name}`;
}

/** Columns: the default roles plus every principal already named in a rule. */
export function collectPrincipals(
    rules: { principal_type: string; principal_id: string | number }[],
    extra: Principal[] = [],
): Principal[] {
    const seen = new Map<string, Principal>();
    const add = (principal: Principal): void => {
        if (
            !(principal.type === 'role' && ALWAYS_FULL.includes(principal.id))
        ) {
            seen.set(principalKey(principal.type, principal.id), principal);
        }
    };
    DEFAULT_ROLES.forEach((id) => add({ type: 'role', id }));
    rules.forEach((rule) =>
        add({
            type: rule.principal_type as PrincipalType,
            id: String(rule.principal_id),
        }),
    );
    extra.forEach(add);

    return [...seen.values()];
}

export function toRoles(
    principals: Principal[],
    lookups: Lookups,
): PermissionRole[] {
    return principals.map((principal, index) => ({
        id: index + 1,
        name: principalLabel(principal, lookups),
        members:
            principal.type === 'user'
                ? lookups.members.filter(
                      (member) => String(member.id) === principal.id,
                  )
                : [],
    }));
}

export function dealGroups(pipelines: Pipeline[]): PermissionGroup[] {
    return pipelines.map((pipeline) => ({
        key: `p${pipeline.id}`,
        label: `Deal pipeline: ${pipeline.name}`,
        rows: DEAL_ACTIONS.map((action) => ({
            key: action.key,
            label: action.label,
            kind: 'level' as const,
        })),
    }));
}

export function dealValues(
    pipelines: Pipeline[],
    principals: Principal[],
    rules: AccessRule[],
): PermissionValues {
    const values: PermissionValues = {};
    principals.forEach((principal, index) => {
        values[index + 1] = {};
        for (const pipeline of pipelines) {
            const rule = rules.find(
                (item) =>
                    item.pipeline_id === pipeline.id &&
                    principalKey(item.principal_type, item.principal_id) ===
                        principalKey(principal.type, principal.id),
            );
            for (const action of DEAL_ACTIONS) {
                values[index + 1][`p${pipeline.id}.${action.key}`] =
                    rule?.permissions?.[action.key] ?? 'none';
            }
        }
    });

    return values;
}

export type AccessRequest = {
    pipelineId: number;
    body: {
        principal_type: PrincipalType;
        principal_id: string;
        permissions: Record<string, string>;
    };
};

/** One request per pipeline and principal whose cells changed, carrying the full action set. */
export function accessRequests(
    pipelines: Pipeline[],
    principals: Principal[],
    before: PermissionValues,
    after: PermissionValues,
): AccessRequest[] {
    const requests: AccessRequest[] = [];
    principals.forEach((principal, index) => {
        for (const pipeline of pipelines) {
            const changed = DEAL_ACTIONS.some((action) => {
                const key = `p${pipeline.id}.${action.key}`;

                return (
                    (before[index + 1]?.[key] ?? 'none') !==
                    (after[index + 1]?.[key] ?? 'none')
                );
            });
            if (changed) {
                requests.push({
                    pipelineId: pipeline.id,
                    body: {
                        principal_type: principal.type,
                        principal_id: principal.id,
                        permissions: Object.fromEntries(
                            DEAL_ACTIONS.map((action) => [
                                action.key,
                                String(
                                    after[index + 1]?.[
                                        `p${pipeline.id}.${action.key}`
                                    ] ?? 'none',
                                ),
                            ]),
                        ),
                    },
                });
            }
        }
    });

    return requests;
}

export function sectionGroups(permissions: string[]): PermissionGroup[] {
    return [
        {
            key: 'sections',
            label: 'CRM sections',
            rows: permissions.map((permission) => ({
                key: permission,
                label: permission
                    .replaceAll('_', ' ')
                    .replace(/^./, (c) => c.toUpperCase()),
                kind: 'toggle' as const,
            })),
        },
    ];
}

/** A section is on unless a rule switched it off for that principal. */
export function sectionValues(
    permissions: string[],
    principals: Principal[],
    rules: SectionRule[],
): PermissionValues {
    const values: PermissionValues = {};
    principals.forEach((principal, index) => {
        values[index + 1] = {};
        for (const permission of permissions) {
            const rule = rules.find(
                (item) =>
                    item.permission === permission &&
                    principalKey(item.principal_type, item.principal_id) ===
                        principalKey(principal.type, principal.id),
            );
            values[index + 1][`sections.${permission}`] = rule
                ? Boolean(rule.enabled)
                : true;
        }
    });

    return values;
}

export type SectionRequest = {
    principal_type: PrincipalType;
    principal_id: string;
    permission: string;
    enabled: boolean;
};

export function sectionRequests(
    permissions: string[],
    principals: Principal[],
    before: PermissionValues,
    after: PermissionValues,
): SectionRequest[] {
    const requests: SectionRequest[] = [];
    principals.forEach((principal, index) => {
        for (const permission of permissions) {
            const key = `sections.${permission}`;
            const was = before[index + 1]?.[key] ?? true;
            const now = after[index + 1]?.[key] ?? true;
            if (was !== now) {
                requests.push({
                    principal_type: principal.type,
                    principal_id: principal.id,
                    permission,
                    enabled: Boolean(now),
                });
            }
        }
    });

    return requests;
}
