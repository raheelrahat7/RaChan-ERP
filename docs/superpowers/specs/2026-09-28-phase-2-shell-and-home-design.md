# Phase 2: app shell and Home dashboard, design spec

- **Date:** 2026-09-28
- **Status:** Awaiting owner review
- **Builds on:** `docs/superpowers/specs/2026-09-28-bordeaux-frontend-redesign-design.md` (phase 1, merged)
- **Approved visuals:**
    - `.superpowers/brainstorm/home-dashboard.html` for the sidebar, top bar and Home dashboard
    - `.superpowers/brainstorm/home-concepts.html`, concept B (building skyline), which is **not** part of phase 2; see §6
- **Backend contract:** `docs/CODEX_BACKEND_HANDOFF_2026_09_28.md`. Codex owns all backend work (owner decision, 2026-09-28). This phase is frontend only.

## 1. Goals

1. A sidebar with the section-and-item structure the owner chose: small uppercase section headings, one distinct icon per item, compact rows, and a highlighted active row. It covers every feature of the reference product and Z1's own modules, and is styled in Bordeaux.
2. A **Home** dashboard that follows the approved mockup. The word "Command Centre" appears nowhere in the product.
3. The dashboard is unique to Z1 while covering everything the reference covers: filters, headline figures, financial position, pipeline and obligations, value trend, commission split, attention list, top agents, insights, lead and deal pipelines, lead sources, cost-centre profitability.
4. It works today with the data the backend already sends, and gets richer automatically as Codex delivers each part of the handoff, with no frontend rework.

## 2. Sidebar

### 2.1 Structure

The sidebar is built from `resources/js/lib/navigation.ts`, which replaces the phase 1 structure. Sections are **not** collapsible, matching the owner's reference. Only Accounting keeps a nested sub-list. Items marked _Soon_ are shown dimmed and not clickable, with a "Soon" label. The navigation test checks every enabled item's route.

| Section                 | Items (route, or _Soon_)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Overview**            | Home `/dashboard` · Search `/search` · Notifications `/notifications`                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| **Sales & CRM**         | CRM & Leads `/crm/leads` · Contacts `/crm/contacts` · AI Matchmaker _Soon_ · Property & Listings `/real-estate/listings` · Property Inventory `/inventory` · Owners & Developers `/real-estate/people` · Off-Plan Projects _Soon_ · Secondary Market _Soon_ · Reservations `/reservations` · Deal Management `/agreements` · Handovers `/handovers` · Leasing & Rental `/lease-compliance` · Agents & Commission `/real-estate/brokerage` · Broker Allocation `/crm/assignment` · Broker Performance `/crm/pipeline-report` |
| **Marketing**           | Marketing & Portals _Soon_ · Portal Listings _Soon_ · Portal Subscriptions _Soon_ · Portal Invoicing _Soon_ · Listing Costing _Soon_                                                                                                                                                                                                                                                                                                                                                                                        |
| **Finance**             | Accounting & Tax `/accounting` ▸ (the phase 1 sub-list) · Cheques (PDC) _Soon_ · Receivables `/invoices` · Payables `/vendor-bills` · Vendor Refunds `/finance/vendor-cash-refunds` · Bank Reconciliation `/bank-reconciliation` · Property Performance `/reports/property-profitability` · Owner Statements `/reports/owner-statements` · Procurement & Inventory `/procurement`                                                                                                                                           |
| **Facility Management** | Maintenance `/maintenance` · Preventive Maintenance `/preventive-maintenance` · Helpdesk `/operations/helpdesk` · AMC Contracts `/operations/amc` · Spare Parts `/operations/spare-parts` · Projects `/operations/projects` · Fleet `/operations/fleet` · Operations Overview `/operations` · Operations Reports `/operations/reports` · Scheduled Reports `/operations/scheduled-reports`                                                                                                                                  |
| **Workflow**            | Approvals _Soon_ · Tasks _Soon_ · Meetings & Viewings _Soon_ · Contracts & Signatures `/documents/signatures` · Compliance Documents `/compliance-documents`                                                                                                                                                                                                                                                                                                                                                                |
| **Corporate**           | HR & Staff Services _Soon_ · Report Centre _Soon_ · GAIM Compliance _Soon_ · Bulletins _Soon_ · Lead Gateway _Soon_ · Follow-up Automation `/crm/assignment#follow-up` · CRM Pipelines `/crm/pipelines` · Departments & Teams `/crm/hierarchy` · Organization & Access `/organization` · Settings `/settings/profile`                                                                                                                                                                                                       |

When Codex delivers a _Soon_ module, its item gets a route and becomes active. This is a one-line change in `navigation.ts`.

### 2.2 Behavior and look

- **Header:** the Z1 mark with the organization name, as in the mockup.
- **Visibility:** if the shared `abilities` prop exists (handoff Part B), items whose ability is `false` are hidden, and a section with no visible items is hidden. Until then, every enabled item shows. The server still enforces access.
- **Badges:** when `counts` exists, items show badges: Notifications uses `counts.notifications_unread`, and CRM & Leads uses `counts.crm_open_leads`.
- **Active state:** the phase 1 `activeHref()` logic, with a champagne marker and a tinted row.
- **Collapsing:** the sidebar collapses to icons with tooltips; on mobile it opens as a sheet; in Arabic it sits on the right. The list scrolls on its own, and the header and user footer stay fixed.
- **Icons:** each `NavIcon` maps to one Lucide icon in a single `resources/js/lib/nav-icons.ts`, one distinct icon per item.

## 3. Top bar

- **Breadcrumbs** on the start side.
- **Home filters** (on Home only):
    - Period: `month` / `quarter` / `year`
    - Sale & rent: `all` / `sale` / `rent`
    - Company and branch dropdowns, shown **only** when `filters.options.companies` or `.branches` is non-empty (handoff Part C1)
    - Filters change the URL query and reload the page's props with an Inertia partial reload
- **Search** (⌘K / Ctrl+K) opens the `CommandPalette`: navigation items, quick actions, and a fall-through to `/search?q=`.
- **Notifications** bell with the unread count; **user menu** with appearance and language.

## 4. Home dashboard

`resources/js/pages/Dashboard.vue` becomes Home. It is split into focused components under `resources/js/components/home/`.

| #   | Section                   | Content                                                                                                                                                                                                                                                                               | Data source (handoff)                                            |
| --- | ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| 1   | Welcome panel             | Burgundy panel: date eyebrow ("Home · Monday 28 September 2026"), serif greeting with the user's first name, one generated summary sentence, three action buttons (Review approvals, New deal, Board pack), and four headline figures (revenue, net profit, cash, commission payable) | `kpis`; the sentence is built by a pure function from `kpis`     |
| 2   | Financial position        | Six figures: revenue, expenses, net profit, cash, receivables, payables, each with a comparison line                                                                                                                                                                                  | `kpis`                                                           |
| 3   | Pipeline & obligations    | Six figures: active deals, pending approvals, overdue tasks, expiring contracts, cheques due in 30 days, VAT payable                                                                                                                                                                  | `kpis`                                                           |
| 4   | Sales & rental value      | Twelve-month area chart with two series, a 3M/6M/12M switch, and a year-to-date total                                                                                                                                                                                                 | `trend`                                                          |
| 5   | Commission                | Donut with the total in the center, plus a key with amounts                                                                                                                                                                                                                           | `commission_split`                                               |
| 6   | Needs attention           | Tagged list, most urgent first, each row linking to its page                                                                                                                                                                                                                          | `alerts` (exists today) plus cheque and expiry items from `kpis` |
| 7   | Top agents                | Ranked leaderboard with progress bars                                                                                                                                                                                                                                                 | `top_agents`                                                     |
| 8   | Z1 Intelligence           | Burgundy insight panel with an "Ask about your portfolio" box                                                                                                                                                                                                                         | `insights` (handoff C9)                                          |
| 9   | Lead pipeline             | Stage rail with conversion % plus leads by source                                                                                                                                                                                                                                     | `lead_pipeline`, `lead_sources`                                  |
| 10  | Deal pipeline             | Stage rail, value by stage, commission payable                                                                                                                                                                                                                                        | `deal_pipeline`, `kpis.commission_payable`                       |
| 11  | Cost-centre profitability | Indented company → branch → cost-centre table with margin bars                                                                                                                                                                                                                        | `cost_centres` (handoff C1)                                      |

### 4.1 Missing data, so the page works on every day of the rollout

- **Missing prop** (e.g. Codex hasn't shipped Part A yet): the section shows a quiet "Coming soon" state in the section's own shape. It never shows sample numbers.
- **`null` value:** "—" with a small "Coming soon" hint, per the handoff's null rule.
- **Empty list:** the section's designed empty state, e.g. "No agents earned commission this period."
- **Before Part A lands:** Home still shows real data from today's props:
    - receivables ← `metrics.outstandingAed`
    - active leads, available and reserved units, open and overdue maintenance ← `metrics`
    - the alerts list
- **One place for this logic:** a pure `normalizeHome(props)` function in `resources/js/lib/home.ts` turns whatever props exist into a single view model. Components never check for props themselves.

### 4.2 Language, theme, access

- Every label goes through `t()`, with Arabic added to the catalog. Numbers and money use `useFormat`.
- Charts keep a left-to-right time axis in Arabic.
- Light and dark mode use the phase 1 tokens.
- The action buttons link only to pages that exist; "Review approvals" is disabled until handoff C2.

## 5. Testing

- **`navigation.test.mjs`, updated:**
    - every enabled item's route exists
    - _Soon_ items have no route
    - every label has Arabic
    - no duplicate routes
    - every item has an icon mapped in `nav-icons.ts` (checked by name, as a source scan)
- **`home.test.mjs`, new:**
    - `normalizeHome()` with (a) today's props only, (b) the full Part A contract, (c) `null` values, (d) empty lists
    - the summary sentence for up, down and flat months, and for zero bounced cheques or approvals
- **`DashboardTest.php`:** still passes (owned by Codex; unchanged by this phase)
- **Quality gates:** lint, typecheck, build and the full PHP suite
- **Screenshots:** Home and three other pages in light, dark and Arabic, compared against the mockup

## 6. Out of scope

- All backend work. It follows the handoff.
- **The building skyline** (concept B). The owner wants it in the facility management and property sales sections. It will be built in those module phases once handoff Part E data exists.
- Converting the other module pages. That's phases 3–8.
