export type NavIcon =
    | 'dashboard'
    | 'search'
    | 'notifications'
    | 'leads'
    | 'contacts'
    | 'pipelines'
    | 'report'
    | 'assignment'
    | 'hierarchy'
    | 'inventory'
    | 'listings'
    | 'people'
    | 'brokerage'
    | 'reservations'
    | 'agreements'
    | 'handovers'
    | 'compliance'
    | 'invoices'
    | 'bills'
    | 'refunds'
    | 'bank'
    | 'accounting'
    | 'performance'
    | 'statements'
    | 'operations'
    | 'maintenance'
    | 'preventive'
    | 'helpdesk'
    | 'amc'
    | 'parts'
    | 'projects'
    | 'fleet'
    | 'reports'
    | 'scheduled'
    | 'documents'
    | 'signatures'
    | 'procurement'
    | 'organization'
    | 'activity'
    | 'portal'
    | 'tokens'
    | 'settings';

export type NavChild = { label: string; href: string };

export type NavLink = {
    label: string;
    href: string;
    icon: NavIcon;
    /** Path prefix that marks this link active when it differs from href. */
    match?: string;
    children?: NavChild[];
};

export type NavGroup = {
    id: string;
    label: string;
    items: NavLink[];
    placement?: 'bottom';
};

export const NAVIGATION: NavGroup[] = [
    {
        id: 'overview',
        label: 'Overview',
        items: [
            { label: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
            { label: 'Search', href: '/search', icon: 'search' },
            {
                label: 'Notifications',
                href: '/notifications',
                icon: 'notifications',
            },
        ],
    },
    {
        id: 'crm',
        label: 'CRM',
        items: [
            { label: 'Leads', href: '/crm/leads', icon: 'leads' },
            { label: 'Contacts', href: '/crm/contacts', icon: 'contacts' },
            { label: 'Pipelines', href: '/crm/pipelines', icon: 'pipelines' },
            {
                label: 'Pipeline report',
                href: '/crm/pipeline-report',
                icon: 'report',
            },
            {
                label: 'Assignment',
                href: '/crm/assignment',
                icon: 'assignment',
            },
            { label: 'Hierarchy', href: '/crm/hierarchy', icon: 'hierarchy' },
        ],
    },
    {
        id: 'portfolio',
        label: 'Portfolio',
        items: [
            { label: 'Inventory', href: '/inventory', icon: 'inventory' },
            {
                label: 'Listings',
                href: '/real-estate/listings',
                icon: 'listings',
            },
            {
                label: 'Owners, tenants & brokers',
                href: '/real-estate/people',
                icon: 'people',
            },
            {
                label: 'Brokerage',
                href: '/real-estate/brokerage',
                icon: 'brokerage',
            },
        ],
    },
    {
        id: 'leasing',
        label: 'Leasing & sales',
        items: [
            {
                label: 'Reservations',
                href: '/reservations',
                icon: 'reservations',
            },
            { label: 'Agreements', href: '/agreements', icon: 'agreements' },
            { label: 'Handovers', href: '/handovers', icon: 'handovers' },
            {
                label: 'Lease compliance',
                href: '/lease-compliance',
                icon: 'compliance',
            },
        ],
    },
    {
        id: 'finance',
        label: 'Finance',
        items: [
            { label: 'Invoices', href: '/invoices', icon: 'invoices' },
            { label: 'Vendor bills', href: '/vendor-bills', icon: 'bills' },
            {
                label: 'Vendor refunds',
                href: '/finance/vendor-cash-refunds',
                icon: 'refunds',
            },
            {
                label: 'Bank reconciliation',
                href: '/bank-reconciliation',
                icon: 'bank',
            },
            {
                label: 'Accounting',
                href: '/accounting',
                icon: 'accounting',
                children: [
                    { label: 'Accounting overview', href: '/accounting' },
                    {
                        label: 'Journal register',
                        href: '/accounting/journal-register',
                    },
                    {
                        label: 'Financial statements',
                        href: '/accounting/statements',
                    },
                    { label: 'VAT return', href: '/accounting/vat-return' },
                    {
                        label: 'Corporate tax',
                        href: '/accounting/corporate-tax',
                    },
                    { label: 'Budgets', href: '/accounting/budgets' },
                    { label: 'Fixed assets', href: '/accounting/fixed-assets' },
                    {
                        label: 'Outstanding balances',
                        href: '/accounting/outstanding-balances',
                    },
                    { label: 'Account activity', href: '/accounting/activity' },
                    { label: 'Audit trail', href: '/accounting/audit-trail' },
                    {
                        label: 'Legacy mappings',
                        href: '/accounting/legacy-mappings',
                    },
                ],
            },
            {
                label: 'Property performance',
                href: '/reports/property-profitability',
                icon: 'performance',
            },
            {
                label: 'Owner statements',
                href: '/reports/owner-statements',
                icon: 'statements',
            },
        ],
    },
    {
        id: 'operations',
        label: 'Operations',
        items: [
            {
                label: 'Operations overview',
                href: '/operations',
                icon: 'operations',
            },
            { label: 'Maintenance', href: '/maintenance', icon: 'maintenance' },
            {
                label: 'Preventive maintenance',
                href: '/preventive-maintenance',
                icon: 'preventive',
            },
            {
                label: 'Helpdesk',
                href: '/operations/helpdesk',
                icon: 'helpdesk',
            },
            { label: 'AMC contracts', href: '/operations/amc', icon: 'amc' },
            {
                label: 'Spare parts',
                href: '/operations/spare-parts',
                icon: 'parts',
            },
            {
                label: 'Projects',
                href: '/operations/projects',
                icon: 'projects',
            },
            { label: 'Fleet', href: '/operations/fleet', icon: 'fleet' },
            {
                label: 'Operations reports',
                href: '/operations/reports',
                icon: 'reports',
            },
            {
                label: 'Scheduled reports',
                href: '/operations/scheduled-reports',
                icon: 'scheduled',
            },
            {
                label: 'Compliance documents',
                href: '/compliance-documents',
                icon: 'documents',
            },
            {
                label: 'Signatures',
                href: '/documents/signatures',
                icon: 'signatures',
            },
        ],
    },
    {
        id: 'procurement',
        label: 'Procurement',
        items: [
            {
                label: 'Procurement',
                href: '/procurement',
                icon: 'procurement',
            },
        ],
    },
    {
        id: 'administration',
        label: 'Administration',
        placement: 'bottom',
        items: [
            {
                label: 'Organization',
                href: '/organization',
                icon: 'organization',
            },
            {
                label: 'Organization activity',
                href: '/organization/activity',
                icon: 'activity',
            },
            {
                label: 'Portal access',
                href: '/organization/portal-access',
                icon: 'portal',
            },
            {
                label: 'API tokens',
                href: '/organization/api-tokens',
                icon: 'tokens',
            },
            {
                label: 'Settings',
                href: '/settings/profile',
                icon: 'settings',
                match: '/settings',
            },
        ],
    },
];

export function navHrefs(): string[] {
    return NAVIGATION.flatMap((group) =>
        group.items.flatMap((item) =>
            item.children
                ? item.children.map((child) => child.href)
                : [item.href],
        ),
    );
}

function pathOf(url: string): string {
    const path = url.split(/[?#]/)[0].replace(/\/+$/, '');

    return path === '' ? '/' : path;
}

function matches(href: string, path: string): boolean {
    return path === href || path.startsWith(`${href}/`);
}

function candidates(): { href: string; prefix: string }[] {
    return NAVIGATION.flatMap((group) =>
        group.items.flatMap((item) =>
            item.children
                ? item.children.map((child) => ({
                      href: child.href,
                      prefix: child.href,
                  }))
                : [{ href: item.href, prefix: item.match ?? item.href }],
        ),
    );
}

export function activeHref(url: string): string | null {
    const path = pathOf(url);
    let best: { href: string; prefix: string } | null = null;
    for (const candidate of candidates()) {
        if (
            matches(candidate.prefix, path) &&
            (best === null || candidate.prefix.length > best.prefix.length)
        ) {
            best = candidate;
        }
    }

    return best?.href ?? null;
}

export function activeGroupId(url: string): string | null {
    const href = activeHref(url);
    if (href === null) {
        return null;
    }
    const group = NAVIGATION.find((candidate) =>
        candidate.items.some(
            (item) =>
                item.href === href ||
                (item.children ?? []).some((child) => child.href === href),
        ),
    );

    return group?.id ?? null;
}
