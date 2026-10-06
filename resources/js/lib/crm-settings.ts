export type SettingsTile = {
    key: string;
    label: string;
    icon: string;
    /** null means the page is not connected yet. */
    href: string | null;
};
export type SettingsCategory = {
    key: string;
    label: string;
    tiles: SettingsTile[];
};

export const SETTINGS_CATEGORIES: SettingsCategory[] = [
    {
        key: 'start',
        label: 'Start point',
        tiles: [
            {
                key: 'stages',
                label: 'Pipelines and stages',
                icon: 'book',
                href: '/crm/pipelines',
            },
            {
                key: 'fields',
                label: 'Lead fields',
                icon: 'list',
                href: '/crm/custom-fields',
            },
            {
                key: 'assignment',
                label: 'Assignment routing',
                icon: 'users',
                href: '/crm/assignment',
            },
            {
                key: 'hierarchy',
                label: 'Departments and CRM access',
                icon: 'building',
                href: '/crm/hierarchy',
            },
            {
                key: 'currency',
                label: 'Currency',
                icon: 'coins',
                href: '/crm/settings/reference/currency',
            },
            {
                key: 'locations',
                label: 'Locations',
                icon: 'map',
                href: '/crm/settings/reference/locations',
            },
            {
                key: 'taxes',
                label: 'Taxes',
                icon: 'calculator',
                href: '/crm/settings/catalog/taxes',
            },
            {
                key: 'units',
                label: 'Units of measurement',
                icon: 'ruler',
                href: '/crm/settings/catalog/units',
            },
            {
                key: 'products',
                label: 'Products',
                icon: 'store',
                href: '/crm/settings/catalog/products',
            },
            {
                key: 'calendar',
                label: 'Working calendar',
                icon: 'calendar',
                href: '/crm/settings/working-calendar',
            },
            {
                key: 'lists',
                label: 'Selection lists',
                icon: 'list',
                href: '/crm/settings/lists',
            },
            {
                key: 'deal-pipelines',
                label: 'Deal pipelines',
                icon: 'handshake',
                href: '/crm/deal-pipelines',
            },
            {
                key: 'templates',
                label: 'Contact or company details templates',
                icon: 'file',
                href: '/crm/settings/catalog/detail-templates',
            },
            {
                key: 'company',
                label: 'My company details',
                icon: 'building',
                href: '/crm/settings/catalog/company-details',
            },
        ],
    },
    {
        key: 'forms',
        label: 'Form and report settings',
        tiles: [
            {
                key: 'import',
                label: 'Import leads',
                icon: 'file',
                href: '/crm/leads/import',
            },
            {
                key: 'export',
                label: 'Export leads',
                icon: 'file',
                href: '/crm/leads/export',
            },
        ],
    },
    {
        key: 'payment',
        label: 'Payment options',
        tiles: [
            {
                key: 'payment-systems',
                label: 'Payment systems',
                icon: 'card',
                href: null,
            },
        ],
    },
    {
        key: 'permissions',
        label: 'Permissions',
        tiles: [
            {
                key: 'access',
                label: 'Access permissions',
                icon: 'shield',
                href: '/crm/permissions',
            },
        ],
    },
    {
        key: 'automation',
        label: 'Automation',
        tiles: [
            {
                key: 'rules',
                label: 'Automation rules',
                icon: 'workflow',
                href: '/crm/automation',
            },
            {
                key: 'follow-up',
                label: 'Follow-up reminders',
                icon: 'workflow',
                href: '/crm/follow-up-settings',
            },
        ],
    },
    {
        key: 'email',
        label: 'Email',
        tiles: [
            {
                key: 'mailboxes',
                label: 'Mailboxes',
                icon: 'mail',
                href: '/crm/settings/catalog/mailboxes',
            },
        ],
    },
    {
        key: 'integration',
        label: 'Integration',
        tiles: [
            {
                key: 'gateway',
                label: 'Lead gateway',
                icon: 'plug',
                href: '/crm/lead-gateway',
            },
        ],
    },
    {
        key: 'market',
        label: 'Market',
        tiles: [
            {
                key: 'apps',
                label: 'CRM applications',
                icon: 'store',
                href: null,
            },
        ],
    },
    {
        key: 'numbering',
        label: 'Auto numbering template',
        tiles: [
            {
                key: 'num-documents',
                label: 'For documents',
                icon: 'file',
                href: '/crm/settings/reference/num-documents',
            },
            {
                key: 'num-invoices',
                label: 'For invoices',
                icon: 'file',
                href: '/crm/settings/reference/num-invoices',
            },
        ],
    },
    {
        key: 'other',
        label: 'Other',
        tiles: [
            {
                key: 'other-settings',
                label: 'Other settings',
                icon: 'settings',
                href: null,
            },
        ],
    },
];

export function findCategory(key: string): SettingsCategory {
    return (
        SETTINGS_CATEGORIES.find((category) => category.key === key) ??
        SETTINGS_CATEGORIES[0]
    );
}

export function connectedCount(category: SettingsCategory): number {
    return category.tiles.filter((tile) => tile.href !== null).length;
}

/** Client-side paging used by the settings tables. */
export function pageSlice<T>(rows: T[], page: number, perPage: number): T[] {
    const start = (Math.max(1, page) - 1) * perPage;

    return rows.slice(start, start + perPage);
}

export function pageCount(total: number, perPage: number): number {
    return Math.max(1, Math.ceil(total / perPage));
}
