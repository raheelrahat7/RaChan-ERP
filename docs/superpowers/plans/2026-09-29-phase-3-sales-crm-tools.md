# Phase 3 (Sales & CRM Tools) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build five pages against Codex's already-merged backend: Approvals, Tasks, Meetings & Viewings, AI Matchmaker (index + lead detail), Broker Performance. Turn their sidebar entries from "Soon" into real links. Ship a record picker that works today (records-search is optional per record) and upgrades automatically once Codex's endpoint lands.

**Architecture:** Pure, unit-tested logic in `resources/js/lib/sales-crm-tools.ts` (tone/icon/href lookups, overdue/time-range/price-range validation, conversion rate) and `resources/js/lib/record-search.ts` (the picker's network call, isolated so its failure modes are testable without a server). Pages are built entirely from phase-1/2 primitives (`PageHeader`, `DataTable`, `FilterBar`, `StatusDot`, `Money`, `DateText`, `FormField`) — no new visual language.

**Tech Stack:** Vue 3.5 SFC + TypeScript, Inertia 3 (`useForm`, `router`), Tailwind 4, shadcn-vue (`Dialog`, `Select`, `Command`, `Textarea`), `@lucide/vue`, `node:test`.

**Spec:** `docs/superpowers/specs/2026-09-29-phase-3-sales-crm-tools-design.md`. Backend contract: `docs/CODEX_RED_MODULE_SCOPES_2026_09_28.md` (route table) plus the controllers read directly (cited per task below). Record-search request to Codex: `docs/CODEX_RECORD_SEARCH_REQUEST_2026_09_29.md` (not yet built — Task 2 must not depend on it existing).

## Commands

Same as phases 1–2:
- `node-run`: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 <cmd>`
- `php-run`: `docker exec z1erp-web sh -lc 'cd /workspace && <cmd>'`
- Frontend tests: `node-run npm run test:frontend`
- Build: `php-run php artisan wayfinder:generate --with-form` then `docker run --rm -v "$PWD":/workspace -w /workspace -e WAYFINDER_GENERATED=1 node:22 npm run build`
- PHP suite (isolated DB, owner-approved): `docker exec -e DB_DATABASE=testing_claude z1erp-web sh -lc 'cd /workspace && php artisan test'` — expect 350 passed plus the same 5 pre-existing `testing_claude` guard failures (unrelated; see phase-2 ledger). Any new failure is a real regression.

Work on branch `bordeaux/phase-3-sales-crm-tools`.

## Global Constraints

- Frontend only. Backend for these five pages is already merged and tested by Codex — do not edit `app/`, `routes/`, `database/`, `config/`.
- Route calls to the app's own mutation endpoints use plain path strings with `router.post/put`, matching the codebase's existing convention (see `resources/js/pages/crm/Leads.vue`), **not** the generated Wayfinder helpers — this codebase only uses those for a handful of shell/auth links, not page business actions.
- Approvals' `approve_url`/`reject_url`/`href` are full URLs already computed server-side (they point at *other* controllers' existing routes) — use them exactly as given, never rebuild them client-side.
- No new npm dependencies.
- Every user-visible string through `t()`; new keys added to `resources/js/locales/ar.json` then synced with `php scripts/sync-arabic-catalog.php`.
- Logical direction utilities only (`ms-/me-/ps-/pe-/start-/end-`).
- Commit after each task, message ending `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Never push.

## Review Focus

1. **Reject without a reason:** the reject dialog's confirm button must be disabled (not just server-validated) until the textarea is non-empty — the backend requires `reason` on every reject route. Pinned in Task 3.
2. **A task/meeting with no related record** (`related_type`/`related_id`/`lead_id`/`listing_id` all null): the Tasks/Meetings tables must render a plain "—", never crash on a null related type. Pinned in Task 1 and Task 5/6.
3. **Broker Performance's `deals`/`commission_aed` being `null`** (visibility-restricted viewer) must render "—", never "0" — a real zero and "not shown to you" are different facts. Pinned in Task 8.
4. **RecordPicker when the search endpoint doesn't exist yet** (404, network error, or a non-array JSON body): must show "Search isn't connected yet", never throw, never show fabricated results. Pinned in Task 2.
5. **`ends_at` not after `starts_at`** on the Meetings create form: caught client-side before submit, not just left to the server's `after:starts_at` error. Pinned in Task 1 and Task 6.

---

### Task 1: Pure helpers (`lib/sales-crm-tools.ts`)

**Files:**
- Create: `resources/js/lib/sales-crm-tools.ts`
- Test: `tests/Frontend/sales-crm-tools.test.mjs`

**Interfaces:**
- **Produces:**
  - `RelatedType = 'lead' | 'reservation' | 'lease' | 'sale' | 'unit' | 'job'`
  - `RELATED_TYPES: { value: RelatedType; label: string }[]`
  - `relatedRecordIcon(type: RelatedType | null): Component` (a neutral dash icon for `null`)
  - `relatedRecordHref(type: RelatedType): string`
  - `Priority = 'low' | 'normal' | 'high' | 'urgent'`
  - `priorityTone(priority: Priority): string` (a Tailwind text-color class)
  - `isTaskOverdue(task: { status: string; due_at: string | null }, now?: Date): boolean`
  - `appointmentTimesValid(startsAt: string, endsAt: string): boolean`
  - `priceRangeValid(min: string | null | undefined, max: string | null | undefined): boolean`
  - `conversionRate(converted: number, leads: number): number | null`

- [ ] **Step 1: Write the failing tests** `tests/Frontend/sales-crm-tools.test.mjs`

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    RELATED_TYPES,
    appointmentTimesValid,
    conversionRate,
    isTaskOverdue,
    priceRangeValid,
    priorityTone,
    relatedRecordHref,
    relatedRecordIcon,
} from '../../resources/js/lib/sales-crm-tools.ts';

await test('every related type has a label, an icon and an href', () => {
    for (const { value } of RELATED_TYPES) {
        assert.ok(relatedRecordIcon(value), value);
        assert.ok(relatedRecordHref(value).startsWith('/'), value);
    }
    assert.equal(RELATED_TYPES.map((r) => r.value).length, new Set(RELATED_TYPES.map((r) => r.value)).size);
});

await test('a null related type still resolves to a neutral icon, never throws', () => {
    assert.ok(relatedRecordIcon(null));
});

await test('priority tone escalates from muted to destructive', () => {
    assert.equal(priorityTone('low'), priorityTone('normal'));
    assert.notEqual(priorityTone('normal'), priorityTone('high'));
    assert.notEqual(priorityTone('high'), priorityTone('urgent'));
});

await test('a task is overdue only while open and past its due date', () => {
    const now = new Date('2026-09-29T12:00:00Z');
    assert.equal(isTaskOverdue({ status: 'open', due_at: '2026-09-28T00:00:00Z' }, now), true);
    assert.equal(isTaskOverdue({ status: 'open', due_at: '2026-09-30T00:00:00Z' }, now), false);
    assert.equal(isTaskOverdue({ status: 'open', due_at: null }, now), false);
    assert.equal(isTaskOverdue({ status: 'completed', due_at: '2026-09-28T00:00:00Z' }, now), false);
});

await test('an appointment must end strictly after it starts', () => {
    assert.equal(appointmentTimesValid('2026-09-29T09:00', '2026-09-29T10:00'), true);
    assert.equal(appointmentTimesValid('2026-09-29T09:00', '2026-09-29T09:00'), false);
    assert.equal(appointmentTimesValid('2026-09-29T09:00', '2026-09-29T08:00'), false);
    assert.equal(appointmentTimesValid('not a date', '2026-09-29T10:00'), false);
});

await test('a price range with either bound empty is always valid; max must be at least min', () => {
    assert.equal(priceRangeValid(null, null), true);
    assert.equal(priceRangeValid('', '500000'), true);
    assert.equal(priceRangeValid('500000', ''), true);
    assert.equal(priceRangeValid('500000', '400000'), false);
    assert.equal(priceRangeValid('400000', '500000'), true);
    assert.equal(priceRangeValid('400000', '400000'), true);
});

await test('conversion rate is null with no leads, never a divide-by-zero', () => {
    assert.equal(conversionRate(0, 0), null);
    assert.equal(conversionRate(3, 12), 25);
    assert.equal(conversionRate(1, 3), 33.3);
});
```

- [ ] **Step 2: Run and confirm it fails**

Run: `node-run node --experimental-strip-types --test tests/Frontend/sales-crm-tools.test.mjs`

Expected: FAIL, module not found.

- [ ] **Step 3: Implement `resources/js/lib/sales-crm-tools.ts`**

```ts
import {
    CalendarCheck,
    CircleDashed,
    FileKey,
    Handshake,
    UsersRound,
    Warehouse,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';

export type RelatedType =
    | 'lead'
    | 'reservation'
    | 'lease'
    | 'sale'
    | 'unit'
    | 'job';

export const RELATED_TYPES: { value: RelatedType; label: string }[] = [
    { value: 'lead', label: 'Lead' },
    { value: 'reservation', label: 'Reservation' },
    { value: 'lease', label: 'Lease' },
    { value: 'sale', label: 'Sale' },
    { value: 'unit', label: 'Unit' },
    { value: 'job', label: 'Job card' },
];

const RELATED_ICONS: Record<RelatedType, Component> = {
    lead: UsersRound,
    reservation: CalendarCheck,
    lease: FileKey,
    sale: Handshake,
    unit: Warehouse,
    job: Wrench,
};

export function relatedRecordIcon(type: RelatedType | null): Component {
    return type ? RELATED_ICONS[type] : CircleDashed;
}

const RELATED_HREFS: Record<RelatedType, string> = {
    lead: '/crm/leads',
    reservation: '/reservations',
    lease: '/agreements',
    sale: '/agreements',
    unit: '/inventory',
    job: '/maintenance',
};

export function relatedRecordHref(type: RelatedType): string {
    return RELATED_HREFS[type];
}

export type Priority = 'low' | 'normal' | 'high' | 'urgent';

const PRIORITY_TONE: Record<Priority, string> = {
    low: 'text-muted-foreground',
    normal: 'text-muted-foreground',
    high: 'text-warning',
    urgent: 'text-destructive',
};

export function priorityTone(priority: Priority): string {
    return PRIORITY_TONE[priority];
}

export function isTaskOverdue(
    task: { status: string; due_at: string | null },
    now: Date = new Date(),
): boolean {
    return (
        task.status === 'open' &&
        task.due_at !== null &&
        new Date(task.due_at).getTime() < now.getTime()
    );
}

export function appointmentTimesValid(startsAt: string, endsAt: string): boolean {
    const start = new Date(startsAt).getTime();
    const end = new Date(endsAt).getTime();

    return Number.isFinite(start) && Number.isFinite(end) && end > start;
}

export function priceRangeValid(
    min: string | null | undefined,
    max: string | null | undefined,
): boolean {
    if (!min || !max) {
        return true;
    }

    return Number(max) >= Number(min);
}

export function conversionRate(
    converted: number,
    leads: number,
): number | null {
    if (leads <= 0) {
        return null;
    }

    return Math.round((converted / leads) * 1000) / 10;
}
```

- [ ] **Step 4: Run and confirm all 7 pass, then format/typecheck/lint**

Run: `node-run sh -c "npx vp fmt resources/js/lib tests/Frontend >/dev/null; npm run test:frontend && npm run typecheck && npm run lint"`

- [ ] **Step 5: Commit** `feat(sales-crm): pure helpers for related records, priority, overdue and validation`

---

### Task 2: Record search (`lib/record-search.ts`) and `RecordPicker.vue`

**Files:**
- Create: `resources/js/lib/record-search.ts`, `resources/js/components/RecordPicker.vue`
- Test: `tests/Frontend/record-search.test.mjs`

**Interfaces:**
- **Consumes:** `RelatedType`, `RECORD_TYPES` is not reused directly (RecordPicker takes a wider type union including `'listing'`, see below).
- **Produces:**
  - `SearchableType = RelatedType | 'listing'`
  - `RecordSearchResult = { id: number; label: string; sublabel: string | null }`
  - `RecordSearchOutcome = { status: 'ok'; results: RecordSearchResult[] } | { status: 'unavailable' }`
  - `searchRecords(type: SearchableType, query: string, fetchImpl?: typeof fetch): Promise<RecordSearchOutcome>`
  - Component `<RecordPicker :type modelValue @update:modelValue />`, emitting the selected `id` (or `null` when cleared)

- [ ] **Step 1: Write the failing tests** `tests/Frontend/record-search.test.mjs`

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { searchRecords } from '../../resources/js/lib/record-search.ts';

function fakeFetch(handler) {
    return async (url) => handler(String(url));
}

await test('a query under two characters never calls the network', async () => {
    let called = false;
    const outcome = await searchRecords('lead', 'a', async () => {
        called = true;
        throw new Error('should not be called');
    });
    assert.equal(called, false);
    assert.deepEqual(outcome, { status: 'ok', results: [] });
});

await test('a 200 with a JSON array returns the results, url-encoded correctly', async () => {
    const outcome = await searchRecords(
        'lead',
        'Al Noor',
        fakeFetch(async (url) => {
            assert.ok(url.includes('type=lead'));
            assert.ok(url.includes('q=Al%20Noor') || url.includes('q=Al+Noor'));

            return { ok: true, json: async () => [{ id: 1, label: 'Al Noor Trading', sublabel: null }] };
        }),
    );
    assert.deepEqual(outcome, {
        status: 'ok',
        results: [{ id: 1, label: 'Al Noor Trading', sublabel: null }],
    });
});

await test('a non-2xx response is treated as unavailable, not an error', async () => {
    const outcome = await searchRecords('listing', 'ab', fakeFetch(async () => ({ ok: false, json: async () => [] })));
    assert.deepEqual(outcome, { status: 'unavailable' });
});

await test('a network failure is treated as unavailable', async () => {
    const outcome = await searchRecords('unit', 'ab', async () => {
        throw new TypeError('Failed to fetch');
    });
    assert.deepEqual(outcome, { status: 'unavailable' });
});

await test('a non-array JSON body is treated as unavailable, never surfaced as results', async () => {
    const outcome = await searchRecords(
        'job',
        'ab',
        fakeFetch(async () => ({ ok: true, json: async () => ({ error: 'nope' }) })),
    );
    assert.deepEqual(outcome, { status: 'unavailable' });
});
```

- [ ] **Step 2: Run and confirm it fails** (module not found)

- [ ] **Step 3: Implement `resources/js/lib/record-search.ts`**

```ts
import type { RelatedType } from '@/lib/sales-crm-tools';

export type SearchableType = RelatedType | 'listing';

export type RecordSearchResult = {
    id: number;
    label: string;
    sublabel: string | null;
};

export type RecordSearchOutcome =
    | { status: 'ok'; results: RecordSearchResult[] }
    | { status: 'unavailable' };

export async function searchRecords(
    type: SearchableType,
    query: string,
    fetchImpl: typeof fetch = fetch,
): Promise<RecordSearchOutcome> {
    const term = query.trim();
    if (term.length < 2) {
        return { status: 'ok', results: [] };
    }
    try {
        const url = `/api/records/search?type=${encodeURIComponent(type)}&q=${encodeURIComponent(term)}`;
        const response = await fetchImpl(url, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            return { status: 'unavailable' };
        }
        const body: unknown = await response.json();
        if (!Array.isArray(body)) {
            return { status: 'unavailable' };
        }

        return { status: 'ok', results: body as RecordSearchResult[] };
    } catch {
        return { status: 'unavailable' };
    }
}
```

- [ ] **Step 4: Run and confirm all 5 pass**

- [ ] **Step 5: Create `resources/js/components/RecordPicker.vue`**

```vue
<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { searchRecords } from '@/lib/record-search';
import type { RecordSearchResult, SearchableType } from '@/lib/record-search';

const props = defineProps<{
    type: SearchableType;
    modelValue: number | null;
    label: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const { t } = useLocale();
const query = ref('');
const results = ref<RecordSearchResult[]>([]);
const status = ref<'idle' | 'searching' | 'ok' | 'empty' | 'unavailable'>('idle');
const picked = ref<RecordSearchResult | null>(null);
let requestId = 0;

watch(query, async (value) => {
    if (props.modelValue !== null) {
        return;
    }
    const term = value.trim();
    if (term.length < 2) {
        status.value = 'idle';
        results.value = [];

        return;
    }
    status.value = 'searching';
    const id = ++requestId;
    const outcome = await searchRecords(props.type, term);
    if (id !== requestId) {
        return;
    }
    if (outcome.status === 'unavailable') {
        status.value = 'unavailable';
        results.value = [];

        return;
    }
    results.value = outcome.results;
    status.value = outcome.results.length === 0 ? 'empty' : 'ok';
});

function pick(result: RecordSearchResult): void {
    picked.value = result;
    emit('update:modelValue', result.id);
    query.value = '';
    results.value = [];
    status.value = 'idle';
}

function clear(): void {
    picked.value = null;
    emit('update:modelValue', null);
}

const showList = computed(
    () => props.modelValue === null && ['searching', 'ok', 'empty', 'unavailable'].includes(status.value),
);
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div v-if="modelValue !== null && picked" class="border-input bg-card flex items-center gap-2 rounded-sm border px-3 py-2 text-sm">
            <span class="min-w-0 flex-1 truncate">
                {{ picked.label }}
                <span v-if="picked.sublabel" class="text-muted-foreground">· {{ picked.sublabel }}</span>
            </span>
            <button type="button" class="text-muted-foreground hover:text-foreground" :aria-label="t('Clear selection')" @click="clear">
                <X class="size-4" />
            </button>
        </div>
        <template v-else>
            <Input v-model="query" :placeholder="t(label)" />
            <div v-if="showList" class="border-input bg-card max-h-48 overflow-y-auto rounded-sm border text-sm">
                <p v-if="status === 'searching'" class="text-muted-foreground p-2.5">{{ t('Searching…') }}</p>
                <p v-else-if="status === 'unavailable'" class="text-muted-foreground p-2.5">{{ t("Search isn't connected yet.") }}</p>
                <p v-else-if="status === 'empty'" class="text-muted-foreground p-2.5">{{ t('No matches.') }}</p>
                <button
                    v-for="result in results"
                    :key="result.id"
                    type="button"
                    class="hover:bg-accent flex w-full flex-col items-start px-2.5 py-1.5 text-start"
                    @click="pick(result)"
                >
                    <span>{{ result.label }}</span>
                    <span v-if="result.sublabel" class="text-muted-foreground text-xs">{{ result.sublabel }}</span>
                </button>
            </div>
        </template>
    </div>
</template>
```

- [ ] **Step 6: Add Arabic**, only missing keys, then sync:

```json
{"Clear selection":"إزالة التحديد","Searching…":"جارٍ البحث…","Search isn't connected yet.":"البحث غير متاح بعد.","No matches.":"لا توجد نتائج مطابقة."}
```

- [ ] **Step 7: Format, typecheck, lint. Commit** `feat(sales-crm): record search helper and picker, graceful without the backend endpoint`

---

### Task 3: `ReasonDialog.vue`

**Files:** Create `resources/js/components/ReasonDialog.vue`. No unit test — thin UI wrapper over `Dialog`/`Textarea`; verified by the Approvals page build/screenshot in Task 4.

**Interfaces:** `<ReasonDialog :open :title :description? :label :confirm-label? @update:open @confirm="(reason: string) => void" />`

- [ ] **Step 1: Create the component**

```vue
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useLocale } from '@/composables/useLocale';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        label: string;
        confirmLabel?: string;
    }>(),
    { description: undefined, confirmLabel: 'Confirm' },
);
const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [reason: string];
}>();

const { t } = useLocale();
const reason = ref('');

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            reason.value = '';
        }
    },
);

const canConfirm = computed(() => reason.value.trim().length > 0);

function confirm(): void {
    if (canConfirm.value) {
        emit('confirm', reason.value.trim());
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t(title) }}</DialogTitle>
                <DialogDescription v-if="description">{{ t(description) }}</DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-1.5">
                <label class="text-xs font-medium">{{ t(label) }}</label>
                <Textarea v-model="reason" :placeholder="t(label)" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="emit('update:open', false)">{{ t('Cancel') }}</Button>
                <Button variant="destructive" :disabled="!canConfirm" @click="confirm">{{ t(confirmLabel) }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
```

- [ ] **Step 2: Format, typecheck, lint. Commit** `feat(sales-crm): reason dialog for reject-with-reason flows`

---

### Task 4: Approvals — `resources/js/pages/approvals/Index.vue`

**Files:** Create the page. Test coverage is the PHP suite already merged (`tests/Feature/ApprovalsInboxTest.php`) plus a build/screenshot check — this page has no new pure logic to unit-test.

**Interfaces:** Consumes `PageHeader`, `Badge`, `Money`, `DateText`, `Button`, `ReasonDialog` (Task 3). Props from `App\Http\Controllers\ApprovalsInboxController::__invoke`: `items: ApprovalItem[]`, `count: number` (see spec §3.1 for the exact `ApprovalItem` shape).

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Money from '@/components/Money.vue';
import DateText from '@/components/DateText.vue';
import { useLocale } from '@/composables/useLocale';

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

defineProps<{ items: ApprovalItem[]; count: number }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Approvals', href: '/approvals' }] },
});

const { t } = useLocale();
const rejecting = ref<ApprovalItem | null>(null);

function approve(item: ApprovalItem): void {
    router.post(item.approve_url, {}, { preserveScroll: true });
}

function confirmReject(reason: string): void {
    if (!rejecting.value?.reject_url) {
        return;
    }
    router.post(
        rejecting.value.reject_url,
        { reason },
        { preserveScroll: true, onSuccess: () => (rejecting.value = null) },
    );
}
</script>

<template>
    <Head :title="t('Approvals')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="Workflow"
            title="Approvals"
            description="Everything waiting for your decision, across every module."
        />
        <div class="bg-card shadow-panel overflow-hidden rounded-lg border">
            <div v-if="items.length === 0" class="text-muted-foreground p-8 text-center text-sm">
                {{ t('Nothing is waiting for your approval.') }}
            </div>
            <table v-else class="w-full text-[13px]">
                <thead>
                    <tr class="text-label border-b">
                        <th class="py-2.5 ps-5 pe-3 text-start font-medium">{{ t('Module') }}</th>
                        <th class="px-3 text-start font-medium">{{ t('Transaction') }}</th>
                        <th class="px-3 text-start font-medium">{{ t('Requested by') }}</th>
                        <th class="px-3 text-start font-medium">{{ t('Submitted') }}</th>
                        <th class="px-3 text-end font-medium">{{ t('Amount') }}</th>
                        <th class="pe-5 ps-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.key" class="border-b last:border-b-0">
                        <td class="py-3 ps-5 pe-3"><Badge variant="outline">{{ t(item.module) }}</Badge></td>
                        <td class="px-3"><Link :href="item.href" class="text-accent-text font-medium hover:underline">{{ item.transaction }}</Link></td>
                        <td class="px-3">{{ item.requested_by }}</td>
                        <td class="px-3"><DateText :value="item.submitted_at" /></td>
                        <td class="px-3 text-end tabular-nums">
                            <Money v-if="item.amount !== null" :value="item.amount" />
                            <span v-else class="text-faint">—</span>
                        </td>
                        <td class="pe-5 ps-3">
                            <div class="flex justify-end gap-2">
                                <Button size="sm" @click="approve(item)">{{ t('Approve') }}</Button>
                                <Button v-if="item.reject_url" size="sm" variant="destructive-outline" @click="rejecting = item">{{ t('Reject') }}</Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ReasonDialog
            :open="rejecting !== null"
            title="Reject this item"
            description="This reason is recorded and, where the workflow supports it, shown to the person who submitted it."
            label="Reason"
            confirm-label="Reject"
            @update:open="(value) => !value && (rejecting = null)"
            @confirm="confirmReject"
        />
    </div>
</template>
```

- [ ] **Step 2: Add Arabic**, only missing keys, then sync:

```json
{"Approvals":"الموافقات","Everything waiting for your decision, across every module.":"كل ما ينتظر قرارك، عبر جميع الوحدات.","Nothing is waiting for your approval.":"لا شيء ينتظر موافقتك.","Module":"الوحدة","Transaction":"العملية","Requested by":"طلبها","Submitted":"تاريخ الإرسال","Amount":"المبلغ","Approve":"موافقة","Reject":"رفض","Reject this item":"رفض هذا العنصر","This reason is recorded and, where the workflow supports it, shown to the person who submitted it.":"يُسجَّل هذا السبب، وقد يظهر لمن قدّم الطلب حيثما يسمح سير العمل بذلك.","Reason":"السبب","Finance":"المالية","Leasing":"التأجير","Procurement":"المشتريات"}
```

- [ ] **Step 3: Verify.** Format, typecheck, lint, then `php-run php artisan wayfinder:generate --with-form` + build. `php-run php artisan test --filter=ApprovalsInboxTest` must still pass unchanged (it's Codex's test, not touched, but a build error here would still break its Inertia render — confirm it's green).

- [ ] **Step 4: Commit** `feat(approvals): approvals inbox with delegated approve/reject`

---

### Task 5: Tasks — `resources/js/pages/tasks/Index.vue`

**Files:** Create the page. Consumes Task 1's `RELATED_TYPES`, `priorityTone`, `isTaskOverdue`, `relatedRecordHref`, `relatedRecordIcon`, and Task 2's `RecordPicker`.

**Interfaces:** Props from `WorkTaskController::index`: `tasks: { data: Task[]; links: PaginationLink[] }`, `members: { id: number; name: string }[]`, `canManage: boolean`. `Task` shape per spec §3.2.

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CalendarClock } from '@lucide/vue';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPicker from '@/components/RecordPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import {
    RELATED_TYPES,
    isTaskOverdue,
    priorityTone,
    relatedRecordHref,
    relatedRecordIcon,
} from '@/lib/sales-crm-tools';
import type { Priority, RelatedType } from '@/lib/sales-crm-tools';
import type { DataTableColumn } from '@/lib/data-table';

type Task = {
    id: number;
    title: string;
    description: string | null;
    priority: Priority;
    status: 'open' | 'completed';
    assigned_to: number;
    assignee_name: string;
    due_at: string | null;
    related_type: RelatedType | null;
    related_id: number | null;
};

const props = defineProps<{
    tasks: { data: Task[]; links: { label: string; url: string | null; active: boolean }[] };
    members: { id: number; name: string }[];
    canManage: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Tasks', href: '/tasks' }] },
});

const { t } = useLocale();
const page = usePage();
const filter = ref<'open' | 'completed' | 'all'>('open');
const visible = computed(() =>
    props.tasks.data.filter((task) => filter.value === 'all' || task.status === filter.value),
);
const columns = computed<DataTableColumn<Task>[]>(() => [
    { key: 'title', label: 'Title' },
    ...(props.canManage ? ([{ key: 'assignee_name', label: 'Assignee' }] as DataTableColumn<Task>[]) : []),
    { key: 'priority', label: 'Priority' },
    { key: 'due_at', label: 'Due' },
    { key: 'related_type', label: 'Related' },
    { key: 'id', label: '', align: 'end' },
]);

function canComplete(task: Task): boolean {
    return task.status === 'open' && (props.canManage || task.assigned_to === page.props.auth.user.id);
}

const dialogOpen = ref(false);
const editing = ref<Task | null>(null);
const relatedType = ref<RelatedType | null>(null);
const relatedId = ref<number | null>(null);

const form = useForm({
    title: '',
    description: '',
    priority: 'normal' as Priority,
    assigned_to: page.props.auth.user.id as number,
    due_at: '',
    related_type: null as RelatedType | null,
    related_id: null as number | null,
});

function openCreate(): void {
    editing.value = null;
    relatedType.value = null;
    relatedId.value = null;
    form.reset();
    form.assigned_to = page.props.auth.user.id;
    dialogOpen.value = true;
}

function openEdit(task: Task): void {
    editing.value = task;
    relatedType.value = task.related_type;
    relatedId.value = task.related_id;
    form.title = task.title;
    form.description = task.description ?? '';
    form.priority = task.priority;
    form.assigned_to = task.assigned_to;
    form.due_at = task.due_at ?? '';
    dialogOpen.value = true;
}

function submit(): void {
    form.related_type = relatedType.value;
    form.related_id = relatedType.value ? relatedId.value : null;
    const onSuccess = () => {
        dialogOpen.value = false;
    };
    if (editing.value) {
        form.transform((data) => data).put(`/tasks/${editing.value.id}`, { preserveScroll: true, onSuccess });
    } else {
        form.post('/tasks', { preserveScroll: true, onSuccess });
    }
}

function complete(task: Task): void {
    form.post(`/tasks/${task.id}/complete`, { preserveScroll: true, only: ['tasks'] });
}
</script>

<template>
    <Head :title="t('Tasks')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="Workflow"
            title="Tasks"
            :description="canManage ? 'Assigned work with a due date.' : 'Your assigned tasks.'"
        >
            <template #actions>
                <Button @click="openCreate">{{ t('New task') }}</Button>
            </template>
        </PageHeader>

        <div class="flex gap-2">
            <Button v-for="option in (['open', 'completed', 'all'] as const)" :key="option" size="sm" :variant="filter === option ? 'default' : 'outline'" @click="filter = option">
                {{ t(option === 'open' ? 'Open' : option === 'completed' ? 'Completed' : 'All') }}
            </Button>
        </div>

        <DataTable
            :columns="columns"
            :rows="visible"
            :row-key="(row) => row.id"
            :row-label="(row) => row.title"
            :empty-title="filter === 'open' ? 'No open tasks.' : 'No tasks yet.'"
        >
            <template #cell-title="{ row }">
                <button type="button" class="font-medium hover:underline" @click="openEdit(row)">{{ row.title }}</button>
            </template>
            <template #cell-priority="{ row }">
                <span :class="priorityTone(row.priority)">{{ t(row.priority) }}</span>
            </template>
            <template #cell-due_at="{ row }">
                <span v-if="row.due_at" :class="isTaskOverdue(row) ? 'text-destructive' : ''">
                    <CalendarClock v-if="isTaskOverdue(row)" class="me-1 inline size-3.5" />
                    <DateText :value="row.due_at" />
                </span>
                <span v-else class="text-faint">—</span>
            </template>
            <template #cell-related_type="{ row }">
                <Link v-if="row.related_type" :href="relatedRecordHref(row.related_type)" class="text-accent-text inline-flex items-center gap-1.5 hover:underline">
                    <component :is="relatedRecordIcon(row.related_type)" class="size-3.5" />{{ t(RELATED_TYPES.find((r) => r.value === row.related_type)?.label ?? '') }}
                </Link>
                <span v-else class="text-faint">—</span>
            </template>
            <template #cell-id="{ row }">
                <Button v-if="canComplete(row)" size="sm" variant="outline" @click="complete(row)">{{ t('Complete') }}</Button>
            </template>
        </DataTable>
        <Pagination :links="tasks.links" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t(editing ? 'Edit task' : 'New task') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label for="task-title">{{ t('Title') }}</Label>
                        <Input id="task-title" v-model="form.title" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="task-description">{{ t('Description') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <Textarea id="task-description" v-model="form.description" />
                    </div>
                    <div v-if="canManage" class="flex flex-col gap-1.5">
                        <Label>{{ t('Assignee') }}</Label>
                        <Select :model-value="String(form.assigned_to)" @update:model-value="form.assigned_to = Number($event)">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="member in members" :key="member.id" :value="String(member.id)">{{ member.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.assigned_to" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="task-due">{{ t('Due date') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <Input id="task-due" v-model="form.due_at" type="date" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Related record') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <Select :model-value="relatedType ?? ''" @update:model-value="(value) => { relatedType = (value || null) as RelatedType | null; relatedId = null; }">
                            <SelectTrigger class="w-full"><SelectValue :placeholder="t('None')" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">{{ t('None') }}</SelectItem>
                                <SelectItem v-for="option in RELATED_TYPES" :key="option.value" :value="option.value">{{ t(option.label) }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <RecordPicker v-if="relatedType" :type="relatedType" v-model="relatedId" label="Search…" />
                        <InputError :message="form.errors.related_id" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ t(editing ? 'Save' : 'Create task') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
```

- [ ] **Step 2: Add Arabic**, only missing keys, then sync:

```json
{"Tasks":"المهام","Assigned work with a due date.":"مهام موكّلة بموعد استحقاق.","Your assigned tasks.":"مهامك الموكّلة إليك.","New task":"مهمة جديدة","Open":"مفتوحة","Completed":"مكتملة","All":"الكل","No open tasks.":"لا توجد مهام مفتوحة.","No tasks yet.":"لا توجد مهام بعد.","Title":"العنوان","Assignee":"المكلَّف","Priority":"الأولوية","Due":"الاستحقاق","Related":"مرتبط بـ","low":"منخفضة","normal":"عادية","high":"مرتفعة","urgent":"عاجلة","Complete":"إنجاز","Lead":"عميل محتمل","Reservation":"حجز","Lease":"عقد إيجار","Sale":"بيع","Unit":"وحدة","Job card":"بطاقة عمل","Edit task":"تعديل المهمة","Description":"الوصف","Due date":"تاريخ الاستحقاق","Related record":"سجل مرتبط","None":"بلا","Save":"حفظ","Cancel":"إلغاء","Search…":"ابحث…"}
```

- [ ] **Step 3: Verify.** Format, typecheck, lint, build. Confirm `php-run php artisan test --filter=WorkTaskTest` still passes.

- [ ] **Step 4: Commit** `feat(tasks): task list, create/edit dialog and completion`

---

### Task 6: Meetings & Viewings — `resources/js/pages/meetings/Index.vue`

**Files:** Create the page. Reuses Task 1's `appointmentTimesValid`, Task 2's `RecordPicker`.

**Interfaces:** Props from `AppointmentController::index`: `appointments: { data: Appointment[]; links: PaginationLink[] }`, `members`, `canManage: boolean`. `Appointment` shape per spec §3.3. Mutations: `POST /meetings` (create), `POST /meetings/{id}/outcome` (outcome text only this phase — see spec §3.3 decision; do not add a stage-move control).

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPicker from '@/components/RecordPicker.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import { appointmentTimesValid } from '@/lib/sales-crm-tools';
import type { DataTableColumn } from '@/lib/data-table';

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

const props = defineProps<{
    appointments: { data: Appointment[]; links: { label: string; url: string | null; active: boolean }[] };
    members: { id: number; name: string }[];
    canManage: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Meetings & Viewings', href: '/meetings' }] },
});

const { t } = useLocale();
const page = usePage();

const dialogOpen = ref(false);
const leadId = ref<number | null>(null);
const listingId = ref<number | null>(null);
const timeError = ref<string | null>(null);

const form = useForm({
    type: 'meeting' as 'meeting' | 'viewing',
    title: '',
    assigned_to: page.props.auth.user.id as number,
    starts_at: '',
    ends_at: '',
    location: '',
    lead_id: null as number | null,
    listing_id: null as number | null,
});

function openCreate(): void {
    leadId.value = null;
    listingId.value = null;
    timeError.value = null;
    form.reset();
    form.assigned_to = page.props.auth.user.id;
    dialogOpen.value = true;
}

function submit(): void {
    if (!appointmentTimesValid(form.starts_at, form.ends_at)) {
        timeError.value = t('The end time must be after the start time.');

        return;
    }
    timeError.value = null;
    form.lead_id = leadId.value;
    form.listing_id = listingId.value;
    form.post('/meetings', { preserveScroll: true, onSuccess: () => (dialogOpen.value = false) });
}

const outcoming = ref<Appointment | null>(null);
const outcomeForm = useForm({ outcome: '' });

function submitOutcome(): void {
    if (!outcoming.value) {
        return;
    }
    outcomeForm.post(`/meetings/${outcoming.value.id}/outcome`, {
        preserveScroll: true,
        onSuccess: () => {
            outcoming.value = null;
            outcomeForm.reset();
        },
    });
}

function canRecordOutcome(appointment: Appointment): boolean {
    return appointment.status === 'scheduled' && (props.canManage || appointment.assigned_to === page.props.auth.user.id);
}

function timeRange(appointment: Appointment): string {
    const start = new Date(appointment.starts_at);
    const end = new Date(appointment.ends_at);
    const time = (date: Date) => date.toISOString().slice(11, 16);

    return `${time(start)}–${time(end)}`;
}

const columns = computed<DataTableColumn<Appointment>[]>(() => [
    { key: 'type', label: 'Type' },
    { key: 'title', label: 'Title' },
    { key: 'starts_at', label: 'When' },
    { key: 'location', label: 'Location' },
    ...(props.canManage ? ([{ key: 'assigned_to', label: 'Assignee' }] as DataTableColumn<Appointment>[]) : []),
    { key: 'status', label: 'Status' },
    { key: 'id', label: '', align: 'end' },
]);
</script>

<template>
    <Head :title="t('Meetings & Viewings')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Workflow" title="Meetings & Viewings">
            <template #actions><Button @click="openCreate">{{ t('New meeting') }}</Button></template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="appointments.data"
            :row-key="(row) => row.id"
            :row-label="(row) => row.title"
            empty-title="No meetings or viewings scheduled."
        >
            <template #cell-type="{ row }">
                <Badge variant="outline">{{ t(row.type === 'meeting' ? 'Meeting' : 'Viewing') }}</Badge>
            </template>
            <template #cell-title="{ row }">
                <span class="font-medium">{{ row.title }}</span>
            </template>
            <template #cell-starts_at="{ row }">
                <DateText :value="row.starts_at" /> · {{ timeRange(row) }}
            </template>
            <template #cell-location="{ row }">{{ row.location ?? '—' }}</template>
            <template #cell-assigned_to="{ row }">{{ members.find((m) => m.id === row.assigned_to)?.name ?? '—' }}</template>
            <template #cell-status="{ row }"><StatusDot :status="row.status" /></template>
            <template #cell-id="{ row }">
                <Button v-if="canRecordOutcome(row)" size="sm" variant="outline" @click="outcoming = row">{{ t('Record outcome') }}</Button>
            </template>
        </DataTable>
        <Pagination :links="appointments.links" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('New meeting') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Type') }}</Label>
                        <Select v-model="form.type">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="meeting">{{ t('Meeting') }}</SelectItem>
                                <SelectItem value="viewing">{{ t('Viewing') }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="meeting-title">{{ t('Title') }}</Label>
                        <Input id="meeting-title" v-model="form.title" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div v-if="canManage" class="flex flex-col gap-1.5">
                        <Label>{{ t('Assignee') }}</Label>
                        <Select :model-value="String(form.assigned_to)" @update:model-value="form.assigned_to = Number($event)">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="member in members" :key="member.id" :value="String(member.id)">{{ member.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="meeting-start">{{ t('Starts') }}</Label>
                            <Input id="meeting-start" v-model="form.starts_at" type="datetime-local" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="meeting-end">{{ t('Ends') }}</Label>
                            <Input id="meeting-end" v-model="form.ends_at" type="datetime-local" />
                        </div>
                    </div>
                    <p v-if="timeError" class="text-destructive text-xs">{{ timeError }}</p>
                    <InputError :message="form.errors.ends_at" />
                    <div class="flex flex-col gap-1.5">
                        <Label for="meeting-location">{{ t('Location') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <Input id="meeting-location" v-model="form.location" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Lead') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <RecordPicker type="lead" v-model="leadId" label="Search leads…" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Listing') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <RecordPicker type="listing" v-model="listingId" label="Search listings…" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ t('Create') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="outcoming !== null" @update:open="(value) => !value && (outcoming = null)">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('Record outcome') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitOutcome">
                    <div class="flex flex-col gap-1.5">
                        <Label for="outcome-text">{{ t('What happened?') }}</Label>
                        <Textarea id="outcome-text" v-model="outcomeForm.outcome" />
                        <InputError :message="outcomeForm.errors.outcome" />
                    </div>
                    <p class="text-muted-foreground text-xs">{{ t("To move this lead's stage, use CRM & Leads afterward.") }}</p>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="outcoming = null">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="outcomeForm.processing">{{ t('Save outcome') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
```

- [ ] **Step 2: Add Arabic**, only missing keys, then sync:

```json
{"Meetings & Viewings":"الاجتماعات والمعاينات","New meeting":"اجتماع جديد","No meetings or viewings scheduled.":"لا توجد اجتماعات أو معاينات مجدولة.","Type":"النوع","When":"الموعد","Location":"الموقع","Status":"الحالة","Meeting":"اجتماع","Viewing":"معاينة","Record outcome":"تسجيل النتيجة","Starts":"البداية","Ends":"النهاية","The end time must be after the start time.":"يجب أن يكون وقت الانتهاء بعد وقت البدء.","Lead":"عميل محتمل","Listing":"عرض عقاري","Search leads…":"ابحث عن عملاء محتملين…","Search listings…":"ابحث عن عروض…","Create":"إنشاء","What happened?":"ماذا حدث؟","To move this lead's stage, use CRM & Leads afterward.":"لنقل مرحلة هذا العميل المحتمل، استخدم صفحة إدارة العملاء لاحقًا.","Save outcome":"حفظ النتيجة","scheduled":"مجدول","completed":"مكتمل"}
```

Check `StatusDot`'s status map (`resources/js/lib/status-tones.ts`) already maps `scheduled`/`completed` — it does (`scheduled: 'info'`, `completed: 'success'`), so no lib change is needed here, only the Arabic label if missing.

- [ ] **Step 3: Verify.** Format, typecheck, lint, build. Confirm `php-run php artisan test --filter=AppointmentTest` still passes.

- [ ] **Step 4: Commit** `feat(meetings): meetings and viewings list, create form, outcome recording`

---

### Task 7: AI Matchmaker — index and lead detail

**Files:** Create `resources/js/pages/crm/Matchmaker.vue`, `resources/js/pages/crm/MatchmakerLead.vue`. Reuses Task 1's `priceRangeValid`.

**Interfaces:** Index props from `MatchmakerController::index`: `leads: { id: number; first_name: string; last_name: string }[]`, `mode: 'local_rules'`, `canManage: boolean`. Lead-detail props from `MatchmakerController::show`: `leadId: number`, `preference: SearchPreference | null`, `matches: Match[]`, `mode: 'local_rules'` (shapes per spec §3.4). Mutation: `PUT /crm/matchmaker/leads/{lead}/preference`.

- [ ] **Step 1: Create `resources/js/pages/crm/Matchmaker.vue`**

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Search, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';

defineProps<{
    leads: { id: number; first_name: string; last_name: string }[];
    mode: 'local_rules';
    canManage: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'AI Matchmaker', href: '/crm/matchmaker' }] },
});

const { t } = useLocale();
const query = ref('');
const filtered = computed(() => {
    const term = query.value.trim().toLowerCase();

    return term.length === 0
        ? []
        : leads.filter((lead) => `${lead.first_name} ${lead.last_name}`.toLowerCase().includes(term)).slice(0, 20);
});
</script>

<template>
    <Head :title="t('AI Matchmaker')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="Sales & CRM"
            title="AI Matchmaker"
            description="Deterministic, explainable matching — not a black-box AI call."
        />
        <div class="bg-card shadow-panel rounded-lg border p-5">
            <label class="relative block">
                <Search class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                <Input v-model="query" class="ps-9" :placeholder="t('Search leads by name…')" />
            </label>
            <ul v-if="filtered.length" class="mt-3 flex flex-col gap-1">
                <li v-for="lead in filtered" :key="lead.id">
                    <Link :href="`/crm/matchmaker/leads/${lead.id}`" class="hover:bg-accent flex items-center gap-2 rounded-sm px-2.5 py-2 text-sm">
                        <Sparkles class="text-accent-text size-4" />{{ lead.first_name }} {{ lead.last_name }}
                    </Link>
                </li>
            </ul>
            <p v-else-if="query.trim().length > 0" class="text-muted-foreground mt-3 text-sm">{{ t('No leads match that name.') }}</p>
            <p v-else class="text-muted-foreground mt-3 text-sm">{{ t('Start typing a lead name to find matches for them.') }}</p>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Create `resources/js/pages/crm/MatchmakerLead.vue`**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import Money from '@/components/Money.vue';
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import { priceRangeValid } from '@/lib/sales-crm-tools';

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

const props = defineProps<{
    leadId: number;
    preference: SearchPreference;
    matches: Match[];
    mode: 'local_rules';
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'AI Matchmaker', href: '/crm/matchmaker' }, { title: 'Lead', href: '#' }] },
});

const { t } = useLocale();
const form = useForm({
    purpose: props.preference?.purpose ?? 'sale',
    city: props.preference?.city ?? '',
    property_type: props.preference?.property_type ?? '',
    min_price_aed: props.preference?.min_price_aed ?? '',
    max_price_aed: props.preference?.max_price_aed ?? '',
});
const rangeError = ref<string | null>(null);

function submit(): void {
    if (!priceRangeValid(form.min_price_aed, form.max_price_aed)) {
        rangeError.value = t('The maximum price must be at least the minimum price.');

        return;
    }
    rangeError.value = null;
    form.put(`/crm/matchmaker/leads/${props.leadId}/preference`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('AI Matchmaker')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Sales & CRM" title="Search preference & matches" />

        <div class="bg-card shadow-panel rounded-lg border p-5">
            <form class="grid grid-cols-2 gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label>{{ t('Purpose') }}</Label>
                    <Select v-model="form.purpose">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="sale">{{ t('Sale') }}</SelectItem>
                            <SelectItem value="rent">{{ t('Rent') }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="pref-city">{{ t('City') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                    <Input id="pref-city" v-model="form.city" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="pref-type">{{ t('Property type') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                    <Input id="pref-type" v-model="form.property_type" />
                </div>
                <div class="col-span-2 grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <Label for="pref-min">{{ t('Minimum price (AED)') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <Input id="pref-min" v-model="form.min_price_aed" type="number" min="0" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="pref-max">{{ t('Maximum price (AED)') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <Input id="pref-max" v-model="form.max_price_aed" type="number" min="0" />
                    </div>
                </div>
                <p v-if="rangeError" class="col-span-2 text-destructive text-xs">{{ rangeError }}</p>
                <InputError class="col-span-2" :message="form.errors.max_price_aed" />
                <div class="col-span-2 flex justify-end">
                    <Button type="submit" :disabled="form.processing">{{ t('Save preference') }}</Button>
                </div>
            </form>
        </div>

        <div>
            <h2 class="font-display mb-3 text-2xl font-medium">{{ t('Matches') }}</h2>
            <p v-if="!preference" class="text-muted-foreground text-sm">{{ t('Set a search preference to see matches.') }}</p>
            <p v-else-if="matches.length === 0" class="text-muted-foreground text-sm">{{ t('No active listings match this preference yet.') }}</p>
            <div v-else class="grid gap-3 md:grid-cols-2">
                <div v-for="match in matches" :key="match.listing_id" class="bg-card shadow-panel rounded-lg border p-4">
                    <p class="font-medium">{{ match.reference }}</p>
                    <p class="text-muted-foreground text-sm">{{ match.property }} · {{ match.city }} · {{ match.unit }}</p>
                    <p class="font-display mt-1.5 text-lg"><Money :value="match.price_aed" /></p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span v-for="reason in match.reasons" :key="reason" class="bg-success/10 text-success rounded-full px-2 py-0.5 text-[11px]">{{ t(reason) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Add Arabic**, only missing keys, then sync:

```json
{"AI Matchmaker":"المطابقة الذكية","Deterministic, explainable matching — not a black-box AI call.":"مطابقة حتمية وواضحة السبب — وليست استدعاء ذكاء اصطناعي غامضًا.","Search leads by name…":"ابحث عن عميل محتمل بالاسم…","No leads match that name.":"لا يوجد عملاء محتملون بهذا الاسم.","Start typing a lead name to find matches for them.":"ابدأ بكتابة اسم عميل محتمل لعرض المطابقات له.","Search preference & matches":"تفضيلات البحث والمطابقات","Purpose":"الغرض","Sale":"بيع","Rent":"إيجار","City":"المدينة","Property type":"نوع العقار","Minimum price (AED)":"الحد الأدنى للسعر (د.إ)","Maximum price (AED)":"الحد الأقصى للسعر (د.إ)","The maximum price must be at least the minimum price.":"يجب ألا يقل الحد الأقصى للسعر عن الحد الأدنى.","Save preference":"حفظ التفضيل","Matches":"المطابقات","Set a search preference to see matches.":"حدد تفضيل بحث لعرض المطابقات.","No active listings match this preference yet.":"لا توجد عروض نشطة تطابق هذا التفضيل بعد.","Matching purpose":"مطابقة الغرض","Matching city":"مطابقة المدينة","Matching property type":"مطابقة نوع العقار","Within budget":"ضمن الميزانية"}
```

- [ ] **Step 4: Verify.** Format, typecheck, lint, build. Confirm `php-run php artisan test --filter=LocalMatchmakerTest` still passes.

- [ ] **Step 5: Commit** `feat(crm): AI matchmaker lead search, preference and matches`

---

### Task 8: Broker Performance — `resources/js/pages/crm/BrokerPerformance.vue`

**Files:** Create the page. Reuses Task 1's `conversionRate`, phase-1's `DataTable`.

**Interfaces:** Props from `SidebarReportsController::brokerPerformance`: `brokers: BrokerRow[]` (shape per spec §3.5).

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useLocale } from '@/composables/useLocale';
import { conversionRate } from '@/lib/sales-crm-tools';
import type { DataTableColumn, SortState } from '@/lib/data-table';

type BrokerRow = {
    broker_id: number;
    name: string;
    team: string | null;
    leads: number;
    converted: number;
    deals: number | null;
    commission_aed: number | null;
};

const props = defineProps<{ brokers: BrokerRow[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Broker Performance', href: '/crm/broker-performance' }] },
});

const { t } = useLocale();
const columns: DataTableColumn<BrokerRow>[] = [
    { key: 'name', label: 'Broker', sortable: true },
    { key: 'leads', label: 'Leads', align: 'end', sortable: true },
    { key: 'converted', label: 'Converted', align: 'end', sortable: true },
    { key: 'deals', label: 'Deals', align: 'end', sortable: true },
    { key: 'commission_aed', label: 'Commission', align: 'end', sortable: true },
];
const sort = ref<SortState>({ key: 'commission_aed', direction: 'desc' });
const hasRestrictedRows = computed(() => props.brokers.some((row) => row.deals === null || row.commission_aed === null));

const rows = computed(() => {
    if (!sort.value) {
        return props.brokers;
    }
    const { key, direction } = sort.value;

    return [...props.brokers].sort((a, b) => {
        const left = a[key as keyof BrokerRow] ?? -1;
        const right = b[key as keyof BrokerRow] ?? -1;
        const order = left < right ? -1 : left > right ? 1 : 0;

        return direction === 'asc' ? order : -order;
    });
});
</script>

<template>
    <Head :title="t('Broker Performance')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Sales & CRM" title="Broker Performance" />
        <DataTable
            v-model:sort="sort"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.broker_id"
            empty-title="No brokers yet"
        >
            <template #cell-name="{ row }">
                <span class="font-medium">{{ row.name }}</span>
                <span v-if="row.team" class="text-muted-foreground"> · {{ row.team }}</span>
            </template>
            <template #cell-converted="{ row }">
                {{ row.converted }}
                <span v-if="conversionRate(row.converted, row.leads) !== null" class="text-muted-foreground">
                    ({{ conversionRate(row.converted, row.leads) }}%)
                </span>
            </template>
            <template #cell-deals="{ row }">
                <span v-if="row.deals === null" class="text-faint">—</span>
                <span v-else>{{ row.deals }}</span>
            </template>
            <template #cell-commission_aed="{ row }">
                <span v-if="row.commission_aed === null" class="text-faint">—</span>
                <Money v-else :value="row.commission_aed" />
            </template>
        </DataTable>
        <p v-if="hasRestrictedRows" class="text-muted-foreground text-xs">
            {{ t('Deals and commission show only to owners without a restricted view.') }}
        </p>
    </div>
</template>
```

- [ ] **Step 2: Add Arabic**, only missing keys, then sync:

```json
{"Broker Performance":"أداء الوسطاء","Broker":"الوسيط","Leads":"العملاء المحتملون","Converted":"محوَّل","Deals":"الصفقات","Commission":"العمولة","No brokers yet":"لا يوجد وسطاء بعد","Deals and commission show only to owners without a restricted view.":"تظهر الصفقات والعمولات فقط للمالكين الذين لا تُقيَّد رؤيتهم."}
```

- [ ] **Step 3: Verify.** Format, typecheck, lint, build.

- [ ] **Step 4: Commit** `feat(crm): broker performance leaderboard`

---

### Task 9: Navigation wiring and phase verification

**Files:**
- Modify: `resources/js/lib/navigation.ts` (turn 4 `soon` items into real links)
- Modify: `tests/Frontend/navigation.test.mjs` if any assertion about these items being soon needs removing

- [ ] **Step 1: Update `navigation.ts`**

Replace:
```ts
soon('AI Matchmaker', 'matchmaker', 'crm'),
```
with:
```ts
{ label: 'AI Matchmaker', href: '/crm/matchmaker', icon: 'matchmaker', ability: 'crm' },
```

Replace the Workflow group's three `soon(...)` entries (`Approvals`, `Tasks`, `Meetings & Viewings`) with:
```ts
{ label: 'Approvals', href: '/approvals', icon: 'approvals' },
{ label: 'Tasks', href: '/tasks', icon: 'tasks' },
{ label: 'Meetings & Viewings', href: '/meetings', icon: 'viewings', ability: 'crm' },
```

Broker Performance already has a real link from phase 2 (`{ label: 'Broker Performance', href: '/crm/pipeline-report', ... }` — **check this**: phase 2's plan pointed Broker Performance at `/crm/pipeline-report` as a stand-in, since its real page didn't exist yet. Update its `href` to `/crm/broker-performance` now that the real page exists.

- [ ] **Step 2: Run the navigation test**

Run: `node-run node --experimental-strip-types --test tests/Frontend/navigation.test.mjs`

Expected: still all pass — `navHrefs()` now includes the 4 new real paths, and the route-existence test (which scans `routes/web.php`) confirms each one exists (it does, Codex merged them).

- [ ] **Step 3: Full phase verification**

```bash
node-run sh -c "npm run test:frontend && npm run typecheck && npm run lint"
php-run php artisan wayfinder:generate --with-form
docker run --rm -v "$PWD":/workspace -w /workspace -e WAYFINDER_GENERATED=1 node:22 npm run build
docker exec -e DB_DATABASE=testing_claude z1erp-web sh -lc 'cd /workspace && php artisan test'
```

Expected: frontend tests all pass (baseline 62 plus this phase's new ones), typecheck/lint/build clean, PHP at 350 passed plus the same 5 pre-existing guard failures — no new PHP failures (nothing here touches backend files, so none should appear; if any do, treat as a real regression and stop to investigate, don't wave it away as "expected").

- [ ] **Step 4: Screenshots**

Log in as the existing local reviewer user. Screenshot, in light, dark and Arabic: Approvals (empty state, since the test org has none pending), Tasks (with at least one created via the UI during this check), Meetings, Matchmaker index and a lead's match view, Broker Performance. Also screenshot RecordPicker's "unavailable" state (expected, since Codex hasn't built the search endpoint yet) inside the Tasks "New task" dialog with a related-record type selected.

- [ ] **Step 5: Report to the owner**

Cover: what shipped, the screenshots, that Codex's record-search endpoint is still pending (link `docs/CODEX_RECORD_SEARCH_REQUEST_2026_09_29.md`) and everything works without it, the stage-move-from-appointment follow-up noted in the spec, and ask about merging plus which module group to design next (Finance & Operations / Marketing & off-plan / Compliance & reporting, per the earlier priority question).
