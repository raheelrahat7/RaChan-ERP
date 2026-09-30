export type NavIcon =
    | 'home'
    | 'search'
    | 'notifications'
    | 'chat'
    | 'leads'
    | 'contacts'
    | 'matchmaker'
    | 'listings'
    | 'inventory'
    | 'owners'
    | 'offPlan'
    | 'secondary'
    | 'reservations'
    | 'deals'
    | 'handovers'
    | 'leasing'
    | 'commission'
    | 'allocation'
    | 'performance'
    | 'marketing'
    | 'portalListings'
    | 'portalSubscriptions'
    | 'portalInvoicing'
    | 'costing'
    | 'accounting'
    | 'cheques'
    | 'receivables'
    | 'payables'
    | 'refunds'
    | 'bank'
    | 'propertyPerformance'
    | 'ownerStatements'
    | 'procurement'
    | 'maintenance'
    | 'preventive'
    | 'helpdesk'
    | 'amc'
    | 'parts'
    | 'projects'
    | 'fleet'
    | 'operations'
    | 'reports'
    | 'scheduled'
    | 'approvals'
    | 'tasks'
    | 'viewings'
    | 'signatures'
    | 'complianceDocuments'
    | 'hr'
    | 'reportCentre'
    | 'gaim'
    | 'bulletins'
    | 'leadGateway'
    | 'followUp'
    | 'pipelines'
    | 'hierarchy'
    | 'organization'
    | 'settings';

export type NavBadge = 'notifications_unread' | 'crm_open_leads';

export type NavChild = { label: string; href: string };

export type NavLink = {
    label: string;
    icon: NavIcon;
    /** Absent for items that are not built yet. */
    href?: string;
    soon?: boolean;
    /** Key in the shared `abilities` prop; `false` hides the item. */
    ability?: string;
    badge?: NavBadge;
    /** Path prefix that marks this link active when it differs from href. */
    match?: string;
    children?: NavChild[];
};

export type NavGroup = { id: string; label: string; items: NavLink[] };

export type CommandEntry = {
    label: string;
    href: string;
    section: string;
    icon: NavIcon;
};

const soon = (label: string, icon: NavIcon, ability?: string): NavLink => ({
    label,
    icon,
    soon: true,
    ...(ability ? { ability } : {}),
});

export const NAVIGATION: NavGroup[] = [
    {
        id: 'overview',
        label: 'Overview',
        items: [
            { label: 'Home', href: '/dashboard', icon: 'home' },
            { label: 'Search', href: '/search', icon: 'search' },
            {
                label: 'Notifications',
                href: '/notifications',
                icon: 'notifications',
                badge: 'notifications_unread',
            },
            {
                label: 'Company chat',
                href: '/chat',
                icon: 'chat',
                ability: 'chat',
            },
        ],
    },
    {
        id: 'sales',
        label: 'Sales & CRM',
        items: [
            {
                label: 'CRM & Leads',
                href: '/crm/leads',
                icon: 'leads',
                ability: 'crm',
                badge: 'crm_open_leads',
            },
            {
                label: 'Contacts',
                href: '/crm/contacts',
                icon: 'contacts',
                ability: 'crm',
            },
            {
                label: 'AI Matchmaker',
                href: '/crm/matchmaker',
                icon: 'matchmaker',
                ability: 'crm',
            },
            {
                label: 'Property & Listings',
                href: '/real-estate/listings',
                icon: 'listings',
                ability: 'listings',
            },
            {
                label: 'Property Inventory',
                href: '/inventory',
                icon: 'inventory',
                ability: 'listings',
            },
            {
                label: 'Owners & Developers',
                href: '/real-estate/people',
                icon: 'owners',
                ability: 'listings',
            },
            {
                label: 'Off-Plan Projects',
                href: '/real-estate/off-plan',
                icon: 'offPlan',
                ability: 'crm',
            },
            {
                label: 'Secondary Market',
                href: '/real-estate/secondary-market',
                icon: 'secondary',
                ability: 'listings',
            },
            {
                label: 'Reservations',
                href: '/reservations',
                icon: 'reservations',
                ability: 'deals',
            },
            {
                label: 'Deal Management',
                href: '/agreements',
                icon: 'deals',
                ability: 'deals',
            },
            {
                label: 'Handovers',
                href: '/handovers',
                icon: 'handovers',
                ability: 'deals',
            },
            {
                label: 'Leasing & Rental',
                href: '/lease-compliance',
                icon: 'leasing',
                ability: 'leasing',
            },
            {
                label: 'Agents & Commission',
                href: '/real-estate/brokerage',
                icon: 'commission',
                ability: 'commission',
            },
            {
                label: 'Broker Allocation',
                href: '/crm/assignment',
                icon: 'allocation',
                ability: 'crm',
            },
            {
                label: 'Broker Performance',
                href: '/crm/broker-performance',
                icon: 'performance',
                ability: 'crm',
            },
        ],
    },
    {
        id: 'marketing',
        label: 'Marketing',
        items: [
            {
                label: 'Marketing & Portals',
                href: '/marketing/portals',
                icon: 'marketing',
                ability: 'crm',
            },
            {
                label: 'Portal Subscriptions',
                href: '/marketing/subscriptions',
                icon: 'portalSubscriptions',
                ability: 'accounting',
            },
            {
                label: 'Listing Costing',
                href: '/marketing/costing',
                icon: 'costing',
                ability: 'accounting',
            },
        ],
    },
    {
        id: 'finance',
        label: 'Finance',
        items: [
            {
                label: 'Accounting & Tax',
                href: '/accounting',
                icon: 'accounting',
                ability: 'accounting',
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
                    {
                        label: 'Dimensions',
                        href: '/accounting/dimensions',
                    },
                ],
            },
            {
                label: 'Cheques (PDC)',
                href: '/transactions/pdc',
                icon: 'cheques',
                ability: 'pdc',
            },
            {
                label: 'Receivables',
                href: '/invoices',
                icon: 'receivables',
                ability: 'accounting',
            },
            {
                label: 'Payables',
                href: '/vendor-bills',
                icon: 'payables',
                ability: 'accounting',
            },
            {
                label: 'Vendor Refunds',
                href: '/finance/vendor-cash-refunds',
                icon: 'refunds',
                ability: 'accounting',
            },
            {
                label: 'Bank Reconciliation',
                href: '/bank-reconciliation',
                icon: 'bank',
                ability: 'accounting',
            },
            {
                label: 'Property Performance',
                href: '/reports/property-profitability',
                icon: 'propertyPerformance',
                ability: 'accounting',
            },
            {
                label: 'Owner Statements',
                href: '/reports/owner-statements',
                icon: 'ownerStatements',
                ability: 'accounting',
            },
            {
                label: 'Procurement & Inventory',
                href: '/procurement',
                icon: 'procurement',
                ability: 'procurement',
            },
        ],
    },
    {
        id: 'facility',
        label: 'Facility Management',
        items: [
            {
                label: 'Maintenance',
                href: '/maintenance',
                icon: 'maintenance',
                ability: 'operations',
            },
            {
                label: 'Preventive Maintenance',
                href: '/preventive-maintenance',
                icon: 'preventive',
                ability: 'operations',
            },
            {
                label: 'Helpdesk',
                href: '/operations/helpdesk',
                icon: 'helpdesk',
                ability: 'operations',
            },
            {
                label: 'AMC Contracts',
                href: '/operations/amc',
                icon: 'amc',
                ability: 'operations',
            },
            {
                label: 'Spare Parts',
                href: '/operations/spare-parts',
                icon: 'parts',
                ability: 'operations',
            },
            {
                label: 'Projects',
                href: '/operations/projects',
                icon: 'projects',
                ability: 'projects',
            },
            {
                label: 'Fleet',
                href: '/operations/fleet',
                icon: 'fleet',
                ability: 'fleet',
            },
            {
                label: 'Operations Overview',
                href: '/operations',
                icon: 'operations',
                ability: 'operations',
            },
            {
                label: 'Operations Reports',
                href: '/operations/reports',
                icon: 'reports',
                ability: 'operations',
            },
            {
                label: 'Scheduled Reports',
                href: '/operations/scheduled-reports',
                icon: 'scheduled',
                ability: 'operations',
            },
        ],
    },
    {
        id: 'workflow',
        label: 'Workflow',
        items: [
            { label: 'Approvals', href: '/approvals', icon: 'approvals' },
            { label: 'Tasks', href: '/tasks', icon: 'tasks' },
            {
                label: 'Meetings & Viewings',
                href: '/meetings',
                icon: 'viewings',
                ability: 'crm',
            },
            {
                label: 'Contracts & Signatures',
                href: '/documents/signatures',
                icon: 'signatures',
            },
            {
                label: 'Compliance Documents',
                href: '/compliance-documents',
                icon: 'complianceDocuments',
            },
        ],
    },
    {
        id: 'corporate',
        label: 'Corporate',
        items: [
            { label: 'HR & Staff Services', href: '/hr', icon: 'hr' },
            { label: 'Report Centre', href: '/reports', icon: 'reportCentre' },
            soon('GAIM Compliance', 'gaim'),
            soon('Bulletins', 'bulletins'),
            {
                label: 'Lead Gateway',
                href: '/crm/lead-gateway',
                icon: 'leadGateway',
                ability: 'crm',
            },
            {
                label: 'Follow-up Automation',
                href: '/crm/assignment#follow-up',
                icon: 'followUp',
                ability: 'crm',
            },
            {
                label: 'CRM Pipelines',
                href: '/crm/pipelines',
                icon: 'pipelines',
                ability: 'crm',
            },
            {
                label: 'Departments & Teams',
                href: '/crm/hierarchy',
                icon: 'hierarchy',
                ability: 'crm',
            },
            {
                label: 'Organization & Access',
                href: '/organization',
                icon: 'organization',
                ability: 'organization_admin',
                match: '/organization',
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

export function isEnabled(item: NavLink): item is NavLink & { href: string } {
    return !item.soon && typeof item.href === 'string';
}

export function navHrefs(groups: NavGroup[] = NAVIGATION): string[] {
    return groups.flatMap((group) =>
        group.items.flatMap((item) => {
            if (item.children) {
                return item.children.map((child) => child.href);
            }

            return isEnabled(item) ? [item.href] : [];
        }),
    );
}

function withoutHash(href: string): string {
    return href.split('#')[0];
}

function pathOf(url: string): string {
    const path = url.split(/[?#]/)[0].replace(/\/+$/, '');

    return path === '' ? '/' : path;
}

function matches(prefix: string, path: string): boolean {
    return path === prefix || path.startsWith(`${prefix}/`);
}

function candidates(): { href: string; prefix: string }[] {
    return NAVIGATION.flatMap((group) =>
        group.items.flatMap((item) => {
            if (item.children) {
                return item.children.map((child) => ({
                    href: child.href,
                    prefix: child.href,
                }));
            }

            return isEnabled(item)
                ? [
                      {
                          href: item.href,
                          prefix: withoutHash(item.match ?? item.href),
                      },
                  ]
                : [];
        }),
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

export function visibleNavigation(
    groups: NavGroup[],
    abilities: Record<string, boolean> | null | undefined,
): NavGroup[] {
    if (!abilities) {
        return groups;
    }

    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => !item.ability || abilities[item.ability] !== false,
            ),
        }))
        .filter((group) => group.items.length > 0);
}

export function commandEntries(groups: NavGroup[]): CommandEntry[] {
    return groups.flatMap((group) =>
        group.items.flatMap((item) => {
            if (item.children) {
                return item.children.map((child) => ({
                    label: child.label,
                    href: child.href,
                    section: group.label,
                    icon: item.icon,
                }));
            }

            return isEnabled(item)
                ? [
                      {
                          label: item.label,
                          href: item.href,
                          section: group.label,
                          icon: item.icon,
                      },
                  ]
                : [];
        }),
    );
}
