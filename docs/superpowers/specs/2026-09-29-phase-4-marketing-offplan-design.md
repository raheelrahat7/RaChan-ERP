# Phase 4: Marketing & Off-Plan — Design Spec

**Goal:** Ship frontend pages for the off-plan sales pipeline and portal marketing modules Codex has already built the backend for, and wire them into navigation, replacing placeholder `soon()` entries with real links.

**Context:** This is the third module-group phase in the post-parity frontend build (after Phase 2's app shell/Home and Phase 3's Sales & CRM tools). Codex's backend for this group lives in `OffPlanController`, `PortalMarketingController`, `ManageOffPlan`, `ManagePortalMarketing`, and the `ListingCosting` query — all read directly from source for this spec. `Secondary Market` has no backend yet and stays `soon`.

## Navigation changes

- `Off-Plan Projects`: `soon('Off-Plan Projects', 'offPlan', 'listings')` → `{ label: 'Off-Plan Projects', href: '/real-estate/off-plan', icon: 'offPlan', ability: 'listings' }`
- The Marketing group's five `soon()` placeholders collapse to three real links, matching what Codex actually built (no `marketing` ability key exists — matches the pattern already used for Approvals/Tasks):
    - `{ label: 'Marketing & Portals', href: '/marketing/portals', icon: 'marketing' }`
    - `{ label: 'Portal Subscriptions', href: '/marketing/subscriptions', icon: 'portalSubscriptions' }`
    - `{ label: 'Listing Costing', href: '/marketing/costing', icon: 'costing' }`
    - `Portal Listings` and `Portal Invoicing` are dropped — they were placeholder labels for concepts that turned out to live inside the Portal and Subscriptions pages, not separate routes. Their icon keys (`portalListings`, `portalInvoicing`) and the now-unused `RelatedType`/nav union members stay in `nav-icons.ts`/`navigation.ts`'s type union harmlessly (removing them isn't required for correctness and risks an unrelated diff); the plan will only touch the `soon(...)` call sites.
- `Secondary Market` unchanged (`soon`).

## Data contracts (read from controllers/actions/migrations directly)

```ts
// Off-Plan
type OffPlanProject = {
    id: number;
    developer_id: number;
    developer_name: string;
    cost_centre_id: number | null;
    code: string;
    name: string;
    emirate: string;
    location: string | null;
    completion_on: string | null;
    commission_rate: string;
    status: 'active' | string;
};
type OffPlanUnit = {
    id: number;
    project_id: number;
    number: string;
    type: string | null;
    area_sqft: string | null;
    price_aed: string;
    status: 'available' | 'reserved' | 'sold';
};
type OffPlanMilestone = {
    id: number;
    sequence: number;
    label: string;
    percentage: string;
    due_on: string | null;
};
type OffPlanDeal = {
    id: number;
    unit_id: number;
    lead_id: number;
    reference: string;
    price_aed: string;
    status: 'enquiry' | 'reserved' | 'contracted' | 'cancelled';
    contracted_on: string | null;
    notes: string | null;
};
// index props: { projects: Paginated<OffPlanProject>, developers: { id: number; name: string }[], canManage: boolean }
// show props: { project: OffPlanProject, units: Paginated<OffPlanUnit>, milestones: OffPlanMilestone[], deals: OffPlanDeal[], canManage: boolean }

// Marketing
type Campaign = {
    id: number;
    name: string;
    type: string;
    budget_aed: string;
    starts_on: string | null;
    ends_on: string | null;
    status: 'draft' | string;
};
type Publication = {
    id: number;
    listing_id: number;
    campaign_id: number | null;
    portal: 'bayut' | 'property_finder' | 'dubizzle';
    status: 'local_validated' | string;
    validated_at: string;
};
// marketing/Portal props: { campaigns: Paginated<Campaign>, publications: Publication[], canManage: boolean, providerSelected: boolean }

type Subscription = {
    id: number;
    portal: 'bayut' | 'property_finder' | 'dubizzle';
    package: string;
    contract_value_aed: string;
    billing_cycle: 'monthly' | 'quarterly' | 'annual';
    credits_total: number;
    credits_used: number;
    starts_on: string;
    renews_on: string | null;
    status: 'active' | string;
};
type SubscriptionBill = {
    id: number;
    subscription_id: number;
    vendor_bill_id: number;
    period_from: string;
    period_to: string;
};
// marketing/Subscriptions props: { subscriptions: Paginated<Subscription>, bills: SubscriptionBill[], canManage: boolean, canLinkBill: boolean }

type PortalCost = {
    portal: 'bayut' | 'property_finder' | 'dubizzle';
    published_listings: number;
    local_validated_listings: number;
    total_leads: number;
    actual_spend_aed: number;
    estimated_spend_aed: number;
    cost_per_listing: number | null;
    cost_per_lead: number | null;
    cost_per_deal: null;
    roi: null;
};
type ListingSpend = {
    id: number;
    listing_id: number;
    publication_id: number | null;
    campaign_id: number | null;
    vendor_bill_id: number | null;
    channel: 'bayut' | 'property_finder' | 'dubizzle' | 'other';
    source: 'estimate' | 'bill_linked';
    amount_aed: string;
    incurred_on: string;
    reason: string;
};
// marketing/Costing props: { portals: PortalCost[], spend: Paginated<ListingSpend>, canManage: boolean }
```

`cost_per_deal` and `roi` are permanently `null` (no deal-attribution data source yet, per `ListingCosting`) — render as "—", not "Coming soon" (it's a genuine metric gap, same treatment as Home's `commission_split`).

## Pages

### 1. `real-estate/OffPlan.vue` (index)

- `DataTable`: code, name, developer, emirate, completion date, status badge
- "New project" dialog (gated on `canManage`): developer `<Select>` (from `developers` prop), optional cost centre — **no cost-centre list is in props**, so this becomes a plain optional numeric ID field with helper text, matching the vendor-bill precedent — code, name, emirate, optional location, optional completion date, optional commission rate (%, default 0)
- Row click → `/real-estate/off-plan/{id}`

### 2. `real-estate/OffPlanProject.vue` (detail)

- Header: code, name, developer, emirate, status, commission rate
- **Units** section: `DataTable` (number, type, area, price, status badge) + "Add unit" dialog (`canManage`): number, optional type, optional area, price
- **Payment milestones** section: table (sequence, label, %, due date) + running-total display + "Add milestone" dialog (`canManage`): sequence, label, percentage, optional due date. Client-side check: if `sum(existing percentages) + new percentage > 100`, show inline error before submit (mirrors `ManageOffPlan::milestone`'s server check; server remains authoritative)
- **Deals** section: `DataTable` (reference, unit, lead, price, status, contracted date) + "New deal" dialog (`canManage`): unit `<Select>` (from `units` prop, only `available` ones), lead via `RecordPicker` (`type="lead"`), reference, optional price (defaults to unit price server-side if omitted — leave blank, don't pre-fill), optional notes
- Status transitions: buttons per allowed transition (`enquiry` → Reserve/Cancel, `reserved` → Contract/Cancel, `contracted`/`cancelled` → none), gated on `canManage`. "Contract" opens a small dialog for the required `contracted_on` date; "Reserve"/"Cancel" post directly with a confirm (reuse the pattern from Approvals' reject flow — a lightweight native `confirm()` is acceptable here since there's no reason text involved, unlike `ReasonDialog`)

### 3. `marketing/Portal.vue`

- **Campaigns** section: table (name, type, budget, dates, status badge) + "New campaign" dialog (`canManage`): name, type, optional vendor ID (plain field — no vendor list in props), optional cost centre ID (plain field), optional budget/dates
- **Publications** section: table (listing ID, portal badge, status, validated date) + "Publish" dialog (`canManage`): listing ID (plain numeric field — no listing list in props; this mirrors how Deal Management/other existing pages handle listing selection when no picker prop is given), portal `<Select>` (bayut/property_finder/dubizzle), optional campaign `<Select>` (from `campaigns` prop)
- Per-publication "Record enquiry" dialog (`canManage`): lead via `RecordPicker` (`type="lead"`), source reference, received-at datetime

### 4. `marketing/Subscriptions.vue`

- `DataTable`: portal badge, package, credits used/total, billing cycle, renewal date, status badge
- "New subscription" dialog (`canManage`): portal `<Select>`, package, optional company/branch/cost-centre IDs (plain fields), contract value, billing cycle `<Select>`, optional credits total, starts-on, optional renews-on
- Per-subscription "Link bill" dialog (`canLinkBill` only): vendor bill ID (plain field), period from/to

### 5. `marketing/Costing.vue`

- Read-only cost report: one card or table row per portal (bayut/property_finder/dubizzle) — published listings, local-validated listings, leads, actual spend, estimated spend, cost-per-listing, cost-per-lead (all money via `Money`, nulls as "—")
- Spend log: `DataTable` (listing ID, channel, source badge, amount, date, reason) + "Record spend" dialog (`canManage`): listing ID (plain field), optional publication/campaign IDs (plain fields), channel `<Select>`, source `<Select>` (estimate/bill_linked), amount, date, reason (textarea); vendor bill ID field appears only when source = bill_linked (matches the backend's own conditional validation)

## Shared components

No new shared components needed — `DataTable`, `RecordPicker`, `Money`, `DateText`, `PageHeader`, `Pagination`, `InputError` all already exist from Phases 1–3. `RecordPicker` is used only for lead selection (off-plan deals, portal enquiries); every other foreign-key field without a props-provided option list uses a plain field, consistent with the vendor-bill precedent from Phase 3's design.

## Review Focus

1. **Off-plan deal status transitions must reject invalid moves client-side** (e.g. showing a "Contract" button on an `enquiry`-status deal) — the button set is computed from `deal.status`, not shown unconditionally, so an invalid transition can't be clicked in the first place.
2. **Milestone percentage overflow** — the spec's client-side running-total check (task-owned test) must match the server's `sum + new > 100.001` tolerance, not a stricter `> 100`, or valid inputs get rejected client-side that the server would accept.
3. **`cost_per_deal`/`roi` always null** — must render as "—" (a real, permanent data-source gap) not "Coming soon" (implies a future feature), matching the Home dashboard's established null-semantics split.
4. **Spend source toggle (estimate vs. bill_linked)** — the vendor-bill field must be hidden/cleared when switching back to `estimate` after having selected `bill_linked`, or a stale bill ID could be submitted and rejected server-side with a confusing error.
5. **Publication uniqueness** — publishing the same listing to the same portal twice is a real, named backend rejection (`'This listing already has a local record for that portal.'`); the form must surface that validation error via the existing `InputError` pattern, not silently fail.

---

Once you approve this, I'll write the implementation plan.
