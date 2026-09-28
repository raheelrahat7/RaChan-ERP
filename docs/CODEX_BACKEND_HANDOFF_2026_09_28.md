# Backend handoff to Codex: Command Centre, shared props and new modules

> **Implementation update, 2026-09-28:** The owner approved Parts A, B, D and C1–C11. Backend routes, modules and focused tests are implemented on `codex/command-centre-data` in an isolated worktree. See [the implementation scope and frontend route contract](CODEX_RED_MODULE_SCOPES_2026_09_28.md) for exact route names, props and data-source limits. The original contract below records the requested shape; the implementation update documents permission-based nullable values and provider-dependent fields.

- **Date:** 2026-09-28
- **From:** the frontend team (Claude). The owner has decided that Codex does all backend work and Claude does all frontend work.
- **Read first:** `AGENTS.md`, `docs/MASTER_DEVELOPMENT_PLAN.md`, `docs/PROVIDER_NEUTRAL_CONTRACTS.md`, and `docs/superpowers/specs/2026-09-28-bordeaux-frontend-redesign-design.md` §3.3 and §5.2.
- **Visual target:** `.superpowers/brainstorm/command-centre.html`. Open it in a browser and click **Data readiness**: orange = data exists but isn't sent to the page; red = a new module.

## How we work together

- **Scope:** Codex owns `app/`, `database/`, `routes/`, `config/` and `tests/Feature`. Claude owns `resources/js`, `resources/css` and `tests/Frontend`. Don't edit each other's areas. If you need a UI change, write it in the PR description and Claude picks it up.
- **The contract is the Inertia props.** Each section below gives the exact prop shape the frontend will read. Keep the names and types. If a shape must change, update this document in the same PR.
- **Staying compatible:** when you add a prop, keep existing props until Claude confirms the frontend no longer reads them. For example, `Dashboard` keeps `metrics` and `alerts`.
- **Nulls:** a value whose source doesn't exist yet is sent as `null`, never `0`. The frontend shows "—" with a "Coming soon" hint for `null`.
- **Rules that apply everywhere:**
    - Everything is scoped to the current organization and to the user's permissions (reuse the existing visibility services, e.g. `LeadVisibility` and `JobCardAccess`).
    - Money is AED decimal numbers with 2 decimals.
    - Dates are `Y-m-d` strings. Timestamps are ISO-8601.
    - Every module lives in `app/Domain/<Module>` with feature tests.
- **Branch:** start from `main` after the owner merges the frontend branch `bordeaux/phase-1-foundation`. Use one branch per part below.

---

## Part A: Command Centre data (orange). Highest priority

Extend `app/Http/Controllers/DashboardController.php`. Put the aggregation in a new query class, `app/Domain/Platform/Queries/CommandCentre.php` (or split it across the owning modules' `Queries/`), not in the controller.

**Query string filters:**

- `period` = `month` (default) | `quarter` | `year`
- `purpose` = `all` (default) | `sale` | `rent`
- `company` and `branch` are optional ids, and work only once Part C1 exists

Echo the chosen filters back in `filters`.

```ts
type Money = number; // AED, 2 decimals

type DashboardProps = {
    // existing, keep for now
    metrics: {/* unchanged */};
    alerts: { title: string; count: number; href: string }[];

    // new
    filters: {
        period: 'month' | 'quarter' | 'year';
        purpose: 'all' | 'sale' | 'rent';
        company_id: number | null;
        branch_id: number | null;
        range: { from: string; to: string }; // Y-m-d, resolved period
        options: {
            companies: { id: number; name: string }[]; // [] until Part C1
            branches: { id: number; name: string; company_id: number }[]; // [] until Part C1
        };
    };
    kpis: {
        revenue: { value: Money; previous: Money | null }; // posted revenue in range vs previous range
        expenses: { value: Money; previous: Money | null };
        net_profit: { value: Money; previous: Money | null };
        cash_balance: { value: Money; change_7d_pct: number | null }; // bank/cash ledger accounts
        receivables: { value: Money }; // outstanding customer invoices
        payables: { value: Money }; // outstanding vendor bills
        vat_payable: { value: Money }; // current open VAT period, output minus input
        commission_payable: { value: Money; agents: number }; // commission_transactions not yet paid
        active_deals: { count: number }; // see deal definition below
        expiring_contracts: { count: number }; // leases/sales contracts ending in next 30 days
        pdc_due: { count: number; amount: Money }; // lease_cheques due in next 30 days, not cleared/bounced
        bounced_cheques: { count: number; amount: Money }; // bounced in the last 7 days
        pending_approvals: { count: number } | null; // null until Part C2
        overdue_tasks: { count: number } | null; // null until Part C3
    };
    trend: {
        // last 12 months, oldest first
        months: string[]; // 'YYYY-MM'
        sales_value: Money[]; // sales_contracts.sale_price by contracted_on
        rental_value: Money[]; // leases.rent_amount (annualised) by starts_on
    };
    commission_split: {
        // commission in range
        net_company: Money;
        agent_payable: Money;
        co_broker: Money;
        referral: Money;
    } | null; // null if commission data cannot yet distinguish these; say which in the PR
    top_agents: {
        // top 5 by commission earned in range
        user_id: number;
        name: string;
        team: string | null;
        commission: Money;
        deals: number;
    }[];
    lead_pipeline: {
        // the organization's default active pipeline
        pipeline: { id: number; name: string } | null;
        stages: { id: number; name: string; type: string; count: number }[]; // in pipeline order
    };
    lead_sources: { source: string; count: number }[]; // crm_leads.source created in range, top 6 + 'Other'
    deal_pipeline: {
        stages: { key: string; label: string; count: number; value: Money }[];
    };
    cost_centres: {
        // [] until Part C1
        level: 0 | 1 | 2; // company, branch, cost centre
        company: string;
        branch: string | null;
        department: string | null;
        cost_centre: string | null;
        revenue: Money;
        expense: Money;
        profit: Money;
    }[];
    insights: { icon: string; text: string }[]; // [] until Part C9
};
```

**Deal definition.** Define deal stages from existing data (reservations → sales contracts/leases and their statuses) and document the mapping in the PR, for example `draft → negotiation → documentation → approved → closed → settled`. If the existing statuses can't express this cleanly, send the stages you can and flag the gap. Don't add tables in Part A.

**Performance:** at most one extra second on the dashboard request with realistic data. Cache per organization, user, filters and period for 5 minutes if needed.

**Tests:**

- every value on an empty organization (all zero or `null` as specified)
- a seeded organization
- tenant isolation: another organization's data is never counted
- permission scoping for leads and job cards
- each filter

---

## Part B: Shared props for the new sidebar

Add these in `app/Http/Middleware/HandleInertiaRequests.php`. Both are lazy closures.

```ts
type SharedProps = {
    abilities: Record<string, boolean>; // which navigation sections the user may open, e.g.
    // { crm: true, listings: true, leasing: true, deals: true, commission: true,
    //   accounting: true, pdc: true, procurement: true, operations: true,
    //   fleet: false, projects: true, reports: true, organization_admin: true, ... }
    counts: {
        notifications_unread: number;
        crm_open_leads: number; // visible to this user
        approvals_pending: number | null; // null until Part C2
    };
};
```

Reuse the existing policies and role checks. The frontend hides a sidebar item whose ability is `false`; the server stays the real guard.

---

## Part C: New modules (red)

Each module is its own phase, with its own tables, policies, routes, Inertia pages and tests, and needs the owner's approval first (AGENTS.md: "Do not begin a later phase without explicit approval"). Codex writes each module's scope document in `docs/` for the owner to approve. Claude then builds the pages from the props that document defines.

The list is in the suggested order: things the dashboard depends on first, then by business value.

| #   | Module                                 | What the owner expects (from the reference product)                                                                                                                     | Notes                                                                                                                                                               |
| --- | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C1  | **Companies, branches & cost centres** | Several legal entities and branches under one organization; departments and cost centres; revenue and expense attributed by cost centre                                 | Feeds the dashboard filters and the cost-centre table. Needs accounting dimensions on journal lines. Talk to the accountant first (`docs/ACCOUNTING_DECISIONS.md`). |
| C2  | **Approvals inbox**                    | One place listing everything waiting for the current user's approval (deals pending finance approval, budgets, deposit deductions, vendor bills, …) with approve/reject | Pull together the approval flows that already exist; don't duplicate them.                                                                                          |
| C3  | **Tasks**                              | Assignable tasks with due dates, links to any record (lead, deal, unit, job card), overdue tracking                                                                     | CRM follow-ups already exist; decide whether tasks cover them or sit alongside.                                                                                     |
| C4  | **Meetings & viewings**                | Calendar of meetings and property viewings linked to leads, listings and agents; viewing outcome feeds the lead pipeline                                                |                                                                                                                                                                     |
| C5  | **Off-plan projects & developers**     | Developers as parties, off-plan projects, unit inventory per project, payment plans and milestones, off-plan leads and deals                                            | `RealEstate` parties may already model developers; extend them.                                                                                                     |
| C6  | **Marketing & portal listings**        | Publish listings to Bayut, Property Finder and Dubizzle, track status, enquiries and leads per portal                                                                   | Must follow `PROVIDER_NEUTRAL_CONTRACTS.md` (an adapter per portal). No real portal credentials in code.                                                            |
| C7  | **Portal subscriptions & invoicing**   | Track portal packages, credits and quotas, and the portal invoices the company pays                                                                                     | Links to vendor bills.                                                                                                                                              |
| C8  | **Listing costing**                    | Marketing spend per listing and channel; cost per lead and cost per deal                                                                                                | Needs C6 spend data.                                                                                                                                                |
| C9  | **AI Matchmaker & Z1 Intelligence**    | Suggest matching listings for a lead (and leads for a listing); generate the dashboard `insights[]`                                                                     | Provider-neutral AI contract; the owner decides the provider and data-privacy rules first. Deterministic rules-based matching is acceptable as step one.            |
| C10 | **HR & staff services**                | Staff records, document expiry (Emirates ID, visa, RERA broker card), leave requests                                                                                    | The "3 agent Emirates IDs expiring" alert comes from here.                                                                                                          |
| C11 | **GAIM compliance & bulletins**        | Owner to define "GAIM". Likely regulatory compliance tracking plus internal announcements                                                                               | Ask the owner before designing.                                                                                                                                     |

**Existing features to extend, not new modules.** Their sidebar entries point at existing pages, and each needs only small additions:

| Sidebar entry        | Existing basis                                                              | What is missing                                                                                                                                     |
| -------------------- | --------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Owners & Developers  | `real-estate/people`                                                        | A developer party type, if not present                                                                                                              |
| Secondary Market     | `real-estate/brokerage`, listings                                           | A resale/secondary flag and filter on listings                                                                                                      |
| Broker Allocation    | `crm/assignment`                                                            | Nothing                                                                                                                                             |
| Broker Performance   | `crm/pipeline-report`, commission transactions                              | Per-broker KPIs query (leads, conversion, deals, commission) as page props                                                                          |
| PDC Management       | `lease_cheques` (currently managed inside leases)                           | An organization-wide cheque register page: list, filter by status and due date, deposit/clear/bounce actions using the existing `ManageLeaseCheque` |
| Report Centre        | Operations reports, property performance, owner statements, pipeline report | An index page listing every report the user may open                                                                                                |
| Lead Gateway         | Meta lead-ads webhook and assignment                                        | A settings/status page for lead sources                                                                                                             |
| Follow-up Automation | CRM follow-up reminders and escalations                                     | A settings page, if not already covered by `crm/assignment`                                                                                         |

---

## Part D: Marketing website demo request (moved from Claude to Codex)

This is spec §5.2, unchanged, now owned by Codex:

- `config/marketing.php` with `lead_organization`, read from `MARKETING_LEAD_ORGANIZATION`, plus a safe placeholder in `.env.example`
- `MarketingDemoRequestController` with a `DemoRequest` form request; fields are listed in the spec
- a `website` honeypot and `throttle:5,1`
- `App\Domain\Crm\Actions\RecordDemoRequest` calling `ManageLeadPipeline::createPublicInquiry()`, with an optional history-note parameter that defaults to the current text
- `source = 'Website demo request'`, `campaign_name = 'Marketing website'`
- a friendly error if the setting is missing
- feature tests: happy path, validation, honeypot, throttle, missing config

Route: `POST /demo`, named `marketing.demo.store`. It should redirect back with a `success` flash message. Claude builds the page against that.

---

## Order of work

1. **Part A** (the dashboard runs on real data, with `null` for anything that depends on C2 or C3)
2. **Part B** (the sidebar hides what the user can't open and shows badges)
3. **Part D** (the website form works)
4. **C1 → C11**, one approved phase at a time, each starting with a scope document for the owner

For each part, report back:

- the PR or branch name
- the final prop shapes, noting any difference from this document
- the tests added
- any data gap the frontend should know about
