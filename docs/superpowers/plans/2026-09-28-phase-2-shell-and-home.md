# Phase 2 (App Shell and Home) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat sidebar with the approved sectioned navigation. Add a ⌘K command palette and a richer top bar. Rebuild `Dashboard.vue` as the approved **Home** dashboard. It must work with today's props and fill in automatically as Codex ships the handoff data.

**Architecture:**

- **Navigation** is data: `lib/navigation.ts`, pure and unit-tested. Rendered through `NavSection.vue` with icons from `lib/nav-icons.ts`.
- **Home:** raw page props are turned into one view model by the pure, unit-tested `lib/home.ts`. Small components under `components/home/` render that model and never inspect props themselves.
- **Charts** reuse `lib/chart-paths.ts`, extended with a shared value domain and donut segments.

**Tech Stack:** Vue 3.5 SFC + TypeScript, Inertia 3, Tailwind 4, shadcn-vue (sidebar, command, collapsible, dropdown), `@lucide/vue`, `node:test`.

**Spec:** `docs/superpowers/specs/2026-09-28-phase-2-shell-and-home-design.md`. The data contract is `docs/CODEX_BACKEND_HANDOFF_2026_09_28.md` Part A and Part B. The visual is `.superpowers/brainstorm/home-dashboard.html`.

## Commands

These are the same as phase 1:

- `node-run`: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 <cmd>`
- `php-run`: `docker exec z1erp-web sh -lc 'cd /workspace && <cmd>'`
- **Frontend tests:** `node-run npm run test:frontend`
- **Build:** `php-run php artisan wayfinder:generate --with-form` then `docker run --rm -v "$PWD":/workspace -w /workspace -e WAYFINDER_GENERATED=1 node:22 npm run build`
- **Arabic keys:** `echo '<json>' | python3 /private/tmp/claude-503/-Users-RR-Docker-z1-erp/bf8a1021-d45c-42c3-8ba0-ef840655677b/scratchpad/add_ar.py` (adds only missing keys), then `php-run php scripts/sync-arabic-catalog.php`

Work on branch `bordeaux/phase-2-shell`.

## Global Constraints

- Frontend only. Don't edit `app/`, `routes/`, `database/`, `config/` or `tests/Feature`.
- No new npm dependencies.
- The phase 1 rules still apply:
    - logical direction utilities only
    - every string through `t()`, with Arabic added
    - `resources/js/components/ui/*` keeps its own style (double quotes, no semicolons)
    - pure `lib/*.ts` modules imported by node tests may only use `import type` from `@/…`
- The words "Command Centre" appear nowhere.
- Missing data is never faked:
    - a missing prop or `null` shows "—" plus a "Coming soon" hint
    - an empty list shows a designed empty state
- Commit after each task, ending the message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never push.

## Review Focus

1. **Today's props only** (`metrics`, `alerts`, nothing else): Home renders without errors. Receivables and the operations figures show real numbers; everything else shows "Coming soon". Pinned in Task 5.
2. **`null` KPIs** (`pending_approvals: null`, `overdue_tasks: null`): no crash, shown as "Coming soon", never as 0. Pinned in Task 5.
3. **Revenue with a previous value of 0 or `null`:** no "Infinity%" and no "NaN%" in the summary sentence. Pinned in Task 5.
4. **A user whose abilities hide a whole section:** the section heading disappears too. An absent `abilities` prop hides nothing. Pinned in Task 1.
5. **Soon items:** never clickable, never in the ⌘K palette, never counted as a route. Pinned in Task 1.

---

### Task 1: Sectioned navigation data

**Files:**

- Modify: `resources/js/lib/navigation.ts` (full replacement)
- Modify: `tests/Frontend/navigation.test.mjs` (full replacement)
- Modify: `resources/js/locales/ar.json` and `lang/ar.json` (sync)

**Interfaces:**

- **Produces:**
    - `NavIcon`
    - `NavBadge = 'notifications_unread' | 'crm_open_leads'`
    - `NavLink { label; icon; href?; soon?; ability?; badge?; match?; children? }`
    - `NavGroup { id; label; items }`
    - `NAVIGATION`
    - `isEnabled(item)`
    - `navHrefs(groups?)`
    - `activeHref(url)`
    - `activeGroupId(url)`
    - `visibleNavigation(groups, abilities)`
    - `CommandEntry { label; href; section; icon }`
    - `commandEntries(groups)`

- [ ] **Step 1: Replace `tests/Frontend/navigation.test.mjs` with the failing tests**

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    NAVIGATION,
    activeGroupId,
    activeHref,
    commandEntries,
    navHrefs,
    visibleNavigation,
} from '../../resources/js/lib/navigation.ts';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const routeSource = read('routes/web.php') + read('routes/settings.php');
const getRoutes = new Set(
    [...routeSource.matchAll(/Route::(?:get|inertia)\('([^']+)'/g)].map(
        (match) => `/${match[1].replace(/^\//, '')}`,
    ),
);
const arabic = JSON.parse(read('resources/js/locales/ar.json'));
const items = NAVIGATION.flatMap((group) => group.items);

await test('every enabled link points at an existing GET route', () => {
    const missing = navHrefs().filter(
        (href) => !getRoutes.has(href.split('#')[0]),
    );
    assert.deepEqual(missing, []);
});

await test('no link appears twice', () => {
    const hrefs = navHrefs();
    assert.equal(new Set(hrefs).size, hrefs.length);
});

await test('soon items have no link and every other item has one', () => {
    for (const item of items) {
        if (item.soon) {
            assert.equal(item.href, undefined, `${item.label} is soon`);
        } else {
            assert.ok(item.href, `${item.label} needs a route`);
        }
    }
});

await test('groups are unique and non-empty, and parents open their first child', () => {
    const ids = NAVIGATION.map((group) => group.id);
    assert.equal(new Set(ids).size, ids.length);
    for (const group of NAVIGATION) {
        assert.ok(group.items.length > 0, `${group.id} is empty`);
    }
    for (const item of items.filter((entry) => entry.children)) {
        assert.equal(item.href, item.children[0].href);
    }
});

await test('every section, item and child label has Arabic', () => {
    const labels = NAVIGATION.flatMap((group) => [
        group.label,
        ...group.items.flatMap((item) => [
            item.label,
            ...(item.children ?? []).map((child) => child.label),
        ]),
    ]);
    const missing = [...new Set([...labels, 'Soon'])].filter(
        (label) => !arabic[label],
    );
    assert.deepEqual(missing, []);
});

await test('the active link is the longest matching path', () => {
    assert.equal(
        activeHref('/accounting/vat-return?period=2026-09'),
        '/accounting/vat-return',
    );
    assert.equal(activeHref('/operations/fleet/12'), '/operations/fleet');
    assert.equal(activeHref('/settings/security'), '/settings/profile');
    assert.equal(activeHref('/crm/assignment'), '/crm/assignment');
    assert.equal(activeHref('/crm/leads-archive'), null);
    assert.equal(activeGroupId('/accounting/vat-return'), 'finance');
    assert.equal(activeGroupId('/settings/appearance'), 'corporate');
    assert.equal(activeGroupId('/maintenance/5'), 'facility');
    assert.equal(activeGroupId('/unknown'), null);
});

await test('abilities hide items, and a section with nothing left disappears', () => {
    assert.equal(visibleNavigation(NAVIGATION, null), NAVIGATION);
    const hidden = visibleNavigation(NAVIGATION, {
        fleet: false,
        crm: false,
    });
    const labels = hidden.flatMap((group) =>
        group.items.map((item) => item.label),
    );
    assert.ok(!labels.includes('Fleet'));
    assert.ok(!labels.includes('CRM & Leads'));
    assert.ok(labels.includes('Maintenance'));
    const onlyOverview = visibleNavigation(
        [
            NAVIGATION[0],
            {
                id: 'x',
                label: 'X',
                items: [
                    {
                        label: 'Fleet',
                        icon: 'fleet',
                        href: '/operations/fleet',
                        ability: 'fleet',
                    },
                ],
            },
        ],
        { fleet: false },
    );
    assert.deepEqual(
        onlyOverview.map((group) => group.id),
        ['overview'],
    );
});

await test('the command palette lists enabled pages only, children included', () => {
    const entries = commandEntries(NAVIGATION);
    assert.ok(entries.every((entry) => entry.href));
    assert.ok(!entries.some((entry) => entry.label === 'AI Matchmaker'));
    const vat = entries.find(
        (entry) => entry.href === '/accounting/vat-return',
    );
    assert.equal(vat.section, 'Finance');
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `node-run npm run test:frontend 2>&1 | grep -E "^not ok|^# (pass|fail)"`

Expected: the navigation tests FAIL. `visibleNavigation` and `commandEntries` aren't exported yet.

- [ ] **Step 3: Replace `resources/js/lib/navigation.ts`**

```ts
export type NavIcon =
    | 'home'
    | 'search'
    | 'notifications'
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

const soon = (label: string, icon: NavIcon): NavLink => ({
    label,
    icon,
    soon: true,
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
            soon('AI Matchmaker', 'matchmaker'),
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
            soon('Off-Plan Projects', 'offPlan'),
            soon('Secondary Market', 'secondary'),
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
                href: '/crm/pipeline-report',
                icon: 'performance',
                ability: 'crm',
            },
        ],
    },
    {
        id: 'marketing',
        label: 'Marketing',
        items: [
            soon('Marketing & Portals', 'marketing'),
            soon('Portal Listings', 'portalListings'),
            soon('Portal Subscriptions', 'portalSubscriptions'),
            soon('Portal Invoicing', 'portalInvoicing'),
            soon('Listing Costing', 'costing'),
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
                ],
            },
            soon('Cheques (PDC)', 'cheques'),
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
            soon('Approvals', 'approvals'),
            soon('Tasks', 'tasks'),
            soon('Meetings & Viewings', 'viewings'),
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
            soon('HR & Staff Services', 'hr'),
            soon('Report Centre', 'reportCentre'),
            soon('GAIM Compliance', 'gaim'),
            soon('Bulletins', 'bulletins'),
            soon('Lead Gateway', 'leadGateway'),
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
```

- [ ] **Step 4: Run the tests. Only the Arabic test should fail**

Expected: 7 of the 8 navigation tests pass. The Arabic test lists the missing labels.

- [ ] **Step 5: Add Arabic for the missing labels**, then run the sync script. Use only these values:

```json
{
    "Home": "الرئيسية",
    "Sales & CRM": "المبيعات وإدارة العملاء",
    "CRM & Leads": "العملاء المحتملون وإدارة العلاقات",
    "AI Matchmaker": "المطابقة الذكية",
    "Property & Listings": "العقارات والعروض",
    "Property Inventory": "المخزون العقاري",
    "Owners & Developers": "الملاك والمطورون",
    "Off-Plan Projects": "مشاريع على الخارطة",
    "Secondary Market": "السوق الثانوي",
    "Deal Management": "إدارة الصفقات",
    "Leasing & Rental": "التأجير والإيجار",
    "Agents & Commission": "الوكلاء والعمولات",
    "Broker Allocation": "توزيع الوسطاء",
    "Broker Performance": "أداء الوسطاء",
    "Marketing": "التسويق",
    "Marketing & Portals": "التسويق والبوابات العقارية",
    "Portal Listings": "عروض البوابات",
    "Portal Subscriptions": "اشتراكات البوابات",
    "Portal Invoicing": "فواتير البوابات",
    "Listing Costing": "تكلفة العروض",
    "Accounting & Tax": "المحاسبة والضرائب",
    "Cheques (PDC)": "الشيكات المؤجلة",
    "Receivables": "الذمم المدينة",
    "Payables": "الذمم الدائنة",
    "Vendor Refunds": "مستردات الموردين",
    "Bank Reconciliation": "المطابقة البنكية",
    "Property Performance": "أداء العقارات",
    "Owner Statements": "كشوف الملاك",
    "Procurement & Inventory": "المشتريات والمخزون",
    "Facility Management": "إدارة المرافق",
    "Preventive Maintenance": "الصيانة الوقائية",
    "AMC Contracts": "عقود الصيانة السنوية",
    "Spare Parts": "قطع الغيار",
    "Operations Overview": "نظرة عامة على العمليات",
    "Operations Reports": "تقارير العمليات",
    "Scheduled Reports": "التقارير المجدولة",
    "Workflow": "سير العمل",
    "Approvals": "الموافقات",
    "Tasks": "المهام",
    "Meetings & Viewings": "الاجتماعات والمعاينات",
    "Contracts & Signatures": "العقود والتوقيعات",
    "Compliance Documents": "مستندات الامتثال",
    "Corporate": "الشركة",
    "HR & Staff Services": "الموارد البشرية وخدمات الموظفين",
    "Report Centre": "مركز التقارير",
    "GAIM Compliance": "امتثال GAIM",
    "Bulletins": "النشرات",
    "Lead Gateway": "بوابة العملاء المحتملين",
    "Follow-up Automation": "أتمتة المتابعة",
    "CRM Pipelines": "مسارات المبيعات",
    "Departments & Teams": "الأقسام والفرق",
    "Organization & Access": "المؤسسة والصلاحيات",
    "Soon": "قريبًا"
}
```

- [ ] **Step 6: Run the tests, format, typecheck and lint**

Run: `node-run sh -c "npx vp fmt resources/js/lib tests/Frontend >/dev/null; npm run test:frontend && npm run typecheck && npm run lint"`

Expected: every test passes, with exit code 0.

The old `AppSidebar.vue` doesn't import `NAVIGATION`, so it keeps compiling.

- [ ] **Step 7: Commit** `feat(nav): sectioned navigation with soon items, abilities and command entries`, including the locale files.

---

### Task 2: Sidebar components

**Files:**

- Create: `resources/js/lib/nav-icons.ts`, `resources/js/composables/useNavigation.ts`, `resources/js/components/SidebarBrand.vue`, `resources/js/components/NavSection.vue`
- Create test: `tests/Frontend/nav-icons.test.mjs`
- Modify: `resources/js/components/AppSidebar.vue` (full replacement), `resources/js/types/global.d.ts`, `resources/js/components/ui/sidebar/index.ts` (active style)

**Interfaces:**

- **Consumes:** Task 1 exports.
- **Produces:**
    - `NAV_ICONS: Record<NavIcon, Component>`
    - `useNavigation(): { groups: ComputedRef<NavGroup[]>; active: ComputedRef<string | null>; badge(key?: NavBadge): number | null }`
    - shared prop types `organization?`, `abilities?` and `counts?`

- [ ] **Step 1: Write the failing icon-coverage test** `tests/Frontend/nav-icons.test.mjs`

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

await test('every navigation icon name has a Lucide icon mapped', () => {
    const union = read('resources/js/lib/navigation.ts').match(
        /export type NavIcon =([^;]+);/,
    )[1];
    const names = [...union.matchAll(/'([A-Za-z]+)'/g)].map((m) => m[1]);
    const icons = read('resources/js/lib/nav-icons.ts');
    const missing = names.filter(
        (name) => !new RegExp(`\\b${name}: [A-Z]`).test(icons),
    );
    assert.deepEqual(missing, []);
});
```

Run it and confirm it fails with ENOENT (no `nav-icons.ts` yet).

- [ ] **Step 2: Create `resources/js/lib/nav-icons.ts`**

```ts
import {
    ArrowLeftRight,
    BadgePercent,
    Banknote,
    Bell,
    Bot,
    Briefcase,
    Building,
    Building2,
    Calculator,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    ChartColumn,
    ChartLine,
    Clock,
    Cog,
    Construction,
    Contact,
    FileCheck,
    FileKey,
    FileMinus,
    FileText,
    Gauge,
    GitBranch,
    Globe,
    Handshake,
    HardHat,
    House,
    IdCard,
    KeyRound,
    Landmark,
    Layers,
    Library,
    LifeBuoy,
    ListTodo,
    Megaphone,
    Network,
    Newspaper,
    Package,
    RadioTower,
    Receipt,
    Repeat,
    Route,
    ScrollText,
    Search,
    Settings,
    ShieldCheck,
    ShieldPlus,
    Signature,
    Sparkles,
    SquareCheck,
    Trophy,
    Truck,
    Undo2,
    UsersRound,
    Warehouse,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { NavIcon } from '@/lib/navigation';

export const NAV_ICONS: Record<NavIcon, Component> = {
    home: House,
    search: Search,
    notifications: Bell,
    leads: UsersRound,
    contacts: Contact,
    matchmaker: Sparkles,
    listings: Building,
    inventory: Warehouse,
    owners: Briefcase,
    offPlan: Construction,
    secondary: Repeat,
    reservations: CalendarCheck,
    deals: Handshake,
    handovers: KeyRound,
    leasing: FileKey,
    commission: BadgePercent,
    allocation: Route,
    performance: Trophy,
    marketing: Megaphone,
    portalListings: Globe,
    portalSubscriptions: Layers,
    portalInvoicing: Receipt,
    costing: Calculator,
    accounting: Landmark,
    cheques: Banknote,
    receivables: FileText,
    payables: FileMinus,
    refunds: Undo2,
    bank: ArrowLeftRight,
    propertyPerformance: ChartLine,
    ownerStatements: ScrollText,
    procurement: Package,
    maintenance: Wrench,
    preventive: CalendarClock,
    helpdesk: LifeBuoy,
    amc: ShieldPlus,
    parts: Cog,
    projects: HardHat,
    fleet: Truck,
    operations: Gauge,
    reports: ChartColumn,
    scheduled: Clock,
    approvals: SquareCheck,
    tasks: ListTodo,
    viewings: CalendarDays,
    signatures: Signature,
    complianceDocuments: FileCheck,
    hr: IdCard,
    reportCentre: Library,
    gaim: ShieldCheck,
    bulletins: Newspaper,
    leadGateway: RadioTower,
    followUp: Bot,
    pipelines: GitBranch,
    hierarchy: Network,
    organization: Building2,
    settings: Settings,
};
```

If `vue-tsc` later reports that an icon name doesn't exist in the installed `@lucide/vue`, replace it with the nearest existing icon. Don't add dependencies. Record a ruling.

- [ ] **Step 3: Add the shared prop types** in `resources/js/types/global.d.ts`, inside `sharedPageProps` before `[key: string]: unknown;`:

```ts
            organization?: { id: number; name: string; slug: string } | null;
            abilities?: Record<string, boolean>;
            counts?: {
                notifications_unread: number;
                crm_open_leads: number;
                approvals_pending: number | null;
            };
```

- [ ] **Step 4: Create `resources/js/composables/useNavigation.ts`**

```ts
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { NAVIGATION, activeHref, visibleNavigation } from '@/lib/navigation';
import type { NavBadge, NavGroup } from '@/lib/navigation';

export function useNavigation() {
    const page = usePage();
    const { currentUrl } = useCurrentUrl();
    const groups = computed<NavGroup[]>(() =>
        visibleNavigation(NAVIGATION, page.props.abilities ?? null),
    );
    const active = computed(() => activeHref(currentUrl.value));

    function badge(key?: NavBadge): number | null {
        if (!key) {
            return null;
        }
        const value = page.props.counts?.[key];

        return typeof value === 'number' && value > 0 ? value : null;
    }

    return { groups, active, badge };
}
```

- [ ] **Step 5: Create `resources/js/components/SidebarBrand.vue`**

```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useLocale } from '@/composables/useLocale';

const page = usePage();
const { t } = useLocale();
const organization = computed(
    () => page.props.organization?.name ?? page.props.name,
);
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <SidebarMenuButton
                size="lg"
                as-child
                class="hover:bg-transparent active:bg-transparent"
            >
                <Link href="/dashboard" :aria-label="t('Home')">
                    <span
                        class="border-champagne/45 text-champagne font-display grid size-9 shrink-0 place-items-center rounded-md border bg-white/5 text-lg font-semibold"
                        >Z1</span
                    >
                    <span class="grid min-w-0 leading-tight">
                        <span class="truncate text-sm font-semibold"
                            >Z1 ERP</span
                        >
                        <span class="text-sidebar-muted truncate text-[11px]">{{
                            organization
                        }}</span>
                    </span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
```

- [ ] **Step 6: Create `resources/js/components/NavSection.vue`**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useLocale } from '@/composables/useLocale';
import { NAV_ICONS } from '@/lib/nav-icons';
import type { NavBadge, NavGroup } from '@/lib/navigation';

defineProps<{
    group: NavGroup;
    active: string | null;
    badge: (key?: NavBadge) => number | null;
}>();

const { t } = useLocale();
</script>

<template>
    <SidebarGroup class="py-1">
        <SidebarGroupLabel
            class="text-sidebar-muted h-7 text-[10px] font-medium tracking-[0.18em] uppercase"
            >{{ t(group.label) }}</SidebarGroupLabel
        >
        <SidebarMenu>
            <template v-for="item in group.items" :key="item.label">
                <Collapsible
                    v-if="item.children"
                    as-child
                    :default-open="
                        item.children.some((child) => child.href === active)
                    "
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :tooltip="t(item.label)"
                                :is-active="
                                    item.children.some(
                                        (child) => child.href === active,
                                    )
                                "
                            >
                                <component :is="NAV_ICONS[item.icon]" />
                                <span>{{ t(item.label) }}</span>
                                <ChevronRight
                                    class="ms-auto transition-transform group-data-[state=open]/collapsible:rotate-90 rtl:-scale-x-100"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem
                                    v-for="child in item.children"
                                    :key="child.href"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="child.href === active"
                                    >
                                        <Link :href="child.href">{{
                                            t(child.label)
                                        }}</Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
                <SidebarMenuItem v-else-if="item.soon">
                    <SidebarMenuButton
                        :tooltip="`${t(item.label)} · ${t('Soon')}`"
                        class="cursor-default opacity-55 hover:bg-transparent active:bg-transparent"
                        aria-disabled="true"
                        tabindex="-1"
                    >
                        <component :is="NAV_ICONS[item.icon]" />
                        <span>{{ t(item.label) }}</span>
                        <span
                            class="text-sidebar-muted ms-auto text-[9px] tracking-[0.1em] uppercase group-data-[collapsible=icon]:hidden"
                            >{{ t('Soon') }}</span
                        >
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :is-active="item.href === active"
                        :tooltip="t(item.label)"
                    >
                        <Link :href="item.href ?? '/dashboard'">
                            <component :is="NAV_ICONS[item.icon]" />
                            <span>{{ t(item.label) }}</span>
                        </Link>
                    </SidebarMenuButton>
                    <SidebarMenuBadge
                        v-if="badge(item.badge)"
                        class="bg-champagne/20 text-sidebar-foreground rounded-full"
                        >{{ badge(item.badge) }}</SidebarMenuBadge
                    >
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
```

The sidebar's `aria-disabled:pointer-events-none` would suppress the tooltip on Soon items. That's acceptable: the label is visible, and the "Soon" text is visible when the sidebar is expanded.

- [ ] **Step 7: Replace `resources/js/components/AppSidebar.vue`.** Keep `LanguageSwitcher` until Task 3 moves it.

```vue
<script setup lang="ts">
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import NavSection from '@/components/NavSection.vue';
import NavUser from '@/components/NavUser.vue';
import SidebarBrand from '@/components/SidebarBrand.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useLocale } from '@/composables/useLocale';
import { useNavigation } from '@/composables/useNavigation';

const { locale } = useLocale();
const { groups, active, badge } = useNavigation();
</script>

<template>
    <Sidebar
        :side="locale === 'ar' ? 'right' : 'left'"
        collapsible="icon"
        variant="inset"
    >
        <SidebarHeader class="pt-3"><SidebarBrand /></SidebarHeader>
        <SidebarContent class="gap-0 pb-3">
            <NavSection
                v-for="group in groups"
                :key="group.id"
                :group="group"
                :active="active"
                :badge="badge"
            />
        </SidebarContent>
        <SidebarFooter>
            <div class="group-data-[collapsible=icon]:hidden">
                <LanguageSwitcher />
            </div>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
```

- [ ] **Step 8: Change the active style** in `components/ui/sidebar/index.ts` `sidebarMenuButtonVariants` base string. Replace `data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground` with:

`relative data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground data-[active=true]:[&>svg]:text-champagne data-[active=true]:before:absolute data-[active=true]:before:inset-y-2 data-[active=true]:before:start-0 data-[active=true]:before:w-0.5 data-[active=true]:before:rounded-full data-[active=true]:before:bg-champagne`

Also change `text-left` to `text-start` in the same string.

- [ ] **Step 9: Verify**

1. Run the icon test (PASS), then `npm run typecheck`, `npm run lint` and the build.
2. Run `php-run php artisan test`: all pass.
3. Screenshot `/dashboard` in light and dark (`pages.py`) and check that the sidebar matches the mockup: section headings, Soon rows, the active marker.

- [ ] **Step 10: Commit** `feat(nav): sectioned sidebar with brand, soon items, badges and active marker`

---

### Task 3: Top bar, ⌘K palette and user menu

**Files:**

- Create: `resources/js/composables/useCommandPalette.ts`, `resources/js/components/CommandPalette.vue`
- Modify: `resources/js/components/AppSidebarHeader.vue` (full replacement), `resources/js/components/UserMenuContent.vue`, `resources/js/components/AppSidebar.vue` (remove `LanguageSwitcher`)

**Interfaces:**

- **Consumes:** `useNavigation`, `commandEntries`, `NAV_ICONS`, `useAppearance`, `useLocale`.
- **Produces:** `useCommandPalette(): { open: Ref<boolean>; toggle(): void; listen(): void }`

- [ ] **Step 1: Create `resources/js/composables/useCommandPalette.ts`**

```ts
import { onBeforeUnmount, onMounted, ref } from 'vue';

const open = ref(false);

function onKey(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = !open.value;
    }
}

export function useCommandPalette() {
    function toggle(): void {
        open.value = !open.value;
    }

    /** Call once, from the component that owns the palette. */
    function listen(): void {
        onMounted(() => window.addEventListener('keydown', onKey));
        onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
    }

    return { open, toggle, listen };
}
```

- [ ] **Step 2: Create `resources/js/components/CommandPalette.vue`.** Check the exact `CommandItem` select event name and the `CommandInput` model in `components/ui/command/*.vue` first. The code below assumes reka's `@select` and `v-model` on the input. If the names differ, adapt them and record a ruling.

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useLocale } from '@/composables/useLocale';
import { useNavigation } from '@/composables/useNavigation';
import { NAV_ICONS } from '@/lib/nav-icons';
import { commandEntries } from '@/lib/navigation';
import type { CommandEntry } from '@/lib/navigation';

const { open, listen } = useCommandPalette();
listen();
const { groups } = useNavigation();
const { t } = useLocale();
const query = ref('');

const sections = computed(() => {
    const map = new Map<string, CommandEntry[]>();
    for (const entry of commandEntries(groups.value)) {
        map.set(entry.section, [...(map.get(entry.section) ?? []), entry]);
    }

    return [...map.entries()];
});

function go(href: string): void {
    open.value = false;
    router.visit(href);
}

function searchAll(): void {
    const q = query.value.trim();
    if (q.length < 2) {
        return;
    }
    open.value = false;
    router.get('/search', { q });
}
</script>

<template>
    <CommandDialog
        v-model:open="open"
        :title="t('Search or jump to…')"
        :description="t('Pages and records')"
    >
        <CommandInput v-model="query" :placeholder="t('Search or jump to…')" />
        <CommandList class="max-h-[60vh]">
            <CommandEmpty>{{ t('No pages match.') }}</CommandEmpty>
            <CommandGroup
                v-if="query.trim().length >= 2"
                :heading="t('Records')"
            >
                <CommandItem :value="`search ${query}`" @select="searchAll">
                    <Search />
                    {{ t('Search all records for “:q”', { q: query.trim() }) }}
                </CommandItem>
            </CommandGroup>
            <CommandGroup
                v-for="[section, entries] in sections"
                :key="section"
                :heading="t(section)"
            >
                <CommandItem
                    v-for="entry in entries"
                    :key="entry.href"
                    :value="`${t(entry.label)} ${entry.label} ${entry.href}`"
                    @select="go(entry.href)"
                >
                    <component :is="NAV_ICONS[entry.icon]" />
                    {{ t(entry.label) }}
                </CommandItem>
            </CommandGroup>
        </CommandList>
    </CommandDialog>
</template>
```

- [ ] **Step 3: Replace `resources/js/components/AppSidebarHeader.vue`**

```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, Search } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useLocale } from '@/composables/useLocale';
import type { BreadcrumbItem } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const { t } = useLocale();
const { toggle } = useCommandPalette();
const unread = computed(() => page.props.counts?.notifications_unread ?? 0);
</script>

<template>
    <header
        class="border-sidebar-border/70 bg-background/85 sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b px-4 backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-6"
    >
        <SidebarTrigger class="-ms-1" />
        <Breadcrumbs v-if="breadcrumbs.length > 0" :breadcrumbs="breadcrumbs" />
        <div class="ms-auto flex items-center gap-2">
            <button
                type="button"
                class="bg-surface-sunken text-muted-foreground hover:text-foreground focus-visible:ring-ring hidden h-9 w-72 items-center gap-2 rounded-md border px-3 text-sm outline-none focus-visible:ring-2 md:flex"
                @click="toggle"
            >
                <Search class="size-4" />
                {{ t('Search or jump to…') }}
                <kbd class="bg-card ms-auto rounded border px-1.5 text-[10.5px]"
                    >⌘K</kbd
                >
            </button>
            <Button
                variant="outline"
                size="icon"
                class="md:hidden"
                :aria-label="t('Search')"
                @click="toggle"
            >
                <Search />
            </Button>
            <Button variant="outline" size="icon" as-child>
                <Link
                    href="/notifications"
                    class="relative"
                    :aria-label="t('Notifications')"
                >
                    <Bell />
                    <span
                        v-if="unread > 0"
                        class="bg-champagne ring-background absolute -end-1 -top-1 grid min-w-4 place-items-center rounded-full px-1 text-[10px] leading-4 font-semibold text-[#3a1520] ring-2"
                        >{{ unread > 99 ? '99+' : unread }}</span
                    >
                </Link>
            </Button>
        </div>
        <CommandPalette />
    </header>
</template>
```

- [ ] **Step 4: Put appearance and language in the user menu.** In `UserMenuContent.vue`:
- Add the imports `import { Check, Monitor, Moon, Sun, Languages } from '@lucide/vue';` and `import { useAppearance } from '@/composables/useAppearance';`.
- In the script, add `const { appearance, updateAppearance } = useAppearance(); const { locale } = useLocale();`. Change the existing line to `const { t, locale } = useLocale();` and remove the duplicate.
- Add `function setLocale(value: 'en' | 'ar') { router.post('/locale', { locale: value }, { preserveState: true }); }`.
- Replace `mr-2` with `me-2` in the file.
- Insert this block after the first `<DropdownMenuSeparator />`:

```vue
<DropdownMenuLabel
    class="text-muted-foreground px-2 text-[10px] font-medium tracking-[0.16em] uppercase"
>{{ t('Appearance') }}</DropdownMenuLabel>
<DropdownMenuItem
    v-for="option in [
            { value: 'light', label: 'Light', icon: Sun },
            { value: 'dark', label: 'Dark', icon: Moon },
            { value: 'system', label: 'System', icon: Monitor },
        ] as const"
    :key="option.value"
    @select="updateAppearance(option.value)"
>
        <component :is="option.icon" class="me-2 h-4 w-4" />{{ t(option.label) }}
        <Check v-if="appearance === option.value" class="ms-auto h-4 w-4" />
    </DropdownMenuItem>
<DropdownMenuSeparator />
<DropdownMenuLabel
    class="text-muted-foreground px-2 text-[10px] font-medium tracking-[0.16em] uppercase"
>{{ t('Language') }}</DropdownMenuLabel>
<DropdownMenuItem @select="setLocale('en')">
        <Languages class="me-2 h-4 w-4" />English
        <Check v-if="locale === 'en'" class="ms-auto h-4 w-4" />
    </DropdownMenuItem>
<DropdownMenuItem @select="setLocale('ar')">
        <Languages class="me-2 h-4 w-4" /><span lang="ar">العربية</span>
        <Check v-if="locale === 'ar'" class="ms-auto h-4 w-4" />
    </DropdownMenuItem>
```

- [ ] **Step 5: Remove the footer `LanguageSwitcher`** from `AppSidebar.vue`, both the `<div>` block and its import. Leave `LanguageSwitcher.vue` in place, because the guest and auth layouts may still use it (`grep -rn LanguageSwitcher resources/js`).

- [ ] **Step 6: Add Arabic keys, if missing**, then run the sync script:

```json
{
    "Search or jump to…": "ابحث أو انتقل إلى…",
    "Pages and records": "الصفحات والسجلات",
    "No pages match.": "لا توجد صفحات مطابقة.",
    "Records": "السجلات",
    "Search all records for “:q”": "البحث في كل السجلات عن «:q»",
    "Appearance": "المظهر",
    "Light": "فاتح",
    "Dark": "داكن",
    "System": "حسب النظام"
}
```

- [ ] **Step 7: Verify**

1. Run typecheck, lint, the build, and the full PHP suite.
2. Take screenshots.
3. Check the palette by hand: after login, press `⌘K`/`Ctrl+K` in the Playwright script (`page.keyboard.press('Control+K')`), type `vat`, and screenshot. The expected result is that "VAT return" is listed.

- [ ] **Step 8: Commit** `feat(shell): top bar with command palette, notifications and menu appearance/language`

---

### Task 4: Chart helpers for Home

**Files:**

- Modify: `resources/js/lib/chart-paths.ts`, `resources/js/lib/format.ts`
- Test: `tests/Frontend/chart-paths.test.mjs`, `tests/Frontend/format.test.mjs` (append)

**Interfaces:**

- **Produces:**
    - an optional `domain?: [number, number]` last parameter on `chartSegments`, `chartPoints`, `linePath` and `areaPath`
    - `donutSegments(values: number[], circumference: number, gap = 0): { length: number; offset: number }[]`
    - `formatMonth(value: string, locale: AppLocale): string`, taking `'YYYY-MM'`

- [ ] **Step 1: Append the failing tests**

```js
// chart-paths.test.mjs — add donutSegments to the import list
await test('a shared domain keeps two series on the same scale', () => {
    const high = chartPoints([50, 100], 100, 100, 0, [0, 100]);
    const low = chartPoints([10, 20], 100, 100, 0, [0, 100]);
    assert.deepEqual(
        high.map(([, y]) => y),
        [50, 0],
    );
    assert.deepEqual(
        low.map(([, y]) => y),
        [90, 80],
    );
});

await test('donut segments split the ring in proportion, with gaps', () => {
    const segments = donutSegments([3, 1], 100, 2);
    assert.deepEqual(segments, [
        { length: 73, offset: 0 },
        { length: 23, offset: -75 },
    ]);
    assert.deepEqual(donutSegments([0, 0], 100), []);
    assert.deepEqual(donutSegments([-5, 5], 100), [
        { length: 0, offset: 0 },
        { length: 100, offset: 0 },
    ]);
});
```

```js
// format.test.mjs — add formatMonth to the import list
await test('months read as short English or full Arabic names', () => {
    assert.equal(formatMonth('2026-09', 'en'), 'Sep');
    assert.equal(formatMonth('2026-01', 'ar'), 'يناير');
    assert.equal(formatMonth('bad', 'en'), '—');
});
```

Confirm they fail.

- [ ] **Step 2: Implement**
    - In `chart-paths.ts`, add a parameter `domain?: [number, number]` to `chartSegments`. When given, use `min = domain[0]` and `range = domain[1] - domain[0]`. Pass it through from `chartPoints`, `linePath` and `areaPath`, which gain the same last parameter. Then append:

```ts
export function donutSegments(
    values: number[],
    circumference: number,
    gap = 0,
): { length: number; offset: number }[] {
    const clean = values.map((value) => (value > 0 ? value : 0));
    const total = clean.reduce((sum, value) => sum + value, 0);
    if (total === 0) {
        return [];
    }
    let used = 0;

    return clean.map((value) => {
        const share = (value / total) * circumference;
        const segment = {
            length: value > 0 ? round(Math.max(0, share - gap)) : 0,
            offset: used === 0 ? 0 : -round(used),
        };
        used += share;

        return segment;
    });
}
```

- In `format.ts`, append:

```ts
export function formatMonth(value: string, locale: AppLocale = 'en'): string {
    if (!/^\d{4}-\d{2}$/.test(value)) {
        return EMPTY_VALUE;
    }

    return new Intl.DateTimeFormat(
        locale === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US',
        { month: locale === 'ar' ? 'long' : 'short', timeZone: 'UTC' },
    ).format(new Date(`${value}-01T00:00:00Z`));
}
```

- [ ] **Step 3: Tests pass, then run typecheck and lint.** Commit: `feat(charts): shared domain, donut segments and month labels`.

---

### Task 5: Home view model (`lib/home.ts`)

**Files:**

- Create: `resources/js/types/home.ts`, `resources/js/lib/home.ts`
- Test: `tests/Frontend/home.test.mjs`

**Interfaces:**

- **Consumes:** the handoff Part A contract.
- **Produces:**
    - `HomeProps`
    - `Figure { value: number | null; change: number | null; count: number | null; soon: boolean }`
    - `HomeView`
    - `normalizeHome(props)`
    - `percentChange(value, previous)`
    - `Sentence { key: string; params?: Record<string, string | number>; emphasis?: boolean }`
    - `summarySentences(view)`
    - `AttentionItem`
    - `attentionItems(view)`
    - `greetingKey(hour)`

- [ ] **Step 1: Create `resources/js/types/home.ts`** (types only)

```ts
export type Money = number;

export type LegacyMetrics = {
    openMaintenance: number;
    overdueMaintenance: number;
    availableUnits: number;
    reservedUnits: number;
    activeLeads: number;
    outstandingAed: number;
};

export type HomeAlert = { title: string; count: number; href: string };

export type HomeFilters = {
    period: 'month' | 'quarter' | 'year';
    purpose: 'all' | 'sale' | 'rent';
    company_id: number | null;
    branch_id: number | null;
    range: { from: string; to: string };
    options: {
        companies: { id: number; name: string }[];
        branches: { id: number; name: string; company_id: number }[];
    };
};

export type HomeKpis = {
    revenue: { value: Money; previous: Money | null };
    expenses: { value: Money; previous: Money | null };
    net_profit: { value: Money; previous: Money | null };
    cash_balance: { value: Money; change_7d_pct: number | null };
    receivables: { value: Money };
    payables: { value: Money };
    vat_payable: { value: Money };
    commission_payable: { value: Money; agents: number };
    active_deals: { count: number };
    expiring_contracts: { count: number };
    pdc_due: { count: number; amount: Money };
    bounced_cheques: { count: number; amount: Money };
    pending_approvals: { count: number } | null;
    overdue_tasks: { count: number } | null;
};

export type HomeTrend = {
    months: string[];
    sales_value: Money[];
    rental_value: Money[];
};
export type CommissionSplit = {
    net_company: Money;
    agent_payable: Money;
    co_broker: Money;
    referral: Money;
};
export type TopAgent = {
    user_id: number;
    name: string;
    team: string | null;
    commission: Money;
    deals: number;
};
export type LeadPipeline = {
    pipeline: { id: number; name: string } | null;
    stages: { id: number; name: string; type: string; count: number }[];
};
export type LeadSource = { source: string; count: number };
export type DealStage = {
    key: string;
    label: string;
    count: number;
    value: Money;
};
export type CostCentreRow = {
    level: 0 | 1 | 2;
    company: string;
    branch: string | null;
    department: string | null;
    cost_centre: string | null;
    revenue: Money;
    expense: Money;
    profit: Money;
};
export type Insight = { icon: string; text: string };

export type HomeProps = {
    metrics?: LegacyMetrics;
    alerts?: HomeAlert[];
    filters?: HomeFilters;
    kpis?: HomeKpis;
    trend?: HomeTrend;
    commission_split?: CommissionSplit | null;
    top_agents?: TopAgent[];
    lead_pipeline?: LeadPipeline;
    lead_sources?: LeadSource[];
    deal_pipeline?: { stages: DealStage[] };
    cost_centres?: CostCentreRow[];
    insights?: Insight[];
};
```

- [ ] **Step 2: Write the failing tests** `tests/Frontend/home.test.mjs`

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    attentionItems,
    greetingKey,
    normalizeHome,
    percentChange,
    summarySentences,
} from '../../resources/js/lib/home.ts';

const legacy = {
    metrics: {
        openMaintenance: 4,
        overdueMaintenance: 1,
        availableUnits: 12,
        reservedUnits: 3,
        activeLeads: 48,
        outstandingAed: 11600,
    },
    alerts: [{ title: 'Overdue maintenance', count: 1, href: '/maintenance' }],
};

const kpis = {
    revenue: { value: 188250, previous: 176900 },
    expenses: { value: 67515, previous: 60000 },
    net_profit: { value: 120735, previous: 116900 },
    cash_balance: { value: 50760, change_7d_pct: -12 },
    receivables: { value: 11600 },
    payables: { value: 52257 },
    vat_payable: { value: 4027 },
    commission_payable: { value: 300400, agents: 18 },
    active_deals: { count: 4 },
    expiring_contracts: { count: 2 },
    pdc_due: { count: 3, amount: 9000 },
    bounced_cheques: { count: 4, amount: 64000 },
    pending_approvals: null,
    overdue_tasks: null,
};

await test("with today's props only, real numbers show and the rest is coming soon", () => {
    const view = normalizeHome(legacy);
    assert.equal(view.hasKpis, false);
    assert.deepEqual(view.figures.receivables, {
        value: 11600,
        change: null,
        count: null,
        soon: false,
    });
    assert.equal(view.figures.revenue.soon, true);
    assert.equal(view.figures.revenue.value, null);
    assert.equal(view.trend, null);
    assert.equal(view.topAgents, null);
    assert.equal(view.alerts.length, 1);
    assert.equal(view.legacy.activeLeads, 48);
});

await test('with the full contract, null KPIs stay coming soon, never zero', () => {
    const view = normalizeHome({ ...legacy, kpis });
    assert.equal(view.hasKpis, true);
    assert.equal(view.figures.revenue.change, 6.4);
    assert.equal(view.figures.cash_balance.change, -12);
    assert.deepEqual(view.figures.bounced_cheques, {
        value: 64000,
        change: null,
        count: 4,
        soon: false,
    });
    assert.equal(view.figures.pending_approvals.soon, true);
    assert.equal(view.figures.overdue_tasks.value, null);
});

await test('percentage change never divides by zero', () => {
    assert.equal(percentChange(100, 0), null);
    assert.equal(percentChange(100, null), null);
    assert.equal(percentChange(50, 100), -50);
    assert.equal(percentChange(-50, -100), 50);
});

await test('the summary sentence reports direction and what needs attention', () => {
    const up = summarySentences(normalizeHome({ ...legacy, kpis }));
    assert.deepEqual(up[0], {
        key: 'Revenue is up :change on the previous period.',
        params: { change: '6.4%' },
    });
    assert.deepEqual(up[1], {
        key: ':count cheques bounced this week.',
        params: { count: 4 },
        emphasis: true,
    });
    const flat = summarySentences(
        normalizeHome({
            kpis: {
                ...kpis,
                revenue: { value: 100, previous: 0 },
                bounced_cheques: { count: 0, amount: 0 },
            },
        }),
    );
    assert.deepEqual(
        flat.map((s) => s.key),
        ['Nothing needs your attention right now.'],
    );
    const today = summarySentences(normalizeHome(legacy));
    assert.deepEqual(
        today.map((s) => s.key),
        ['Here is where things stand today.', '1 item needs your attention.'],
    );
    const one = summarySentences(
        normalizeHome({
            kpis: { ...kpis, bounced_cheques: { count: 1, amount: 50 } },
        }),
    );
    assert.equal(one[1].key, '1 cheque bounced this week.');
});

await test('attention lists urgent money first, then expiries, then backend alerts', () => {
    const items = attentionItems(normalizeHome({ ...legacy, kpis }));
    assert.deepEqual(
        items.map((item) => [item.tag, item.tone]),
        [
            ['PDC', 'danger'],
            ['PDC', 'warning'],
            ['Lease', 'warning'],
            ['Alert', 'info'],
        ],
    );
    assert.equal(items[0].amount, 64000);
    assert.equal(items[3].count, 1);
    assert.deepEqual(attentionItems(normalizeHome({})), []);
});

await test('the greeting follows the time of day', () => {
    assert.equal(greetingKey(6), 'Good morning, :name.');
    assert.equal(greetingKey(13), 'Good afternoon, :name.');
    assert.equal(greetingKey(19), 'Good evening, :name.');
    assert.equal(greetingKey(2), 'Good evening, :name.');
});
```

Confirm they fail with `ERR_MODULE_NOT_FOUND`.

- [ ] **Step 3: Create `resources/js/lib/home.ts`**

```ts
import type {
    CommissionSplit,
    CostCentreRow,
    DealStage,
    HomeAlert,
    HomeProps,
    HomeTrend,
    Insight,
    LeadPipeline,
    LeadSource,
    LegacyMetrics,
    TopAgent,
} from '@/types/home';

export type Figure = {
    value: number | null;
    change: number | null;
    count: number | null;
    soon: boolean;
};

export type FigureKey =
    | 'revenue'
    | 'expenses'
    | 'net_profit'
    | 'cash_balance'
    | 'receivables'
    | 'payables'
    | 'vat_payable'
    | 'commission_payable'
    | 'active_deals'
    | 'expiring_contracts'
    | 'pdc_due'
    | 'bounced_cheques'
    | 'pending_approvals'
    | 'overdue_tasks';

export type HomeView = {
    hasKpis: boolean;
    figures: Record<FigureKey, Figure>;
    trend: HomeTrend | null;
    commission: CommissionSplit | null;
    topAgents: TopAgent[] | null;
    leadPipeline: LeadPipeline | null;
    leadSources: LeadSource[] | null;
    dealPipeline: DealStage[] | null;
    costCentres: CostCentreRow[] | null;
    insights: Insight[] | null;
    alerts: HomeAlert[];
    legacy: LegacyMetrics | null;
};

export type Sentence = {
    key: string;
    params?: Record<string, string | number>;
    emphasis?: boolean;
};

export type AttentionItem = {
    tag: string;
    tone: 'danger' | 'warning' | 'info' | 'brand';
    key: string;
    params: Record<string, string | number>;
    href: string | null;
    amount?: number;
    count?: number;
};

const SOON: Figure = { value: null, change: null, count: null, soon: true };

export function percentChange(
    value: number,
    previous: number | null,
): number | null {
    if (previous === null || previous === 0) {
        return null;
    }

    return Math.round(((value - previous) / Math.abs(previous)) * 1000) / 10;
}

function figure(
    value: number | null | undefined,
    previous: number | null = null,
    count: number | null = null,
): Figure {
    if (value === null || value === undefined) {
        return SOON;
    }

    return {
        value,
        change: percentChange(value, previous),
        count,
        soon: false,
    };
}

export function normalizeHome(props: HomeProps): HomeView {
    const k = props.kpis;

    return {
        hasKpis: Boolean(k),
        figures: {
            revenue: figure(k?.revenue.value, k?.revenue.previous ?? null),
            expenses: figure(k?.expenses.value, k?.expenses.previous ?? null),
            net_profit: figure(
                k?.net_profit.value,
                k?.net_profit.previous ?? null,
            ),
            cash_balance: k
                ? {
                      value: k.cash_balance.value,
                      change: k.cash_balance.change_7d_pct,
                      count: null,
                      soon: false,
                  }
                : SOON,
            receivables: figure(
                k ? k.receivables.value : props.metrics?.outstandingAed,
            ),
            payables: figure(k?.payables.value),
            vat_payable: figure(k?.vat_payable.value),
            commission_payable: figure(
                k?.commission_payable.value,
                null,
                k?.commission_payable.agents ?? null,
            ),
            active_deals: figure(k?.active_deals.count),
            expiring_contracts: figure(k?.expiring_contracts.count),
            pdc_due: figure(k?.pdc_due.amount, null, k?.pdc_due.count ?? null),
            bounced_cheques: figure(
                k?.bounced_cheques.amount,
                null,
                k?.bounced_cheques.count ?? null,
            ),
            pending_approvals: figure(k?.pending_approvals?.count),
            overdue_tasks: figure(k?.overdue_tasks?.count),
        },
        trend: props.trend ?? null,
        commission: props.commission_split ?? null,
        topAgents: props.top_agents ?? null,
        leadPipeline: props.lead_pipeline ?? null,
        leadSources: props.lead_sources ?? null,
        dealPipeline: props.deal_pipeline?.stages ?? null,
        costCentres: props.cost_centres ?? null,
        insights: props.insights ?? null,
        alerts: props.alerts ?? [],
        legacy: props.metrics ?? null,
    };
}

function pct(value: number): string {
    return `${Number(value.toFixed(1))}%`;
}

export function summarySentences(view: HomeView): Sentence[] {
    const sentences: Sentence[] = [];
    const revenue = view.figures.revenue;
    if (!revenue.soon && revenue.change !== null) {
        if (revenue.change > 0.05) {
            sentences.push({
                key: 'Revenue is up :change on the previous period.',
                params: { change: pct(revenue.change) },
            });
        } else if (revenue.change < -0.05) {
            sentences.push({
                key: 'Revenue is down :change on the previous period.',
                params: { change: pct(-revenue.change) },
            });
        } else {
            sentences.push({
                key: 'Revenue is level with the previous period.',
            });
        }
    } else if (!view.hasKpis) {
        sentences.push({ key: 'Here is where things stand today.' });
    }

    const bounced = view.figures.bounced_cheques.count ?? 0;
    if (bounced > 0) {
        sentences.push(
            bounced === 1
                ? { key: '1 cheque bounced this week.', emphasis: true }
                : {
                      key: ':count cheques bounced this week.',
                      params: { count: bounced },
                      emphasis: true,
                  },
        );
    }
    const approvals = view.figures.pending_approvals.value ?? 0;
    if (approvals > 0) {
        sentences.push(
            approvals === 1
                ? { key: '1 approval is waiting for you.', emphasis: true }
                : {
                      key: ':count approvals are waiting for you.',
                      params: { count: approvals },
                      emphasis: true,
                  },
        );
    }
    if (bounced === 0 && approvals === 0) {
        const alerts = view.alerts.reduce((sum, alert) => sum + alert.count, 0);
        sentences.push(
            alerts === 0
                ? { key: 'Nothing needs your attention right now.' }
                : alerts === 1
                  ? { key: '1 item needs your attention.', emphasis: true }
                  : {
                        key: ':count items need your attention.',
                        params: { count: alerts },
                        emphasis: true,
                    },
        );
    }

    return sentences;
}

export function attentionItems(view: HomeView): AttentionItem[] {
    const items: AttentionItem[] = [];
    const {
        bounced_cheques: bounced,
        pdc_due: due,
        expiring_contracts: expiring,
        pending_approvals: approvals,
    } = view.figures;
    if (!bounced.soon && (bounced.count ?? 0) > 0) {
        items.push({
            tag: 'PDC',
            tone: 'danger',
            key: ':count cheques bounced this week',
            params: { count: bounced.count ?? 0 },
            href: '/lease-compliance',
            amount: bounced.value ?? undefined,
        });
    }
    if (!due.soon && (due.count ?? 0) > 0) {
        items.push({
            tag: 'PDC',
            tone: 'warning',
            key: ':count cheques due in the next 30 days',
            params: { count: due.count ?? 0 },
            href: '/lease-compliance',
            amount: due.value ?? undefined,
        });
    }
    if (!expiring.soon && (expiring.value ?? 0) > 0) {
        items.push({
            tag: 'Lease',
            tone: 'warning',
            key: ':count contracts end in the next 30 days',
            params: { count: expiring.value ?? 0 },
            href: '/lease-compliance',
        });
    }
    if (!approvals.soon && (approvals.value ?? 0) > 0) {
        items.push({
            tag: 'Approval',
            tone: 'brand',
            key: ':count approvals are waiting',
            params: { count: approvals.value ?? 0 },
            href: null,
        });
    }
    for (const alert of view.alerts) {
        items.push({
            tag: 'Alert',
            tone: 'info',
            key: alert.title,
            params: {},
            href: alert.href,
            count: alert.count,
        });
    }

    return items;
}

export function greetingKey(hour: number): string {
    if (hour >= 5 && hour < 12) {
        return 'Good morning, :name.';
    }

    return hour >= 12 && hour < 17
        ? 'Good afternoon, :name.'
        : 'Good evening, :name.';
}
```

- [ ] **Step 4: All `home.test.mjs` tests pass.** Then format, typecheck, lint and commit: `feat(home): view model, summary sentences and attention list`.

---

### Task 6: Home components and page

**Files:**

- Create under `resources/js/components/home/`: `HomePanel.vue`, `ComingSoon.vue`, `HomeFigure.vue`, `FigureGroup.vue`, `HomeWelcome.vue`, `HomeFilters.vue`, `ValueTrend.vue`, `CommissionDonut.vue`, `AttentionList.vue`, `TopAgents.vue`, `InsightsPanel.vue`, `StageRail.vue`, `BarList.vue`, `CostCentreTable.vue`
- Modify: `resources/js/pages/Dashboard.vue` (full replacement), `resources/css/app.css` (brand-panel utility)

**Interfaces:**

- **Consumes:**
    - `HomeView`, `Figure`, `summarySentences`, `attentionItems`, `greetingKey` (Task 5)
    - `linePath`, `areaPath`, `donutSegments` (Task 4)
    - `formatMonth`, `statFigure`, `formatCompact`, `formatNumber` (phase 1 and Task 4)
    - `useFormat`, `useLocale`, `StatusDot`, `Button`

This task is markup and wiring on top of tested logic. Its test is the build, the PHP `DashboardTest` and a screenshot comparison with `home-dashboard.html` in light, dark and Arabic, for two data states. Every component below is written in full.

- [ ] **Step 1: Add the brand-panel utility** to `app.css`, after the `text-label` utility:

```css
@utility bg-brand-deep {
    background-image: linear-gradient(
        135deg,
        #4a1320 0%,
        #6b1b2a 55%,
        #8a2b40 100%
    );
    color: #fbf3ef;
}
```

Then add to the `.dark` block: `--brand-deep-start: #3a1520;`. Add a rule after the utility:

```css
.dark .bg-brand-deep {
    background-image: linear-gradient(
        135deg,
        #3a1520 0%,
        #5a1b2b 55%,
        #7a2438 100%
    );
}
```

Skip the `--brand-deep-start` variable if it isn't referenced anywhere; ledger that it isn't needed.

- [ ] **Step 2: Create the building blocks**

`ComingSoon.vue`:

```vue
<script setup lang="ts">
import { Clock } from '@lucide/vue';
import { useLocale } from '@/composables/useLocale';

withDefaults(defineProps<{ reason?: string; inverse?: boolean }>(), {
    reason: 'This section fills in as soon as its data is connected.',
    inverse: false,
});
const { t } = useLocale();
</script>

<template>
    <div
        :class="[
            'flex min-h-32 flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-6 text-center text-sm',
            inverse
                ? 'border-white/20 text-[#fbf3ef]/70'
                : 'text-muted-foreground',
        ]"
    >
        <Clock class="size-4 opacity-70" />
        <span class="font-medium">{{ t('Coming soon') }}</span>
        <span class="max-w-xs text-xs">{{ t(reason) }}</span>
    </div>
</template>
```

`HomePanel.vue`:

```vue
<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{ title: string; subtitle?: string; brand?: boolean }>(),
    { subtitle: undefined, brand: false },
);
const { t } = useLocale();
</script>

<template>
    <section
        :class="[
            'relative min-w-0 overflow-hidden rounded-xl border p-6',
            brand
                ? 'bg-brand-deep border-transparent shadow-overlay'
                : 'bg-card shadow-panel',
        ]"
    >
        <header class="mb-4 flex items-start justify-between gap-3">
            <div>
                <slot name="eyebrow" />
                <h2 class="font-display text-[23px] leading-tight font-medium">
                    {{ t(title) }}
                </h2>
                <p
                    v-if="subtitle"
                    :class="[
                        'mt-0.5 text-xs',
                        brand ? 'text-[#fbf3ef]/65' : 'text-muted-foreground',
                    ]"
                >
                    {{ t(subtitle) }}
                </p>
            </div>
            <slot name="action" />
        </header>
        <slot />
    </section>
</template>
```

`HomeFigure.vue`:

```vue
<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { statFigure } from '@/lib/format';
import type { Figure } from '@/lib/home';

const props = withDefaults(
    defineProps<{
        label: string;
        icon: Component;
        figure: Figure;
        kind?: 'money' | 'count';
        note?: string;
        noteParams?: Record<string, string | number>;
        invertTone?: boolean;
    }>(),
    {
        kind: 'money',
        note: undefined,
        noteParams: () => ({}),
        invertTone: false,
    },
);

const { t, locale } = useLocale();
const parts = computed(() =>
    statFigure(
        {
            value: props.figure.value,
            currency: props.kind === 'money' ? 'AED' : undefined,
            compact: false,
            decimals: 0,
        },
        locale.value,
    ),
);
const trend = computed(() => {
    const change = props.figure.change;
    if (change === null) {
        return null;
    }
    const good = props.invertTone ? change < 0 : change > 0;

    return {
        text: `${change > 0 ? '▲' : change < 0 ? '▼' : '■'} ${Math.abs(change)}%`,
        tone:
            change === 0
                ? 'text-muted-foreground'
                : good
                  ? 'text-success'
                  : 'text-destructive',
    };
});
</script>

<template>
    <div class="bg-card flex flex-col px-5 pt-4 pb-4">
        <span class="text-label flex items-center gap-1.5">
            <component :is="icon" class="text-faint size-3.5" />{{ t(label) }}
        </span>
        <span
            class="font-display mt-3 mb-2 text-[32px] leading-none font-medium whitespace-nowrap lining-nums tabular-nums"
        >
            <span
                v-if="parts.prefix"
                class="text-muted-foreground me-1.5 align-[0.8em] font-sans text-[10.5px] tracking-[0.12em]"
                >{{ parts.prefix }}</span
            >{{ parts.figure
            }}<span
                v-if="parts.suffix"
                class="text-muted-foreground ms-2 text-[0.45em]"
                >{{ parts.suffix }}</span
            >
        </span>
        <span class="text-xs">
            <span v-if="figure.soon" class="text-faint">{{
                t('Coming soon')
            }}</span>
            <template v-else>
                <span v-if="trend" :class="trend.tone">{{ trend.text }}</span>
                <span v-if="note" class="text-muted-foreground">
                    {{ trend ? ' · ' : '' }}{{ t(note, noteParams) }}</span
                >
            </template>
        </span>
    </div>
</template>
```

`FigureGroup.vue`:

```vue
<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

defineProps<{ title: string; subtitle?: string }>();
const { t } = useLocale();
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex items-baseline justify-between gap-3 px-0.5">
            <h2 class="font-display text-2xl font-medium">{{ t(title) }}</h2>
            <span v-if="subtitle" class="text-muted-foreground text-xs">{{
                t(subtitle)
            }}</span>
        </div>
        <div
            class="bg-border shadow-panel grid grid-cols-2 gap-px overflow-hidden rounded-xl border md:grid-cols-3 xl:grid-cols-6"
        >
            <slot />
        </div>
    </section>
</template>
```

- [ ] **Step 3: Create the welcome panel and filters**

`HomeWelcome.vue`:

```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CheckCheck, ChartLine, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { statFigure } from '@/lib/format';
import type { Figure, HomeView } from '@/lib/home';
import { greetingKey, summarySentences } from '@/lib/home';

const props = defineProps<{ view: HomeView }>();
const page = usePage();
const { t, locale } = useLocale();
const now = new Date();
const firstName = computed(
    () => String(page.props.auth.user.name ?? '').split(' ')[0] || '',
);
const dateLine = computed(() =>
    new Intl.DateTimeFormat(
        locale.value === 'ar' ? 'ar-AE-u-nu-latn' : 'en-GB',
        { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' },
    ).format(now),
);
const sentences = computed(() => summarySentences(props.view));
const headline = computed<{ label: string; figure: Figure; note: string }[]>(
    () => [
        { label: 'Revenue', figure: props.view.figures.revenue, note: '' },
        {
            label: 'Net profit',
            figure: props.view.figures.net_profit,
            note: '',
        },
        {
            label: 'Cash balance',
            figure: props.view.figures.cash_balance,
            note: '',
        },
        {
            label: 'Commission payable',
            figure: props.view.figures.commission_payable,
            note: '',
        },
    ],
);

function money(figure: Figure) {
    return statFigure(
        { value: figure.value, currency: 'AED', compact: false, decimals: 0 },
        locale.value,
    );
}
</script>

<template>
    <section
        class="bg-brand-deep shadow-overlay relative grid gap-7 overflow-hidden rounded-xl p-7 lg:grid-cols-[1.4fr_1fr]"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -end-24 -top-40 size-[520px] rounded-full bg-[radial-gradient(closest-side,rgba(201,162,122,0.28),transparent)]"
        />
        <div class="relative">
            <p class="text-eyebrow !text-[#e6c9a2]">
                {{ t('Home') }} · {{ dateLine }}
            </p>
            <h1
                class="font-display mt-2 mb-3 text-4xl leading-[1.05] font-medium md:text-[44px]"
            >
                {{ t(greetingKey(now.getHours()), { name: firstName }) }}
            </h1>
            <p class="max-w-xl text-sm leading-relaxed text-[#fbf3ef]/80">
                <template v-for="(sentence, index) in sentences" :key="index">
                    <span
                        :class="
                            sentence.emphasis ? 'font-medium text-white' : ''
                        "
                        >{{ t(sentence.key, sentence.params ?? {}) }}</span
                    >{{ ' ' }}
                </template>
            </p>
            <div class="mt-5 flex flex-wrap gap-2">
                <span
                    class="inline-flex h-9 cursor-not-allowed items-center gap-2 rounded-md bg-[#e6c9a2]/60 px-4 text-sm font-medium text-[#3a1520]"
                    :title="t('Soon')"
                    ><CheckCheck class="size-4" />{{
                        t('Review approvals')
                    }}</span
                >
                <Link
                    href="/reservations"
                    class="inline-flex h-9 items-center gap-2 rounded-md border border-white/25 bg-white/10 px-4 text-sm font-medium hover:bg-white/15"
                    ><Plus class="size-4" />{{ t('New deal') }}</Link
                >
                <Link
                    href="/reports/property-profitability"
                    class="inline-flex h-9 items-center gap-2 rounded-md border border-white/25 bg-white/10 px-4 text-sm font-medium hover:bg-white/15"
                    ><ChartLine class="size-4" />{{ t('Reports') }}</Link
                >
            </div>
        </div>
        <div
            class="relative grid grid-cols-2 gap-px self-center overflow-hidden rounded-lg bg-white/15"
        >
            <div
                v-for="item in headline"
                :key="item.label"
                class="bg-[#3a1520]/55 p-4 backdrop-blur-sm"
            >
                <span
                    class="text-[10px] tracking-[0.16em] text-[#fbf3ef]/65 uppercase"
                    >{{ t(item.label) }}</span
                >
                <span
                    class="font-display mt-1.5 block text-[28px] leading-none lining-nums tabular-nums"
                >
                    <span
                        v-if="money(item.figure).prefix"
                        class="me-1.5 align-[0.7em] font-sans text-[10px] tracking-[0.12em] text-[#fbf3ef]/60"
                        >{{ money(item.figure).prefix }}</span
                    >{{ money(item.figure).figure
                    }}<span
                        v-if="money(item.figure).suffix"
                        class="ms-2 text-[0.45em]"
                        >{{ money(item.figure).suffix }}</span
                    >
                </span>
                <span class="mt-1 block text-[11px] text-[#fbf3ef]/60">
                    <template v-if="item.figure.soon">{{
                        t('Coming soon')
                    }}</template>
                    <template v-else-if="item.figure.change !== null"
                        >{{ item.figure.change > 0 ? '▲' : '▼' }}
                        {{ Math.abs(item.figure.change) }}%</template
                    >
                    <template
                        v-else-if="
                            item.label === 'Commission payable' &&
                            item.figure.count
                        "
                        >{{
                            t('across :count agents', {
                                count: item.figure.count,
                            })
                        }}</template
                    >
                </span>
            </div>
        </div>
    </section>
</template>
```

`HomeFilters.vue`:

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { HomeFilters } from '@/types/home';

const props = defineProps<{ filters?: HomeFilters }>();
const { t } = useLocale();
const period = computed(() => props.filters?.period ?? 'month');
const purpose = computed(() => props.filters?.purpose ?? 'all');

function apply(change: Record<string, string | number | null>): void {
    router.get(
        '/dashboard',
        {
            period: period.value,
            purpose: purpose.value,
            company: props.filters?.company_id ?? null,
            branch: props.filters?.branch_id ?? null,
            ...change,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const periods = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
] as const;
const purposes = [
    { value: 'all', label: 'Sale & rent' },
    { value: 'sale', label: 'Sale' },
    { value: 'rent', label: 'Rent' },
] as const;
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div
            v-for="(group, index) in [
                { options: periods, current: period, key: 'period' },
                { options: purposes, current: purpose, key: 'purpose' },
            ]"
            :key="index"
            class="bg-card inline-flex rounded-md border p-0.5"
            role="group"
        >
            <button
                v-for="option in group.options"
                :key="option.value"
                type="button"
                :aria-pressed="group.current === option.value"
                :class="[
                    'rounded px-3 py-1 text-xs font-medium transition-colors',
                    group.current === option.value
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="apply({ [group.key]: option.value })"
            >
                {{ t(option.label) }}
            </button>
        </div>
        <select
            v-if="filters?.options.companies.length"
            class="h-8 rounded-md border px-2 text-xs"
            :value="filters.company_id ?? ''"
            :aria-label="t('Company')"
            @change="
                apply({
                    company: ($event.target as HTMLSelectElement).value || null,
                    branch: null,
                })
            "
        >
            <option value="">{{ t('All companies') }}</option>
            <option
                v-for="company in filters.options.companies"
                :key="company.id"
                :value="company.id"
            >
                {{ company.name }}
            </option>
        </select>
        <select
            v-if="filters?.options.branches.length"
            class="h-8 rounded-md border px-2 text-xs"
            :value="filters.branch_id ?? ''"
            :aria-label="t('Branch')"
            @change="
                apply({
                    branch: ($event.target as HTMLSelectElement).value || null,
                })
            "
        >
            <option value="">{{ t('All branches') }}</option>
            <option
                v-for="branch in filters.options.branches.filter(
                    (b) =>
                        !filters?.company_id ||
                        b.company_id === filters.company_id,
                )"
                :key="branch.id"
                :value="branch.id"
            >
                {{ branch.name }}
            </option>
        </select>
        <span
            v-if="filters?.range"
            class="text-muted-foreground ms-auto text-xs"
            >{{ filters.range.from }} → {{ filters.range.to }}</span
        >
    </div>
</template>
```

- [ ] **Step 4: Create the chart panels**

`ValueTrend.vue`:

```vue
<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useLocale } from '@/composables/useLocale';
import { areaPath, linePath } from '@/lib/chart-paths';
import { formatCompact, formatMonth } from '@/lib/format';
import type { HomeTrend } from '@/types/home';

const props = defineProps<{ trend: HomeTrend | null }>();
const { t, locale } = useLocale();
const span = ref<3 | 6 | 12>(12);
const id = useId();
const W = 760;
const H = 230;

const slice = computed(() => {
    if (!props.trend) {
        return null;
    }
    const from = Math.max(0, props.trend.months.length - span.value);

    return {
        months: props.trend.months.slice(from),
        sales: props.trend.sales_value.slice(from),
        rental: props.trend.rental_value.slice(from),
    };
});
const domain = computed<[number, number]>(() => {
    const values = [
        ...(slice.value?.sales ?? []),
        ...(slice.value?.rental ?? []),
    ];

    return [0, Math.max(1, ...values) * 1.1];
});
const total = computed(
    () =>
        (props.trend?.sales_value ?? []).reduce((sum, v) => sum + v, 0) +
        (props.trend?.rental_value ?? []).reduce((sum, v) => sum + v, 0),
);
</script>

<template>
    <HomePanel
        title="Sales & rental value"
        subtitle="Closed transaction value, AED"
    >
        <template #action>
            <div
                v-if="trend"
                class="bg-surface-sunken inline-flex rounded-md border p-0.5 text-xs"
            >
                <button
                    v-for="n in [3, 6, 12] as const"
                    :key="n"
                    type="button"
                    :class="[
                        'rounded px-2.5 py-0.5',
                        span === n
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground',
                    ]"
                    @click="span = n"
                >
                    {{ n }}M
                </button>
            </div>
        </template>
        <ComingSoon v-if="!slice" />
        <template v-else>
            <div
                class="text-muted-foreground mb-2 flex flex-wrap gap-5 text-xs"
            >
                <span class="inline-flex items-center gap-2"
                    ><i class="bg-chart-1 h-0.5 w-3 rounded" />{{
                        t('Sales value')
                    }}</span
                >
                <span class="inline-flex items-center gap-2"
                    ><i class="bg-chart-2 h-0.5 w-3 rounded" />{{
                        t('Rental value')
                    }}</span
                >
                <span class="ms-auto"
                    >{{ t('12-month total') }}
                    <b class="text-foreground font-medium"
                        >AED {{ formatCompact(total, locale) }}</b
                    ></span
                >
            </div>
            <figure dir="ltr">
                <svg
                    :viewBox="`0 -6 ${W} ${H + 8}`"
                    class="block h-auto w-full"
                    role="img"
                    :aria-label="t('Sales & rental value')"
                >
                    <defs>
                        <linearGradient
                            :id="`${id}-s`"
                            x1="0"
                            x2="0"
                            y1="0"
                            y2="1"
                        >
                            <stop
                                offset="0"
                                stop-color="var(--chart-1)"
                                stop-opacity="0.22"
                            />
                            <stop
                                offset="1"
                                stop-color="var(--chart-1)"
                                stop-opacity="0"
                            />
                        </linearGradient>
                        <linearGradient
                            :id="`${id}-r`"
                            x1="0"
                            x2="0"
                            y1="0"
                            y2="1"
                        >
                            <stop
                                offset="0"
                                stop-color="var(--chart-2)"
                                stop-opacity="0.25"
                            />
                            <stop
                                offset="1"
                                stop-color="var(--chart-2)"
                                stop-opacity="0"
                            />
                        </linearGradient>
                    </defs>
                    <line
                        v-for="f in [0.25, 0.5, 0.75]"
                        :key="f"
                        x1="0"
                        :x2="W"
                        :y1="H * f"
                        :y2="H * f"
                        class="stroke-border"
                        stroke-dasharray="2 5"
                    />
                    <path
                        :d="areaPath(slice.sales, W, H, 18, domain)"
                        :fill="`url(#${id}-s)`"
                    />
                    <path
                        :d="linePath(slice.sales, W, H, 18, domain)"
                        fill="none"
                        stroke="var(--chart-1)"
                        stroke-width="2"
                    />
                    <path
                        :d="areaPath(slice.rental, W, H, 18, domain)"
                        :fill="`url(#${id}-r)`"
                    />
                    <path
                        :d="linePath(slice.rental, W, H, 18, domain)"
                        fill="none"
                        stroke="var(--chart-2)"
                        stroke-width="2"
                    />
                </svg>
                <figcaption
                    class="text-faint mt-2 flex justify-between text-[10.5px]"
                >
                    <span v-for="month in slice.months" :key="month">{{
                        formatMonth(month, locale)
                    }}</span>
                </figcaption>
            </figure>
        </template>
    </HomePanel>
</template>
```

`CommissionDonut.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import { donutSegments } from '@/lib/chart-paths';
import type { CommissionSplit } from '@/types/home';

const props = defineProps<{ split: CommissionSplit | null }>();
const { t } = useLocale();
const { money, compact } = useFormat();
const R = 66;
const C = 2 * Math.PI * R;
const parts = computed(() => {
    if (!props.split) {
        return [];
    }
    const s = props.split;

    return [
        { label: 'Net company', value: s.net_company, color: 'var(--chart-1)' },
        {
            label: 'Agent payable',
            value: s.agent_payable,
            color: 'var(--chart-2)',
        },
        { label: 'Co-broker', value: s.co_broker, color: 'var(--chart-3)' },
        { label: 'Referral', value: s.referral, color: 'var(--chart-4)' },
    ];
});
const total = computed(() => parts.value.reduce((sum, p) => sum + p.value, 0));
const segments = computed(() =>
    donutSegments(
        parts.value.map((p) => p.value),
        C,
        3,
    ),
);
</script>

<template>
    <HomePanel title="Commission" subtitle="Distribution this period">
        <ComingSoon v-if="!split" />
        <p
            v-else-if="total === 0"
            class="text-muted-foreground py-10 text-center text-sm"
        >
            {{ t('No commission earned this period.') }}
        </p>
        <div v-else class="grid grid-cols-[170px_1fr] items-center gap-5">
            <svg viewBox="0 0 170 170" class="size-[170px]">
                <circle
                    v-for="(segment, i) in segments"
                    :key="i"
                    :r="R"
                    cx="85"
                    cy="85"
                    fill="none"
                    :stroke="parts[i].color"
                    stroke-width="16"
                    :stroke-dasharray="`${segment.length} ${C}`"
                    :stroke-dashoffset="segment.offset"
                    transform="rotate(-90 85 85)"
                />
                <text
                    x="85"
                    y="80"
                    text-anchor="middle"
                    font-size="10"
                    letter-spacing="1.5"
                    fill="var(--muted-foreground)"
                >
                    {{ t('TOTAL') }}
                </text>
                <text
                    x="85"
                    y="104"
                    text-anchor="middle"
                    class="font-display"
                    font-size="26"
                    fill="var(--foreground)"
                >
                    {{ compact(total) }}
                </text>
            </svg>
            <ul class="flex flex-col gap-3 text-[13px]">
                <li
                    v-for="part in parts"
                    :key="part.label"
                    class="grid grid-cols-[8px_1fr_auto] items-center gap-2.5"
                >
                    <i
                        class="size-2 rounded-full"
                        :style="{ background: part.color }"
                    />
                    <span>{{ t(part.label) }}</span>
                    <b class="font-medium tabular-nums"
                        >{{ Math.round((part.value / total) * 100) }}%</b
                    >
                    <small
                        class="text-muted-foreground col-span-2 col-start-2 -mt-2 text-[11px]"
                        >{{ money(part.value, 'AED', { decimals: 0 }) }}</small
                    >
                </li>
            </ul>
        </div>
    </HomePanel>
</template>
```

- [ ] **Step 5: Create the list panels**

`AttentionList.vue`:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import type { HomeView } from '@/lib/home';
import { attentionItems } from '@/lib/home';

const props = defineProps<{ view: HomeView }>();
const { t } = useLocale();
const { money } = useFormat();
const items = computed(() => attentionItems(props.view));
const tones = {
    danger: 'bg-destructive/10 text-destructive',
    warning: 'bg-warning/12 text-warning',
    info: 'bg-info/10 text-info',
    brand: 'bg-primary/10 text-accent-text',
} as const;
</script>

<template>
    <HomePanel title="Needs attention" subtitle="Most urgent first">
        <p
            v-if="items.length === 0"
            class="text-muted-foreground py-10 text-center text-sm"
        >
            {{ t('Nothing needs your attention right now.') }}
        </p>
        <ul v-else class="divide-y">
            <li v-for="(item, i) in items" :key="i" class="py-3 first:pt-0">
                <component
                    :is="item.href ? Link : 'div'"
                    :href="item.href ?? undefined"
                    class="grid grid-cols-[auto_1fr_auto] items-start gap-3"
                >
                    <span
                        :class="[
                            'mt-0.5 rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-[0.1em] uppercase',
                            tones[item.tone],
                        ]"
                        >{{ t(item.tag) }}</span
                    >
                    <span>
                        <b class="block font-medium">{{
                            t(item.key, item.params)
                        }}</b>
                        <small
                            v-if="item.amount !== undefined"
                            class="text-muted-foreground text-xs"
                            >{{
                                money(item.amount, 'AED', { decimals: 0 })
                            }}</small
                        >
                        <small
                            v-else-if="item.count !== undefined"
                            class="text-muted-foreground text-xs"
                            >{{ item.count }}</small
                        >
                    </span>
                    <ChevronRight
                        v-if="item.href"
                        class="text-faint size-4 rtl:-scale-x-100"
                    />
                </component>
            </li>
        </ul>
    </HomePanel>
</template>
```

`TopAgents.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { useInitials } from '@/composables/useInitials';
import { useLocale } from '@/composables/useLocale';
import type { TopAgent } from '@/types/home';

const props = defineProps<{ agents: TopAgent[] | null }>();
const { t } = useLocale();
const { money } = useFormat();
const { getInitials } = useInitials();
const top = computed(() =>
    Math.max(1, ...(props.agents ?? []).map((a) => a.commission)),
);
</script>

<template>
    <HomePanel title="Top agents" subtitle="Commission earned this period">
        <ComingSoon v-if="!agents" />
        <p
            v-else-if="agents.length === 0"
            class="text-muted-foreground py-10 text-center text-sm"
        >
            {{ t('No agents earned commission this period.') }}
        </p>
        <ol v-else class="flex flex-col gap-3.5">
            <li
                v-for="(agent, i) in agents"
                :key="agent.user_id"
                class="grid grid-cols-[22px_34px_1fr_auto] items-center gap-3"
            >
                <span
                    :class="[
                        'font-display text-center text-xl',
                        i === 0 ? 'text-champagne' : 'text-faint',
                    ]"
                    >{{ i + 1 }}</span
                >
                <span
                    class="bg-surface-sunken grid size-[34px] place-items-center rounded-full border text-[11px] font-semibold"
                    >{{ getInitials(agent.name) }}</span
                >
                <span class="min-w-0">
                    <b class="block truncate font-medium">{{ agent.name }}</b>
                    <span
                        class="bg-surface-sunken mt-1.5 block h-1 overflow-hidden rounded"
                    >
                        <span
                            class="block h-full rounded bg-gradient-to-r from-[var(--primary)] to-[var(--champagne)] rtl:bg-gradient-to-l"
                            :style="{
                                width: `${(agent.commission / top) * 100}%`,
                            }"
                        />
                    </span>
                </span>
                <span class="text-end">
                    <b class="font-medium tabular-nums">{{
                        money(agent.commission, 'AED', { decimals: 0 })
                    }}</b>
                    <small class="text-muted-foreground block text-[11px]">{{
                        agent.team ?? t(':count deals', { count: agent.deals })
                    }}</small>
                </span>
            </li>
        </ol>
    </HomePanel>
</template>
```

`InsightsPanel.vue`:

```vue
<script setup lang="ts">
import {
    MessageCircle,
    Sparkles,
    TrendingUp,
    TriangleAlert,
    Target,
    Scale,
} from '@lucide/vue';
import type { Component } from 'vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useLocale } from '@/composables/useLocale';
import type { Insight } from '@/types/home';

defineProps<{ insights: Insight[] | null }>();
const { t } = useLocale();
const icons: Record<string, Component> = {
    'trending-up': TrendingUp,
    'alert-triangle': TriangleAlert,
    target: Target,
    scale: Scale,
};
</script>

<template>
    <HomePanel title="This week's insights" brand>
        <template #eyebrow>
            <span
                class="mb-1 inline-flex items-center gap-1.5 text-[10px] tracking-[0.18em] text-[#e6c9a2] uppercase"
            >
                <Sparkles class="size-3" />Z1 Intelligence
            </span>
        </template>
        <ComingSoon
            v-if="!insights || insights.length === 0"
            inverse
            reason="Z1 Intelligence will surface trends and risks from your data here."
        />
        <ul v-else class="relative flex flex-col">
            <li
                v-for="(insight, i) in insights"
                :key="i"
                class="grid grid-cols-[26px_1fr] gap-2.5 border-t border-white/12 py-3 first:border-t-0 first:pt-0"
            >
                <component
                    :is="icons[insight.icon] ?? Sparkles"
                    class="mt-0.5 size-4 text-[#e6c9a2]"
                />
                <p class="text-[13px] text-[#fbf3ef]/85">{{ insight.text }}</p>
            </li>
        </ul>
        <div
            class="relative mt-3 flex h-10 items-center gap-2.5 rounded-md border border-white/18 bg-white/8 px-3 text-sm text-[#fbf3ef]/55"
            aria-disabled="true"
        >
            <MessageCircle class="size-4 text-[#e6c9a2]" />{{
                t('Ask about your portfolio…')
            }}
            <span class="ms-auto text-[10px] tracking-[0.1em] uppercase">{{
                t('Soon')
            }}</span>
        </div>
    </HomePanel>
</template>
```

- [ ] **Step 6: Create the pipeline and table blocks**

`StageRail.vue`:

```vue
<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

const props = defineProps<{
    stages: { label: string; count: number; won?: boolean }[];
}>();
const { t } = useLocale();
const first = () => props.stages[0]?.count || 0;
</script>

<template>
    <div class="flex overflow-x-auto">
        <div
            v-for="(stage, i) in stages"
            :key="stage.label"
            :class="[
                'relative min-w-[92px] flex-1 border px-3 pt-3 pb-2.5',
                i > 0 ? 'border-s-0' : 'rounded-s-lg',
                i === stages.length - 1 ? 'rounded-e-lg' : '',
                stage.won ? 'bg-success/8' : 'bg-surface-sunken',
            ]"
        >
            <span
                class="text-muted-foreground block truncate text-[10px] tracking-[0.14em] uppercase"
                >{{ t(stage.label) }}</span
            >
            <b
                :class="[
                    'font-display block text-[28px] leading-tight font-medium lining-nums',
                    stage.won ? 'text-success' : '',
                ]"
                >{{ stage.count }}</b
            >
            <span class="text-muted-foreground text-[11px]">{{
                i > 0 && first()
                    ? t(':percent of start', {
                          percent:
                              Math.round((stage.count / first()) * 100) + '%',
                      })
                    : ' '
            }}</span>
            <i
                class="absolute start-0 bottom-0 h-[3px] bg-gradient-to-r from-[var(--primary)] to-[var(--champagne)]"
                :style="{
                    width: `${Math.max(6, first() ? (stage.count / first()) * 100 : 0)}%`,
                }"
            />
        </div>
    </div>
</template>
```

`BarList.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    rows: { label: string; value: number; display?: string }[];
}>();
const max = computed(() => Math.max(1, ...props.rows.map((r) => r.value)));
const colors = [
    'var(--chart-1)',
    'var(--chart-5)',
    'var(--chart-2)',
    'var(--chart-4)',
    'var(--chart-3)',
];
</script>

<template>
    <ul class="flex flex-col gap-2.5">
        <li
            v-for="(row, i) in rows"
            :key="row.label"
            class="grid grid-cols-[120px_1fr_64px] items-center gap-3 text-[13px]"
        >
            <span class="truncate">{{ row.label }}</span>
            <span class="bg-surface-sunken h-2 overflow-hidden rounded">
                <span
                    class="block h-full rounded"
                    :style="{
                        width: `${(row.value / max) * 100}%`,
                        background: colors[i % colors.length],
                    }"
                />
            </span>
            <b class="text-end font-medium tabular-nums">{{
                row.display ?? row.value
            }}</b>
        </li>
    </ul>
</template>
```

`CostCentreTable.vue`:

```vue
<script setup lang="ts">
import { Building2, MapPin } from '@lucide/vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import type { CostCentreRow } from '@/types/home';

defineProps<{ rows: CostCentreRow[] | null }>();
const { t } = useLocale();
const { number } = useFormat();
const margin = (row: CostCentreRow) =>
    row.revenue
        ? Math.round((row.profit / row.revenue) * 100)
        : row.profit < 0
          ? -100
          : 0;
</script>

<template>
    <HomePanel
        title="Cost centre profitability"
        subtitle="Company → branch → department → cost centre"
    >
        <ComingSoon
            v-if="!rows || rows.length === 0"
            reason="Available once companies, branches and cost centres are set up."
        />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-label border-b">
                        <th class="py-2.5 pe-3 text-start font-medium">
                            {{ t('Company / branch') }}
                        </th>
                        <th class="px-3 text-start font-medium">
                            {{ t('Department') }}
                        </th>
                        <th class="px-3 text-start font-medium">
                            {{ t('Cost centre') }}
                        </th>
                        <th class="px-3 text-end font-medium">
                            {{ t('Revenue') }}
                        </th>
                        <th class="px-3 text-end font-medium">
                            {{ t('Expenses') }}
                        </th>
                        <th class="px-3 text-end font-medium">
                            {{ t('Profit') }}
                        </th>
                        <th class="ps-3 text-start font-medium">
                            {{ t('Margin') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, i) in rows"
                        :key="i"
                        :class="[
                            'border-b last:border-b-0',
                            row.level === 0 ? 'font-semibold' : '',
                        ]"
                    >
                        <td
                            :class="[
                                'py-3 pe-3',
                                row.level === 1
                                    ? 'ps-5'
                                    : row.level === 2
                                      ? 'text-muted-foreground ps-10'
                                      : '',
                            ]"
                        >
                            <Building2
                                v-if="row.level === 0"
                                class="text-faint me-1.5 inline size-3.5"
                            />
                            <MapPin
                                v-else-if="row.level === 1"
                                class="text-faint me-1.5 inline size-3.5"
                            />
                            {{
                                row.level === 0
                                    ? row.company
                                    : row.level === 1
                                      ? row.branch
                                      : ''
                            }}
                        </td>
                        <td class="px-3">{{ row.department ?? '' }}</td>
                        <td class="px-3">{{ row.cost_centre ?? '' }}</td>
                        <td class="px-3 text-end tabular-nums">
                            {{ number(row.revenue) }}
                        </td>
                        <td class="px-3 text-end tabular-nums">
                            {{ number(row.expense) }}
                        </td>
                        <td
                            :class="[
                                'px-3 text-end tabular-nums',
                                row.profit < 0
                                    ? 'text-destructive'
                                    : row.profit > 0
                                      ? 'text-success'
                                      : '',
                            ]"
                        >
                            {{ number(row.profit) }}
                        </td>
                        <td class="ps-3 whitespace-nowrap">
                            <span class="text-muted-foreground text-xs"
                                >{{ margin(row) }}%</span
                            >
                            <span
                                class="bg-surface-sunken relative ms-2 inline-block h-1 w-16 overflow-hidden rounded align-middle"
                            >
                                <span
                                    :class="[
                                        'absolute inset-y-0 start-0 rounded',
                                        margin(row) < 0
                                            ? 'bg-destructive'
                                            : 'bg-success',
                                    ]"
                                    :style="{
                                        width: `${Math.min(100, Math.abs(margin(row)))}%`,
                                    }"
                                />
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </HomePanel>
</template>
```

- [ ] **Step 7: Replace `resources/js/pages/Dashboard.vue`**

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Banknote,
    BadgePercent,
    CalendarClock,
    Clock3,
    FileMinus,
    FileText,
    Gem,
    Handshake,
    ListTodo,
    Receipt,
    SquareCheck,
    TrendingDown,
    TrendingUp,
    Wallet,
    Building,
    UsersRound,
    Wrench,
    CalendarCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import AttentionList from '@/components/home/AttentionList.vue';
import BarList from '@/components/home/BarList.vue';
import CommissionDonut from '@/components/home/CommissionDonut.vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import CostCentreTable from '@/components/home/CostCentreTable.vue';
import FigureGroup from '@/components/home/FigureGroup.vue';
import HomeFigure from '@/components/home/HomeFigure.vue';
import HomeFilters from '@/components/home/HomeFilters.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import HomeWelcome from '@/components/home/HomeWelcome.vue';
import InsightsPanel from '@/components/home/InsightsPanel.vue';
import StageRail from '@/components/home/StageRail.vue';
import TopAgents from '@/components/home/TopAgents.vue';
import ValueTrend from '@/components/home/ValueTrend.vue';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import { normalizeHome } from '@/lib/home';
import type { HomeProps } from '@/types/home';

const props = defineProps<HomeProps>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Home', href: '/dashboard' }] },
});

const { t } = useLocale();
const { compact } = useFormat();
const view = computed(() => normalizeHome(props));
const f = computed(() => view.value.figures);
const count = (value: number | undefined) => ({
    value: value ?? null,
    change: null,
    count: null,
    soon: value === undefined,
});
</script>

<template>
    <Head :title="t('Home')" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <HomeFilters :filters="filters" />
        <HomeWelcome :view="view" />

        <FigureGroup
            title="Financial position"
            subtitle="Company-wide, selected period"
        >
            <HomeFigure
                label="Revenue"
                :icon="TrendingUp"
                :figure="f.revenue"
            />
            <HomeFigure
                label="Expenses"
                :icon="TrendingDown"
                :figure="f.expenses"
                invert-tone
            />
            <HomeFigure label="Net profit" :icon="Gem" :figure="f.net_profit" />
            <HomeFigure
                label="Cash balance"
                :icon="Wallet"
                :figure="f.cash_balance"
                note="this week"
            />
            <HomeFigure
                label="Receivables"
                :icon="FileText"
                :figure="f.receivables"
                note="Customer ledger"
            />
            <HomeFigure
                label="Payables"
                :icon="FileMinus"
                :figure="f.payables"
                note="Vendor ledger"
            />
        </FigureGroup>

        <FigureGroup
            title="Pipeline & obligations"
            subtitle="What needs action"
        >
            <HomeFigure
                label="Active deals"
                :icon="Handshake"
                :figure="f.active_deals"
                kind="count"
                note="In pipeline"
            />
            <HomeFigure
                label="Pending approvals"
                :icon="SquareCheck"
                :figure="f.pending_approvals"
                kind="count"
            />
            <HomeFigure
                label="Overdue tasks"
                :icon="ListTodo"
                :figure="f.overdue_tasks"
                kind="count"
            />
            <HomeFigure
                label="Expiring contracts"
                :icon="CalendarClock"
                :figure="f.expiring_contracts"
                kind="count"
                note="Next 30 days"
            />
            <HomeFigure
                label="Cheques due"
                :icon="Banknote"
                :figure="f.pdc_due"
                note=":count in the next 30 days"
                :note-params="{ count: f.pdc_due.count ?? 0 }"
            />
            <HomeFigure
                label="VAT payable"
                :icon="Receipt"
                :figure="f.vat_payable"
                note="Net of input tax"
            />
        </FigureGroup>

        <FigureGroup
            v-if="view.legacy"
            title="Portfolio & operations"
            subtitle="Live counts"
        >
            <HomeFigure
                label="Available units"
                :icon="Building"
                :figure="count(view.legacy.availableUnits)"
                kind="count"
            />
            <HomeFigure
                label="Reserved units"
                :icon="CalendarCheck"
                :figure="count(view.legacy.reservedUnits)"
                kind="count"
            />
            <HomeFigure
                label="Active leads"
                :icon="UsersRound"
                :figure="count(view.legacy.activeLeads)"
                kind="count"
            />
            <HomeFigure
                label="Open maintenance"
                :icon="Wrench"
                :figure="count(view.legacy.openMaintenance)"
                kind="count"
            />
            <HomeFigure
                label="Overdue maintenance"
                :icon="Clock3"
                :figure="count(view.legacy.overdueMaintenance)"
                kind="count"
            />
            <HomeFigure
                label="Commission payable"
                :icon="BadgePercent"
                :figure="f.commission_payable"
            />
        </FigureGroup>

        <div class="grid gap-6 xl:grid-cols-[2fr_1fr]">
            <ValueTrend :trend="view.trend" />
            <CommissionDonut :split="view.commission" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2 xl:grid-cols-3">
            <AttentionList :view="view" />
            <TopAgents :agents="view.topAgents" />
            <InsightsPanel :insights="view.insights" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <HomePanel
                title="Lead pipeline"
                :subtitle="view.leadPipeline?.pipeline?.name ?? 'CRM'"
            >
                <template #action>
                    <Link href="/crm/leads" class="text-accent-text text-xs"
                        >{{ t('Open CRM') }} →</Link
                    >
                </template>
                <ComingSoon v-if="!view.leadPipeline" />
                <template v-else>
                    <StageRail
                        :stages="
                            view.leadPipeline.stages.map((s) => ({
                                label: s.name,
                                count: s.count,
                                won: s.type === 'won',
                            }))
                        "
                    />
                    <p class="text-muted-foreground mt-5 mb-2.5 text-xs">
                        {{ t('Leads by source') }}
                    </p>
                    <BarList
                        v-if="view.leadSources?.length"
                        :rows="
                            view.leadSources.map((s) => ({
                                label: s.source,
                                value: s.count,
                            }))
                        "
                    />
                    <p v-else class="text-muted-foreground text-sm">
                        {{ t('No new leads this period.') }}
                    </p>
                </template>
            </HomePanel>
            <HomePanel title="Deal pipeline" subtitle="Deals & commission">
                <template #action>
                    <Link href="/agreements" class="text-accent-text text-xs"
                        >{{ t('Open deals') }} →</Link
                    >
                </template>
                <ComingSoon v-if="!view.dealPipeline" />
                <template v-else>
                    <StageRail
                        :stages="
                            view.dealPipeline.map((s) => ({
                                label: s.label,
                                count: s.count,
                                won: ['closed', 'settled'].includes(s.key),
                            }))
                        "
                    />
                    <p class="text-muted-foreground mt-5 mb-2.5 text-xs">
                        {{ t('Deal value by stage') }}
                    </p>
                    <BarList
                        :rows="
                            view.dealPipeline.map((s) => ({
                                label: t(s.label),
                                value: s.value,
                                display: compact(s.value),
                            }))
                        "
                    />
                    <div
                        class="text-muted-foreground mt-4 flex items-center justify-between border-t pt-3.5 text-[13px]"
                    >
                        <span>{{
                            t('Commission payable across all agents')
                        }}</span>
                        <b
                            class="font-display text-foreground text-[22px] font-medium"
                            >{{
                                f.commission_payable.soon
                                    ? '—'
                                    : `AED ${compact(f.commission_payable.value)}`
                            }}</b
                        >
                    </div>
                </template>
            </HomePanel>
        </div>

        <CostCentreTable :rows="view.costCentres" />
    </div>
</template>
```

- [ ] **Step 8: Add the Arabic keys**, only the missing ones, then run the sync script:

```json
{
    "Financial position": "المركز المالي",
    "Company-wide, selected period": "على مستوى الشركة، للفترة المحددة",
    "Revenue": "الإيرادات",
    "Expenses": "المصروفات",
    "Net profit": "صافي الربح",
    "Cash balance": "الرصيد النقدي",
    "this week": "هذا الأسبوع",
    "Customer ledger": "دفتر العملاء",
    "Vendor ledger": "دفتر الموردين",
    "Pipeline & obligations": "الصفقات والالتزامات",
    "What needs action": "ما يتطلب إجراءً",
    "Active deals": "الصفقات النشطة",
    "In pipeline": "قيد التنفيذ",
    "Pending approvals": "الموافقات المعلقة",
    "Overdue tasks": "المهام المتأخرة",
    "Expiring contracts": "العقود المنتهية قريبًا",
    "Next 30 days": "خلال 30 يومًا",
    "Cheques due": "الشيكات المستحقة",
    ":count in the next 30 days": ":count خلال 30 يومًا",
    "VAT payable": "ضريبة القيمة المضافة المستحقة",
    "Net of input tax": "بعد خصم ضريبة المدخلات",
    "Portfolio & operations": "المحفظة والعمليات",
    "Live counts": "أعداد مباشرة",
    "Available units": "الوحدات المتاحة",
    "Reserved units": "الوحدات المحجوزة",
    "Active leads": "العملاء المحتملون النشطون",
    "Open maintenance": "الصيانة المفتوحة",
    "Overdue maintenance": "الصيانة المتأخرة",
    "Commission payable": "العمولات المستحقة",
    "Coming soon": "قريبًا",
    "This section fills in as soon as its data is connected.": "يمتلئ هذا القسم بمجرد ربط بياناته.",
    "Sales & rental value": "قيمة المبيعات والإيجارات",
    "Closed transaction value, AED": "قيمة الصفقات المنجزة بالدرهم",
    "Sales value": "قيمة المبيعات",
    "Rental value": "قيمة الإيجارات",
    "12-month total": "إجمالي 12 شهرًا",
    "Commission": "العمولات",
    "Distribution this period": "التوزيع لهذه الفترة",
    "No commission earned this period.": "لا توجد عمولات مكتسبة في هذه الفترة.",
    "TOTAL": "الإجمالي",
    "Net company": "صافي الشركة",
    "Agent payable": "مستحق للوكلاء",
    "Co-broker": "وسيط مشارك",
    "Referral": "إحالة",
    "Needs attention": "يتطلب انتباهك",
    "Most urgent first": "الأكثر إلحاحًا أولًا",
    "Nothing needs your attention right now.": "لا شيء يتطلب انتباهك الآن.",
    "PDC": "شيكات",
    "Lease": "عقد",
    "Approval": "موافقة",
    "Alert": "تنبيه",
    ":count cheques bounced this week": ":count شيكات مرتجعة هذا الأسبوع",
    ":count cheques due in the next 30 days": ":count شيكات مستحقة خلال 30 يومًا",
    ":count contracts end in the next 30 days": ":count عقود تنتهي خلال 30 يومًا",
    ":count approvals are waiting": ":count موافقات بانتظارك",
    "Top agents": "أفضل الوكلاء",
    "Commission earned this period": "العمولات المكتسبة في هذه الفترة",
    "No agents earned commission this period.": "لم يكتسب أي وكيل عمولة في هذه الفترة.",
    ":count deals": ":count صفقات",
    "This week's insights": "رؤى هذا الأسبوع",
    "Z1 Intelligence will surface trends and risks from your data here.": "سيعرض Z1 الذكي هنا الاتجاهات والمخاطر من بياناتك.",
    "Ask about your portfolio…": "اسأل عن محفظتك…",
    "Lead pipeline": "مسار العملاء المحتملين",
    "Open CRM": "فتح إدارة العملاء",
    "Leads by source": "العملاء المحتملون حسب المصدر",
    "No new leads this period.": "لا يوجد عملاء محتملون جدد في هذه الفترة.",
    "Deal pipeline": "مسار الصفقات",
    "Deals & commission": "الصفقات والعمولات",
    "Open deals": "فتح الصفقات",
    "Deal value by stage": "قيمة الصفقات حسب المرحلة",
    "Commission payable across all agents": "العمولات المستحقة لجميع الوكلاء",
    ":percent of start": ":percent من البداية",
    "Cost centre profitability": "ربحية مراكز التكلفة",
    "Company → branch → department → cost centre": "الشركة ← الفرع ← القسم ← مركز التكلفة",
    "Available once companies, branches and cost centres are set up.": "يتوفر بعد إعداد الشركات والفروع ومراكز التكلفة.",
    "Company / branch": "الشركة / الفرع",
    "Department": "القسم",
    "Cost centre": "مركز التكلفة",
    "Profit": "الربح",
    "Margin": "الهامش",
    "Good morning, :name.": "صباح الخير، :name.",
    "Good afternoon, :name.": "مساء الخير، :name.",
    "Good evening, :name.": "مساء الخير، :name.",
    "Here is where things stand today.": "إليك الوضع اليوم.",
    "Revenue is up :change on the previous period.": "ارتفعت الإيرادات بنسبة :change مقارنة بالفترة السابقة.",
    "Revenue is down :change on the previous period.": "انخفضت الإيرادات بنسبة :change مقارنة بالفترة السابقة.",
    "Revenue is level with the previous period.": "الإيرادات مستقرة مقارنة بالفترة السابقة.",
    "1 cheque bounced this week.": "ارتُجع شيك واحد هذا الأسبوع.",
    ":count cheques bounced this week.": "ارتُجع :count شيكات هذا الأسبوع.",
    "1 approval is waiting for you.": "هناك موافقة واحدة بانتظارك.",
    ":count approvals are waiting for you.": "هناك :count موافقات بانتظارك.",
    "1 item needs your attention.": "هناك بند واحد يتطلب انتباهك.",
    ":count items need your attention.": "هناك :count بنود تتطلب انتباهك.",
    "Review approvals": "مراجعة الموافقات",
    "New deal": "صفقة جديدة",
    "Reports": "التقارير",
    "across :count agents": "لـ :count وكلاء",
    "Month": "شهر",
    "Quarter": "ربع سنة",
    "Year": "سنة",
    "Sale & rent": "بيع وإيجار",
    "Sale": "بيع",
    "Rent": "إيجار",
    "Company": "الشركة",
    "Branch": "الفرع",
    "All companies": "جميع الشركات",
    "All branches": "جميع الفروع"
}
```

- [ ] **Step 9: Verify**

1. Run `npm run test:frontend`, `npm run typecheck`, `npm run lint`, the build, and the full PHP suite. `DashboardTest` must stay green.
2. Screenshot `/dashboard` in light, dark and Arabic with today's real props. Check that:
    - the welcome panel reads "Here is where things stand today." plus the alert count
    - receivables and the "Portfolio & operations" figures are real
    - every other section shows "Coming soon"
    - nothing shows sample numbers
3. To check the full-data layout, temporarily render with a local fake:
    - add a `?preview=1` guard **in a scratch copy** of the page. Never commit fake data.
    - or compare the component layout to the mockup by reading the code
    - Record which method you used.

- [ ] **Step 10: Commit** `feat(home): Home dashboard with welcome panel, figures, charts, pipelines and cost centres`

---

### Task 7: Phase verification

- [ ] Run every gate on the branch and record the output in the ledger:
    - `npm run test:frontend`
    - `npm run typecheck`
    - `npm run lint`
    - the build
    - `php artisan test`
- [ ] Screenshots:
    - Home, CRM leads, Invoices and Settings, in light, dark and Arabic
    - the ⌘K palette open
    - the sidebar in icon-collapsed mode
- [ ] Final whole-branch review with a fresh reviewer, as in phase 1, then one fix pass.
- [ ] Report to the owner with the screenshots and the "Soon" list, and ask whether to merge.
