# Phase 3: Sales & CRM tools, design spec

- **Date:** 2026-09-29
- **Status:** Approved by owner (design summary + one decision confirmed in chat)
- **Builds on:** phase 1 (Bordeaux design system) and phase 2 (shell, Home), both merged
- **Backend:** already built and merged by Codex on `main` (`docs/CODEX_RED_MODULE_SCOPES_2026_09_28.md`). This phase is frontend only, except for one small backend request already handed to Codex: `docs/CODEX_RECORD_SEARCH_REQUEST_2026_09_29.md`.

## 1. Scope

Five pages, replacing their "Soon" sidebar entries with real routes:

| Page                | Route                                                                           | Sidebar item it replaces                                                     |
| ------------------- | ------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| Approvals           | `GET /approvals` (`approvals.index`)                                            | Workflow ▸ Approvals                                                         |
| Tasks               | `GET/POST /tasks`, `PUT /tasks/{task}`, `POST /tasks/{task}/complete`           | Workflow ▸ Tasks                                                             |
| Meetings & Viewings | `GET/POST /meetings`, `POST /meetings/{appointment}/outcome`                    | Workflow ▸ Meetings & Viewings                                               |
| AI Matchmaker       | `GET /crm/matchmaker`, `GET /crm/matchmaker/leads/{lead}`, `PUT .../preference` | Sales & CRM ▸ AI Matchmaker                                                  |
| Broker Performance  | `GET /crm/broker-performance`                                                   | Sales & CRM ▸ Broker Performance (already a real link; this builds its page) |

A sixth small piece, not a page: the **record picker** component used by Tasks and Meetings to link a lead/listing/unit/reservation/lease/sale/job. It calls the endpoint requested from Codex and degrades to "search isn't connected yet" without it — everything else on both pages works regardless.

## 2. Navigation changes

In `resources/js/lib/navigation.ts`:

- `AI Matchmaker`, `Approvals`, `Tasks`, `Meetings & Viewings` lose `soon: true` and gain real `href`s.
- Each gets an `ability` matching what its controller authorizes: Matchmaker → `crm`, Approvals → none (every organization role can have pending approvals; the page itself is empty when there are none), Tasks → none (everyone can see their own tasks), Meetings → `crm`.
- `counts.approvals_pending` (already shared, already wired to the sidebar badge) now reflects real pending approvals once this phase's `PendingApprovals` query is live — no frontend change needed, it already reads that count.

## 3. Pages

### 3.1 Approvals — `resources/js/pages/approvals/Index.vue`

Props: `items: ApprovalItem[]`, `count: number`.

```ts
type ApprovalItem = {
    key: string;
    module: string;
    transaction: string;
    amount: number | null;
    cost_centre: string | null;
    requested_by: string;
    status: 'submitted';
    submitted_at: string;
    href: string;
    approve_url: string;
    reject_url: string | null;
    reject_requires_reason: boolean;
};
```

- `PageHeader` (eyebrow "Workflow", title "Approvals", description "Everything waiting for your decision, across every module.")
- A `DataTable` grouped by `module` is unnecessary complexity for a first cut; render one flat table sorted as the backend already sorts it (newest first), with a `module` badge column (`StatusDot`-style tag, not a status — a small neutral badge is enough, reuse the `Badge` primitive with `variant="outline"`).
- Columns: Module (badge), Transaction (bold + link to `href`), Requested by, Submitted (`DateText`), Amount (`Money`, only when not null — show "—" otherwise), Actions.
- Actions: an **Approve** button that posts to `approve_url` with no body (`router.post(item.approve_url, {}, { preserveScroll: true })`). A **Reject** button, only when `reject_url` is present, opens a small reason dialog (`ReasonDialog`, new component, §4) and posts `{ reason }` to `reject_url` on confirm.
- Empty state: "Nothing is waiting for your approval." (This is a real empty state, never "coming soon" — the feature is live.)
- No filters needed for a first cut (the backend already returns only what's actionable for this user); a search-by-transaction-reference `FilterBar` can be added later if the list grows long.

### 3.2 Tasks — `resources/js/pages/tasks/Index.vue`

Props: `tasks: Paginated<Task>`, `members: { id: number; name: string }[]`, `canManage: boolean`.

```ts
type Task = {
    id: number;
    title: string;
    description: string | null;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    status: 'open' | 'completed';
    assigned_to: number;
    assignee_name: string;
    due_at: string | null;
    related_type:
        'lead' | 'reservation' | 'lease' | 'sale' | 'unit' | 'job' | null;
    related_id: number | null;
    completed_at: string | null;
};
```

(`Paginated<T>` is the existing convention already used elsewhere: `{ data: T[]; links: PaginationLink[] }`, ignoring the rest of Laravel's paginator envelope.)

- `PageHeader` (eyebrow "Workflow", title "Tasks", description "Assigned work with a due date." — description changes to "Your assigned tasks." when `!canManage`, since a non-manager only ever sees their own).
- `DataTable`: Title, Assignee (only shown when `canManage`, since a non-manager's list is always "me"), Priority (a small tone-mapped label: low/normal = muted, high = warning, urgent = danger — reuse `StatusDot`'s tone classes directly, not the status map, since these aren't backend "statuses"), Due (`DateText`, red text when overdue and still open), Related (an icon + short label built from `related_type`, linking to the record's index page the same way phase 2's `attentionItems` links out), row action: **Complete** (visible when `status === 'open'` and (`canManage` or it's the current user's task) — the page doesn't know the current user id from the shared `auth` prop, compare against `usePage().props.auth.user.id`).
- Filter: a status toggle (Open / Completed / All), client-side over the current page's rows — the backend doesn't filter server-side today, so this is a simple computed filter, not a new query param. Note this limitation in a code comment; a later phase can add server-side filtering if task volume grows.
- "New task" button opens a `FormSection`-based dialog (not a full page — this is a lightweight create, matching the size of the data): Title, Description (optional), Priority (segmented control, default Normal), Assignee (`Select`, only when `canManage`; otherwise defaulted to self and not shown), Due date (native date input), and the **record picker** (optional, §4).
- Editing an open task reuses the same dialog, pre-filled, posting to `PUT /tasks/{task}`.
- Empty state: "No open tasks." / "No tasks yet." depending on filter.

### 3.3 Meetings & Viewings — `resources/js/pages/meetings/Index.vue`

Props: `appointments: Paginated<Appointment>`, `members: {...}[]`, `canManage: boolean`.

```ts
type Appointment = {
    id: number;
    type: 'meeting' | 'viewing';
    title: string;
    assigned_to: number;
    lead_id: number | null;
    listing_id: number | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    status: 'scheduled' | 'completed';
    outcome: string | null;
};
```

- `PageHeader` (eyebrow "Workflow", title "Meetings & Viewings").
- `DataTable`: Type (badge: Meeting/Viewing), Title, When (`DateText` with time, showing the range compactly: "28 Sep, 09:00–10:00"), Location, Assignee (when `canManage`), Status (`StatusDot`), row action: **Record outcome** (visible when `status === 'scheduled'` and it's the assignee or `canManage`).
- "New meeting" dialog: Type (segmented), Title, Assignee, Start/End (native datetime-local inputs, `ends_at` validated client-side to be after `starts_at` before submit, mirroring the backend rule so the error is instant), Location (optional), the record picker for a lead and, separately, a listing (two independent optional pickers, since the backend accepts both).
- **Outcome dialog**: a textarea for the outcome note (required, matching `outcome: required|string|max:3000`). When the appointment has a `lead_id` **and** `canManage` (moving a stage requires `manageCrm`, matching the backend's own check — showing the control to someone who'd get a 403 would be misleading), an optional "Move this lead to…" stage `Select`, populated by fetching the lead's pipeline the same way the CRM pipeline board already does — reuse `crm-pipeline-board.ts`'s `boardColumns` data shape if convenient, but a plain stage list is enough here; if wiring that in cleanly takes real backend data this page doesn't have (the appointment props include no pipeline data), leave the stage move out of the outcome dialog for this phase and note it as a follow-up ("Change the lead's stage from CRM & Leads afterward") rather than block the whole page on a data shape Codex hasn't sent. **Decision:** ship the outcome text only in this phase; stage-move-from-appointment is a follow-up once its own small prop is added.

### 3.4 AI Matchmaker

**Index — `resources/js/pages/crm/Matchmaker.vue`.** Props: `leads: { id: number; first_name: string; last_name: string }[]`, `mode: 'local_rules'`, `canManage: boolean`.

- `PageHeader` (eyebrow "Sales & CRM", title "AI Matchmaker", description "Deterministic, explainable matching — not a black-box AI call.").
- A single searchable list (reuse the `Command`/`CommandInput` primitives already used for the ⌘K palette, in an inline, always-open form rather than a dialog) filtering the up-to-100 leads already sent as a prop — no new backend call needed here, this is a client-side filter over an in-memory list. Selecting a lead navigates to its detail route.

**Lead detail — `resources/js/pages/crm/MatchmakerLead.vue`.** Props: `leadId: number`, `preference: SearchPreference | null`, `matches: Match[]`, `mode: 'local_rules'`.

```ts
type SearchPreference = {
    purpose: 'sale' | 'rent';
    city: string | null;
    property_type: string | null;
    min_price_aed: string | null;
    max_price_aed: string | null;
} | null;
type Match = {
    listing_id: number;
    reference: string;
    property: string;
    city: string | null;
    unit: string;
    purpose: string;
    price_aed: number;
    reasons: string[];
};
```

- `DetailLayout`-style header (no `status`, since a lead here has no single status to show — pass nothing for that prop).
- A `FormSection` for the preference (purpose segmented, city/property type text inputs, min/max price money inputs), submitted to `PUT .../preference`. `max_price_aed < min_price_aed` is validated client-side before submit (mirrors the backend rule), so the error shows instantly.
- Below it, the matches: empty state "Set a search preference to see matches." when `preference` is null; "No active listings match this preference yet." when `preference` is set but `matches` is empty; otherwise a card per match with reference, property/city/unit, `Money`, and the `reasons` as small tags.

### 3.5 Broker Performance — `resources/js/pages/crm/BrokerPerformance.vue`

Props: `brokers: BrokerRow[]`.

```ts
type BrokerRow = {
    broker_id: number;
    name: string;
    team: string | null;
    leads: number;
    converted: number;
    deals: number | null;
    commission_aed: number | null;
};
```

- `PageHeader` (eyebrow "Sales & CRM", title "Broker Performance").
- `DataTable`: Broker, Leads, Converted (with a conversion % computed client-side: `converted / leads`, "—" when `leads` is 0), Deals (`— ` when null, meaning "not shown to a visibility-restricted viewer" — not zero), Commission (`Money`, "—" when null for the same reason, plus a small note under the table: "Deals and commission show only to owners without a restricted view." when any row has a null value, so the dash never reads as a real zero).
- Sortable by leads/converted/deals/commission using `DataTable`'s existing sort support.

## 4. New shared components

- **`ReasonDialog.vue`** (`resources/js/components/`): a small dialog with a required `Textarea`, used by Approvals' reject flow. Props: `open`, `title`, `description?`, `label` (for the textarea), `confirmLabel?`. Emits `update:open` and `confirm(reason: string)`. Disables confirm until the textarea is non-empty (mirrors the backend's `required`).
- **`RecordPicker.vue`** (`resources/js/components/`): props `type: 'lead' | 'listing' | 'unit' | 'reservation' | 'lease' | 'sale' | 'job'`, `modelValue: number | null`. Debounced search box over `GET /api/records/search?type=...&q=...`. States: idle (nothing typed), searching, results, no results, and **unavailable** (endpoint 404s or errors — shows "Search isn't connected yet" and nothing else, never a silent failure and never fake rows). Selecting a result sets `modelValue` and shows the picked label with a clear (×) button. Used standalone for the single-type cases (Tasks' `related_type`+`related_id` pair: a type `Select` next to one `RecordPicker` whose `type` prop is bound to the selected type) and twice on Meetings (one for lead, one for listing, both always type `'lead'`/`'listing'`).
- **`lib/records.ts`**: pure helpers — `relatedRecordLabel(type, id)` builders are not needed (the picker already carries the label once chosen), but a `RELATED_TYPES` list (value/label pairs, translated) is shared between Tasks' type `Select` and any future use.

## 5. Testing

- Frontend: `lib` unit tests for any pure logic extracted (priority tone mapping, conversion-rate/percentage-safe-division reused from `percentChange`-style helpers, the client-side "is this task overdue" check, the ends-after-starts validation). `RecordPicker`'s network-failure fallback is tested at the unit level by mocking `fetch` to reject/404 and asserting the "unavailable" state, not by hitting a live endpoint.
- PHP: none — the backend and its tests are already merged.
- Manual/screenshot: all five pages in light, dark, and Arabic, plus RecordPicker's three states (typing, results, unavailable) with the endpoint absent (expected, until Codex ships it).

## 6. Out of scope for this phase

- The `GET /api/records/search` endpoint itself (Codex, requested).
- Moving a lead's stage from the meeting-outcome dialog (needs a small additional prop from Codex; follow-up).
- Server-side filtering/pagination controls on Tasks beyond what the backend already paginates.
- The remaining module groups (Finance & Operations, Marketing & off-plan, Compliance & reporting) — later phases, per the owner's stated order.
