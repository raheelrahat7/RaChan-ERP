# Phase 4: Marketing & Off-Plan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship 5 frontend pages (off-plan projects index/detail, portal marketing, subscriptions, costing) against Codex's already-built backend, and wire them into navigation.

**Architecture:** Each page is a plain Inertia page component under `resources/js/pages/real-estate/` or `resources/js/pages/marketing/`, following the exact patterns established in Phase 3 (`DataTable` for lists, `Dialog` + `useForm` for create/mutate flows, `RecordPicker` only for lead selection, plain fields for any foreign key without a props-provided option list). No new shared components are needed.

**Tech Stack:** Same as Phases 1–3 — Vue 3 `<script setup lang="ts">`, Inertia 3, TypeScript, Tailwind 4, shadcn-vue, `@lucide/vue`.

**Spec:** `docs/superpowers/specs/2026-09-29-phase-4-marketing-offplan-design.md`

## Global Constraints

- Every new user-visible string goes through `t()` and gets an Arabic translation in `resources/js/locales/ar.json`, synced to `lang/ar.json` via `php scripts/sync-arabic-catalog.php`.
- Never use physical `ml-/mr-/pl-/pr-/left-/right-/text-left/text-right` — always logical `ms-/me-/ps-/pe-/start-/end-/text-start/text-end`.
- Missing/restricted backend data (`cost_per_deal`, `roi` — permanently `null`, not permission-gated) renders as `—`, never "Coming soon" (that phrase is reserved for permission-gated nulls, per the Home dashboard convention).
- `RecordPicker` is used only where its `SearchableType` already covers the field (`lead`, `listing`); every other foreign key without a props-provided `<Select>` option list is a plain `<Input type="number">` with helper text, matching Phase 3's vendor-bill precedent.
- All docker/test commands follow the Phase 3 pattern: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 <cmd>` for frontend, `docker exec z1erp-web sh -lc 'cd /workspace && <cmd>'` for PHP, `-e DB_DATABASE=testing_claude` for the full PHP suite (owner-approved dedicated test DB from Phase 2).

## Review Focus

- **Off-plan deal status transitions must reject invalid moves client-side** — the button set shown is computed from `deal.status`, never unconditional (Task 3's tests cover this).
- **Milestone percentage overflow tolerance must match the server's `> 100.001`, not a stricter `> 100`** (Task 3's tests cover this).
- **`cost_per_deal`/`roi` always render `—`, never "Coming soon"** — enforced structurally in Task 6's template (both fields are hardcoded `<dd class="text-faint">—</dd>`, not driven by a conditional that could accidentally show a "coming soon" string); no separate unit test applies since there's no branching logic to test.
- **Spend source toggle clears a stale vendor-bill ID when switching back to `estimate`** (Task 6's tests cover this).
- **Publication uniqueness rejection surfaces via `InputError`, not silently** — covered by using the existing `form.errors.portal` binding already proven in Phase 3's dialogs (no separate test needed; it's the same `useForm` error-binding pattern already covered by TypeScript and by Codex's own backend test).

---

### Task 1: Status tones for off-plan and marketing statuses

**Files:**
- Modify: `resources/js/lib/status-tones.ts`
- Test: `tests/Frontend/status-tones.test.mjs`
- Modify: `resources/js/locales/ar.json` (Arabic labels, required by the existing "every mapped status has an Arabic label" test)

**Interfaces:**
- Produces: `STATUS_TONES` gains `enquiry: 'info'`, `contracted: 'success'`, `local_validated: 'info'` — consumed by every later task's `StatusDot`/badge usage.

- [ ] **Step 1: Write the failing test**

Add to `tests/Frontend/status-tones.test.mjs` (find the existing `import` block at the top and the last `await test(...)` block, then append):

```js
await test('off-plan and marketing statuses map to the right tone', () => {
    assert.equal(statusTone('enquiry'), 'info');
    assert.equal(statusTone('contracted'), 'success');
    assert.equal(statusTone('local_validated'), 'info');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/status-tones.test.mjs`
Expected: FAIL — `assert.equal(statusTone('enquiry'), 'info')` gets `'neutral'` (the fallback), not `'info'`.

- [ ] **Step 3: Add the statuses**

In `resources/js/lib/status-tones.ts`, add to the `STATUS_TONES` object (alongside the existing `info:` entries like `scheduled: 'info',`):

```ts
    enquiry: 'info',
    local_validated: 'info',
```

And alongside the existing `success:` entries like `signed: 'success',`:

```ts
    contracted: 'success',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/status-tones.test.mjs`
Expected: PASS, including the pre-existing "every mapped status has an Arabic label" test — which will now FAIL unless Step 5 is also done first. Do Step 5, then run this again.

- [ ] **Step 5: Add Arabic labels**

Add to `resources/js/locales/ar.json` (only if missing — check first) the capitalized-label keys the "every mapped status has an Arabic label" test requires (`statusLabel()` title-cases and space-separates the raw key):

```json
{
    "Enquiry": "استفسار",
    "Local validated": "تحقق محلي",
    "Contracted": "متعاقد"
}
```

Then run: `docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'`

- [ ] **Step 6: Commit**

```bash
git add resources/js/lib/status-tones.ts tests/Frontend/status-tones.test.mjs resources/js/locales/ar.json lang/ar.json
git commit -m "feat(status-tones): add off-plan and marketing statuses"
```

---

### Task 2: Off-Plan index — `resources/js/pages/real-estate/OffPlan.vue`

**Files:** Create the page.

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by later tasks (Task 3 is a separate page with its own props).

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
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
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import { ref } from 'vue';
import type { DataTableColumn } from '@/lib/data-table';

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
    status: string;
};

defineProps<{
    projects: {
        data: OffPlanProject[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    developers: { id: number; name: string }[];
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Off-Plan Projects', href: '/real-estate/off-plan' }],
    },
});

const { t } = useLocale();
const dialogOpen = ref(false);

const form = useForm({
    developer_id: null as number | null,
    cost_centre_id: '',
    code: '',
    name: '',
    emirate: '',
    location: '',
    completion_on: '',
    commission_rate: '',
});

function openCreate(): void {
    form.reset();
    dialogOpen.value = true;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        cost_centre_id: data.cost_centre_id === '' ? null : Number(data.cost_centre_id),
    })).post('/real-estate/off-plan/projects', {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

const columns: DataTableColumn<OffPlanProject>[] = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Project' },
    { key: 'developer_name', label: 'Developer' },
    { key: 'emirate', label: 'Emirate' },
    { key: 'completion_on', label: 'Completion' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <Head :title="t('Off-Plan Projects')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Real Estate" title="Off-Plan Projects">
            <template #actions>
                <Button v-if="canManage" @click="openCreate">{{ t('New project') }}</Button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="projects.data"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            empty-title="No off-plan projects yet."
        >
            <template #cell-code="{ row }">
                <Link
                    :href="`/real-estate/off-plan/${row.id}`"
                    class="text-accent-text font-medium hover:underline"
                    >{{ row.code }}</Link
                >
            </template>
            <template #cell-completion_on="{ row }">{{ row.completion_on ?? '—' }}</template>
            <template #cell-status="{ row }"><StatusDot :status="row.status" /></template>
        </DataTable>
        <Pagination :links="projects.links" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('New project') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Developer') }}</Label>
                        <Select
                            :model-value="form.developer_id ? String(form.developer_id) : ''"
                            @update:model-value="form.developer_id = Number($event)"
                        >
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="developer in developers"
                                    :key="developer.id"
                                    :value="String(developer.id)"
                                    >{{ developer.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.developer_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="project-cost-centre"
                            >{{ t('Cost centre ID') }}
                            <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                        >
                        <Input id="project-cost-centre" v-model="form.cost_centre_id" type="number" min="1" />
                        <InputError :message="form.errors.cost_centre_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-code">{{ t('Code') }}</Label>
                            <Input id="project-code" v-model="form.code" />
                            <InputError :message="form.errors.code" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-name">{{ t('Name') }}</Label>
                            <Input id="project-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-emirate">{{ t('Emirate') }}</Label>
                            <Input id="project-emirate" v-model="form.emirate" />
                            <InputError :message="form.errors.emirate" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-location"
                                >{{ t('Location') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="project-location" v-model="form.location" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-completion"
                                >{{ t('Completion date') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="project-completion" v-model="form.completion_on" type="date" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-commission"
                                >{{ t('Commission rate %') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="project-commission" v-model="form.commission_rate" type="number" min="0" max="100" step="0.01" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ t('Create project') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
```

- [ ] **Step 2: Add Arabic**, only missing keys, then sync:

```json
{
    "Off-Plan Projects": "المشاريع على الخارطة",
    "New project": "مشروع جديد",
    "No off-plan projects yet.": "لا توجد مشاريع على الخارطة بعد.",
    "Code": "الرمز",
    "Project": "المشروع",
    "Developer": "المطوّر",
    "Emirate": "الإمارة",
    "Completion": "الإنجاز",
    "Cost centre ID": "معرّف مركز التكلفة",
    "Name": "الاسم",
    "Location": "الموقع",
    "Completion date": "تاريخ الإنجاز",
    "Commission rate %": "نسبة العمولة %",
    "Create project": "إنشاء مشروع",
    "Cancel": "إلغاء"
}
```

Run: `docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'`

- [ ] **Step 3: Verify.** Format, typecheck, lint, build (with PHP 8.5-cli installed ad hoc in the node container for the Wayfinder build step, per Phase 3's pattern).

- [ ] **Step 4: Commit** `feat(offplan): projects index with create dialog`

---

### Task 3: Off-Plan project detail — `resources/js/pages/real-estate/OffPlanProject.vue`

**Files:** Create the page. Reuses `RecordPicker` (`type="lead"`).

**Interfaces:**
- Consumes: nothing new from Task 1/2 besides `StatusDot`.
- Produces: nothing consumed later.

- [ ] **Step 1: Write the failing tests first (pure logic, extracted to a lib file)**

Create `resources/js/lib/offplan.ts`'s test first: `tests/Frontend/offplan.test.mjs`

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    allowedDealTransitions,
    milestonePercentageValid,
} from '../../resources/js/lib/offplan.ts';

await test('enquiry deals can move to reserved or cancelled, nothing else', () => {
    assert.deepEqual(allowedDealTransitions('enquiry'), ['reserved', 'cancelled']);
});

await test('reserved deals can move to contracted or cancelled', () => {
    assert.deepEqual(allowedDealTransitions('reserved'), ['contracted', 'cancelled']);
});

await test('contracted and cancelled deals have no further transitions', () => {
    assert.deepEqual(allowedDealTransitions('contracted'), []);
    assert.deepEqual(allowedDealTransitions('cancelled'), []);
});

await test('milestone percentage is valid up to the server tolerance of 100.001', () => {
    assert.equal(milestonePercentageValid(60, 40), true);
    assert.equal(milestonePercentageValid(60, 40.001), true);
    assert.equal(milestonePercentageValid(60, 40.01), false);
});

await test('milestone percentage handles an empty existing total', () => {
    assert.equal(milestonePercentageValid(0, 100), true);
    assert.equal(milestonePercentageValid(0, 100.01), false);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/offplan.test.mjs`
Expected: FAIL — `resources/js/lib/offplan.ts` does not exist yet.

- [ ] **Step 3: Create `resources/js/lib/offplan.ts`**

```ts
export type DealStatus = 'enquiry' | 'reserved' | 'contracted' | 'cancelled';

export function allowedDealTransitions(status: DealStatus): DealStatus[] {
    switch (status) {
        case 'enquiry':
            return ['reserved', 'cancelled'];
        case 'reserved':
            return ['contracted', 'cancelled'];
        default:
            return [];
    }
}

export function milestonePercentageValid(existingTotal: number, newPercentage: number): boolean {
    return existingTotal + newPercentage <= 100.001;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/offplan.test.mjs`
Expected: PASS, 5/5.

- [ ] **Step 5: Create the page**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPicker from '@/components/RecordPicker.vue';
import StatusDot from '@/components/StatusDot.vue';
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
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { useLocale } from '@/composables/useLocale';
import { allowedDealTransitions, milestonePercentageValid } from '@/lib/offplan';
import type { DealStatus } from '@/lib/offplan';
import type { DataTableColumn } from '@/lib/data-table';

type OffPlanProject = {
    id: number;
    developer_id: number;
    developer_name?: string;
    code: string;
    name: string;
    emirate: string;
    status: string;
    commission_rate: string;
};
type OffPlanUnit = {
    id: number;
    number: string;
    type: string | null;
    area_sqft: string | null;
    price_aed: string;
    status: 'available' | 'reserved' | 'sold';
};
type OffPlanMilestone = { id: number; sequence: number; label: string; percentage: string; due_on: string | null };
type OffPlanDeal = {
    id: number;
    unit_id: number;
    lead_id: number;
    reference: string;
    price_aed: string;
    status: DealStatus;
    contracted_on: string | null;
    notes: string | null;
};

const props = defineProps<{
    project: OffPlanProject;
    units: { data: OffPlanUnit[]; links: { label: string; url: string | null; active: boolean }[] };
    milestones: OffPlanMilestone[];
    deals: OffPlanDeal[];
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Off-Plan Projects', href: '/real-estate/off-plan' },
            { title: 'Project', href: '#' },
        ],
    },
});

const { t } = useLocale();

const unitColumns: DataTableColumn<OffPlanUnit>[] = [
    { key: 'number', label: 'Unit' },
    { key: 'type', label: 'Type' },
    { key: 'area_sqft', label: 'Area (sqft)' },
    { key: 'price_aed', label: 'Price' },
    { key: 'status', label: 'Status' },
];
const unitDialogOpen = ref(false);
const unitForm = useForm({ number: '', type: '', area_sqft: '', price_aed: '' });
function openUnit(): void {
    unitForm.reset();
    unitDialogOpen.value = true;
}
function submitUnit(): void {
    unitForm.post(`/real-estate/off-plan/${props.project.id}/units`, {
        preserveScroll: true,
        onSuccess: () => (unitDialogOpen.value = false),
    });
}

const milestoneTotal = computed(() =>
    props.milestones.reduce((sum, milestone) => sum + Number(milestone.percentage), 0),
);
const milestoneDialogOpen = ref(false);
const milestoneForm = useForm({ sequence: '', label: '', percentage: '', due_on: '' });
const milestoneError = ref<string | null>(null);
function openMilestone(): void {
    milestoneForm.reset();
    milestoneError.value = null;
    milestoneDialogOpen.value = true;
}
function submitMilestone(): void {
    if (!milestonePercentageValid(milestoneTotal.value, Number(milestoneForm.percentage))) {
        milestoneError.value = t('Payment milestone percentages cannot exceed 100% in total.');

        return;
    }
    milestoneError.value = null;
    milestoneForm.post(`/real-estate/off-plan/${props.project.id}/milestones`, {
        preserveScroll: true,
        onSuccess: () => (milestoneDialogOpen.value = false),
    });
}

const availableUnits = computed(() => props.units.data.filter((unit) => unit.status === 'available'));
const dealColumns: DataTableColumn<OffPlanDeal>[] = [
    { key: 'reference', label: 'Reference' },
    { key: 'unit_id', label: 'Unit' },
    { key: 'price_aed', label: 'Price' },
    { key: 'status', label: 'Status' },
    { key: 'id', label: '', align: 'end' },
];
const dealDialogOpen = ref(false);
const dealLeadId = ref<number | null>(null);
const dealForm = useForm({ unit_id: null as number | null, reference: '', price_aed: '', notes: '' });
function openDeal(): void {
    dealForm.reset();
    dealLeadId.value = null;
    dealDialogOpen.value = true;
}
function submitDeal(): void {
    dealForm
        .transform((data) => ({ ...data, lead_id: dealLeadId.value }))
        .post('/real-estate/off-plan/deals', {
            preserveScroll: true,
            onSuccess: () => (dealDialogOpen.value = false),
        });
}

function unitNumber(unitId: number): string {
    return props.units.data.find((unit) => unit.id === unitId)?.number ?? String(unitId);
}

const contractDialogDeal = ref<OffPlanDeal | null>(null);
const contractForm = useForm({ contracted_on: '' });
function transition(deal: OffPlanDeal, status: DealStatus): void {
    if (status === 'contracted') {
        contractForm.reset();
        contractDialogDeal.value = deal;

        return;
    }
    const question =
        status === 'cancelled'
            ? t('Cancel this deal?')
            : t('Reserve this unit for this deal?');
    if (confirm(question)) {
        useForm({ status, contracted_on: null }).post(`/real-estate/off-plan/deals/${deal.id}/status`, {
            preserveScroll: true,
        });
    }
}
function submitContract(): void {
    if (!contractDialogDeal.value) {
        return;
    }
    contractForm
        .transform((data) => ({ status: 'contracted', contracted_on: data.contracted_on }))
        .post(`/real-estate/off-plan/deals/${contractDialogDeal.value.id}/status`, {
            preserveScroll: true,
            onSuccess: () => (contractDialogDeal.value = null),
        });
}
</script>

<template>
    <Head :title="project.name" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Off-Plan" :title="project.name" :translate="false">
            <template #actions>
                <StatusDot :status="project.status" />
            </template>
        </PageHeader>
        <p class="text-muted-foreground -mt-2 text-sm">
            {{ project.code }} · {{ project.emirate }} · {{ t('Commission') }} {{ project.commission_rate }}%
        </p>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">{{ t('Units') }}</h2>
                <Button v-if="canManage" size="sm" @click="openUnit">{{ t('Add unit') }}</Button>
            </div>
            <DataTable
                :columns="unitColumns"
                :rows="units.data"
                :row-key="(row) => row.id"
                :row-label="(row) => row.number"
                empty-title="No units yet."
            >
                <template #cell-area_sqft="{ row }">{{ row.area_sqft ?? '—' }}</template>
                <template #cell-price_aed="{ row }"><Money :value="row.price_aed" /></template>
                <template #cell-status="{ row }"><StatusDot :status="row.status" /></template>
            </DataTable>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">{{ t('Payment milestones') }}</h2>
                <Button v-if="canManage" size="sm" @click="openMilestone">{{ t('Add milestone') }}</Button>
            </div>
            <p class="text-muted-foreground text-xs">{{ t('Allocated') }}: {{ milestoneTotal }}%</p>
            <div class="bg-card shadow-panel overflow-hidden rounded-lg border">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-label border-b">
                            <th class="py-2.5 ps-5 pe-3 text-start font-medium">{{ t('Sequence') }}</th>
                            <th class="px-3 text-start font-medium">{{ t('Label') }}</th>
                            <th class="px-3 text-end font-medium">{{ t('Percentage') }}</th>
                            <th class="px-3 pe-5 text-start font-medium">{{ t('Due') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="milestone in milestones" :key="milestone.id" class="border-b last:border-b-0">
                            <td class="py-3 ps-5 pe-3">{{ milestone.sequence }}</td>
                            <td class="px-3">{{ milestone.label }}</td>
                            <td class="px-3 text-end tabular-nums">{{ milestone.percentage }}%</td>
                            <td class="px-3 pe-5">{{ milestone.due_on ?? '—' }}</td>
                        </tr>
                        <tr v-if="milestones.length === 0">
                            <td colspan="4" class="text-muted-foreground p-8 text-center text-sm">
                                {{ t('No payment milestones yet.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">{{ t('Deals') }}</h2>
                <Button v-if="canManage" size="sm" @click="openDeal">{{ t('New deal') }}</Button>
            </div>
            <DataTable
                :columns="dealColumns"
                :rows="deals"
                :row-key="(row) => row.id"
                :row-label="(row) => row.reference"
                empty-title="No deals yet."
            >
                <template #cell-unit_id="{ row }">{{ unitNumber(row.unit_id) }}</template>
                <template #cell-price_aed="{ row }"><Money :value="row.price_aed" /></template>
                <template #cell-status="{ row }"><StatusDot :status="row.status" /></template>
                <template #cell-id="{ row }">
                    <div v-if="canManage" class="flex justify-end gap-2">
                        <Button
                            v-for="next in allowedDealTransitions(row.status)"
                            :key="next"
                            size="sm"
                            :variant="next === 'cancelled' ? 'destructive-outline' : 'outline'"
                            @click="transition(row, next)"
                            >{{ t(next === 'reserved' ? 'Reserve' : next === 'contracted' ? 'Contract' : 'Cancel') }}</Button
                        >
                    </div>
                </template>
            </DataTable>
        </section>

        <Dialog v-model:open="unitDialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('Add unit') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitUnit">
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit-number">{{ t('Unit number') }}</Label>
                        <Input id="unit-number" v-model="unitForm.number" />
                        <InputError :message="unitForm.errors.number" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit-type"
                            >{{ t('Type') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                        >
                        <Input id="unit-type" v-model="unitForm.type" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit-area"
                            >{{ t('Area (sqft)') }}
                            <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                        >
                        <Input id="unit-area" v-model="unitForm.area_sqft" type="number" min="0" step="0.01" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit-price">{{ t('Price (AED)') }}</Label>
                        <Input id="unit-price" v-model="unitForm.price_aed" type="number" min="0.01" step="0.01" />
                        <InputError :message="unitForm.errors.price_aed" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="unitDialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="unitForm.processing">{{ t('Add') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="milestoneDialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('Add milestone') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitMilestone">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="milestone-sequence">{{ t('Sequence') }}</Label>
                            <Input id="milestone-sequence" v-model="milestoneForm.sequence" type="number" min="1" max="999" />
                            <InputError :message="milestoneForm.errors.sequence" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="milestone-percentage">{{ t('Percentage') }}</Label>
                            <Input id="milestone-percentage" v-model="milestoneForm.percentage" type="number" min="0.01" max="100" step="0.01" />
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="milestone-label">{{ t('Label') }}</Label>
                        <Input id="milestone-label" v-model="milestoneForm.label" />
                        <InputError :message="milestoneForm.errors.label" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="milestone-due"
                            >{{ t('Due date') }}
                            <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                        >
                        <Input id="milestone-due" v-model="milestoneForm.due_on" type="date" />
                    </div>
                    <p v-if="milestoneError" class="text-destructive text-xs">{{ milestoneError }}</p>
                    <InputError :message="milestoneForm.errors.percentage" />
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="milestoneDialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="milestoneForm.processing">{{ t('Add') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dealDialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('New deal') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitDeal">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Unit') }}</Label>
                        <Select
                            :model-value="dealForm.unit_id ? String(dealForm.unit_id) : ''"
                            @update:model-value="dealForm.unit_id = Number($event)"
                        >
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="unit in availableUnits" :key="unit.id" :value="String(unit.id)">{{
                                    unit.number
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="dealForm.errors.unit_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Lead') }}</Label>
                        <RecordPicker type="lead" v-model="dealLeadId" label="Search leads…" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="deal-reference">{{ t('Reference') }}</Label>
                        <Input id="deal-reference" v-model="dealForm.reference" />
                        <InputError :message="dealForm.errors.reference" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="deal-price"
                            >{{ t('Price (AED)') }}
                            <span class="text-muted-foreground font-normal">({{ t('optional, defaults to unit price') }})</span></Label
                        >
                        <Input id="deal-price" v-model="dealForm.price_aed" type="number" min="0.01" step="0.01" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="deal-notes"
                            >{{ t('Notes') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                        >
                        <Textarea id="deal-notes" v-model="dealForm.notes" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dealDialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="dealForm.processing">{{ t('Create deal') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="contractDialogDeal !== null" @update:open="(value) => !value && (contractDialogDeal = null)">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('Contract this deal') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitContract">
                    <div class="flex flex-col gap-1.5">
                        <Label for="contract-date">{{ t('Contracted on') }}</Label>
                        <Input id="contract-date" v-model="contractForm.contracted_on" type="date" />
                        <InputError :message="contractForm.errors.contracted_on" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="contractDialogDeal = null">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="contractForm.processing">{{ t('Confirm') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
```

- [ ] **Step 6: Add Arabic**, only missing keys, then sync:

```json
{
    "Units": "الوحدات",
    "Add unit": "إضافة وحدة",
    "No units yet.": "لا توجد وحدات بعد.",
    "Type": "النوع",
    "Area (sqft)": "المساحة (قدم مربع)",
    "Payment milestones": "دفعات السداد",
    "Add milestone": "إضافة دفعة",
    "Allocated": "المخصص",
    "Sequence": "التسلسل",
    "Label": "الوصف",
    "Percentage": "النسبة",
    "Due": "الاستحقاق",
    "No payment milestones yet.": "لا توجد دفعات سداد بعد.",
    "Deals": "الصفقات",
    "New deal": "صفقة جديدة",
    "No deals yet.": "لا توجد صفقات بعد.",
    "Reserve": "حجز",
    "Contract": "التعاقد",
    "Payment milestone percentages cannot exceed 100% in total.": "لا يجوز أن يتجاوز مجموع نسب دفعات السداد 100%.",
    "Unit number": "رقم الوحدة",
    "Price (AED)": "السعر (د.إ)",
    "Add": "إضافة",
    "Lead": "عميل محتمل",
    "Reference": "المرجع",
    "optional, defaults to unit price": "اختياري، يُستخدم سعر الوحدة افتراضيًا",
    "Notes": "ملاحظات",
    "Create deal": "إنشاء صفقة",
    "Contract this deal": "التعاقد على هذه الصفقة",
    "Contracted on": "تاريخ التعاقد",
    "Confirm": "تأكيد",
    "Cancel this deal?": "إلغاء هذه الصفقة؟",
    "Reserve this unit for this deal?": "حجز هذه الوحدة لهذه الصفقة؟",
    "Unit": "الوحدة",
    "Search leads…": "ابحث عن عملاء محتملين…"
}
```

Run: `docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'`

- [ ] **Step 7: Verify.** Format, typecheck, lint, build. Confirm `docker exec -e DB_DATABASE=testing_claude z1erp-web sh -lc 'cd /workspace && php artisan test --filter=OffPlanTest'` passes.

- [ ] **Step 8: Commit** `feat(offplan): project detail with units, milestones and deal pipeline`

---

### Task 4: Marketing Portal — `resources/js/pages/marketing/Portal.vue`

**Files:** Create the page. Reuses `RecordPicker` (`type="lead"`).

**Interfaces:**
- Consumes: nothing from Tasks 1–3.
- Produces: nothing consumed later.

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type Campaign = {
    id: number;
    name: string;
    type: string;
    budget_aed: string;
    starts_on: string | null;
    ends_on: string | null;
    status: string;
};
type Publication = {
    id: number;
    listing_id: number;
    campaign_id: number | null;
    portal: 'bayut' | 'property_finder' | 'dubizzle';
    status: string;
    validated_at: string;
};

const props = defineProps<{
    campaigns: { data: Campaign[]; links: { label: string; url: string | null; active: boolean }[] };
    publications: Publication[];
    canManage: boolean;
    providerSelected: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Marketing & Portals', href: '/marketing/portals' }] },
});

const { t } = useLocale();
const portalLabel = (portal: string) =>
    ({ bayut: 'Bayut', property_finder: 'Property Finder', dubizzle: 'Dubizzle' })[portal] ?? portal;

const campaignColumns: DataTableColumn<Campaign>[] = [
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Type' },
    { key: 'budget_aed', label: 'Budget' },
    { key: 'status', label: 'Status' },
];
const campaignDialogOpen = ref(false);
const campaignForm = useForm({
    name: '',
    type: '',
    vendor_id: '',
    cost_centre_id: '',
    budget_aed: '',
    starts_on: '',
    ends_on: '',
});
function openCampaign(): void {
    campaignForm.reset();
    campaignDialogOpen.value = true;
}
function submitCampaign(): void {
    campaignForm
        .transform((data) => ({
            ...data,
            vendor_id: data.vendor_id === '' ? null : Number(data.vendor_id),
            cost_centre_id: data.cost_centre_id === '' ? null : Number(data.cost_centre_id),
        }))
        .post('/marketing/campaigns', {
            preserveScroll: true,
            onSuccess: () => (campaignDialogOpen.value = false),
        });
}

const publicationColumns: DataTableColumn<Publication>[] = [
    { key: 'listing_id', label: 'Listing' },
    { key: 'portal', label: 'Portal' },
    { key: 'status', label: 'Status' },
    { key: 'validated_at', label: 'Validated' },
    { key: 'id', label: '', align: 'end' },
];
const publishDialogOpen = ref(false);
const publishForm = useForm({ listing_id: '', campaign_id: '', portal: 'bayut' as Publication['portal'] });
function openPublish(): void {
    publishForm.reset();
    publishDialogOpen.value = true;
}
function submitPublish(): void {
    publishForm
        .transform((data) => ({
            ...data,
            listing_id: Number(data.listing_id),
            campaign_id: data.campaign_id === '' ? null : Number(data.campaign_id),
        }))
        .post('/marketing/publications', {
            preserveScroll: true,
            onSuccess: () => (publishDialogOpen.value = false),
        });
}

const enquiryPublication = ref<Publication | null>(null);
const enquiryLeadId = ref<number | null>(null);
const enquiryForm = useForm({ source_reference: '', received_at: '' });
function openEnquiry(publication: Publication): void {
    enquiryForm.reset();
    enquiryLeadId.value = null;
    enquiryPublication.value = publication;
}
function submitEnquiry(): void {
    if (!enquiryPublication.value) {
        return;
    }
    enquiryForm
        .transform((data) => ({ ...data, lead_id: enquiryLeadId.value }))
        .post(`/marketing/publications/${enquiryPublication.value.id}/enquiries`, {
            preserveScroll: true,
            onSuccess: () => (enquiryPublication.value = null),
        });
}
</script>

<template>
    <Head :title="t('Marketing & Portals')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Marketing" title="Marketing & Portals" />

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">{{ t('Campaigns') }}</h2>
                <Button v-if="canManage" size="sm" @click="openCampaign">{{ t('New campaign') }}</Button>
            </div>
            <DataTable
                :columns="campaignColumns"
                :rows="campaigns.data"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                empty-title="No campaigns yet."
            >
                <template #cell-budget_aed="{ row }"><Money :value="row.budget_aed" /></template>
                <template #cell-status="{ row }"><StatusDot :status="row.status" /></template>
            </DataTable>
            <Pagination :links="campaigns.links" />
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">{{ t('Publications') }}</h2>
                <Button v-if="canManage" size="sm" @click="openPublish">{{ t('Publish listing') }}</Button>
            </div>
            <DataTable
                :columns="publicationColumns"
                :rows="publications"
                :row-key="(row) => row.id"
                empty-title="No publications yet."
            >
                <template #cell-listing_id="{ row }">#{{ row.listing_id }}</template>
                <template #cell-portal="{ row }"><Badge variant="outline">{{ portalLabel(row.portal) }}</Badge></template>
                <template #cell-status="{ row }"><StatusDot :status="row.status" /></template>
                <template #cell-validated_at="{ row }"><DateText :value="row.validated_at" /></template>
                <template #cell-id="{ row }">
                    <Button v-if="canManage" size="sm" variant="outline" @click="openEnquiry(row)">{{
                        t('Record enquiry')
                    }}</Button>
                </template>
            </DataTable>
        </section>

        <Dialog v-model:open="campaignDialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('New campaign') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitCampaign">
                    <div class="flex flex-col gap-1.5">
                        <Label for="campaign-name">{{ t('Name') }}</Label>
                        <Input id="campaign-name" v-model="campaignForm.name" />
                        <InputError :message="campaignForm.errors.name" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="campaign-type">{{ t('Type') }}</Label>
                        <Input id="campaign-type" v-model="campaignForm.type" />
                        <InputError :message="campaignForm.errors.type" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="campaign-vendor"
                                >{{ t('Vendor ID') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="campaign-vendor" v-model="campaignForm.vendor_id" type="number" min="1" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="campaign-cost-centre"
                                >{{ t('Cost centre ID') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="campaign-cost-centre" v-model="campaignForm.cost_centre_id" type="number" min="1" />
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="campaign-budget"
                            >{{ t('Budget (AED)') }}
                            <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                        >
                        <Input id="campaign-budget" v-model="campaignForm.budget_aed" type="number" min="0" step="0.01" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="campaign-starts"
                                >{{ t('Starts') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="campaign-starts" v-model="campaignForm.starts_on" type="date" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="campaign-ends"
                                >{{ t('Ends') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="campaign-ends" v-model="campaignForm.ends_on" type="date" />
                        </div>
                    </div>
                    <InputError :message="campaignForm.errors.ends_on" />
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="campaignDialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="campaignForm.processing">{{ t('Create') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="publishDialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('Publish listing') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitPublish">
                    <div class="flex flex-col gap-1.5">
                        <Label for="publish-listing">{{ t('Listing ID') }}</Label>
                        <Input id="publish-listing" v-model="publishForm.listing_id" type="number" min="1" />
                        <InputError :message="publishForm.errors.listing_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Portal') }}</Label>
                        <Select v-model="publishForm.portal">
                            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="bayut">{{ t('Bayut') }}</SelectItem>
                                <SelectItem value="property_finder">{{ t('Property Finder') }}</SelectItem>
                                <SelectItem value="dubizzle">{{ t('Dubizzle') }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="publishForm.errors.portal" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Campaign') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label>
                        <Select
                            :model-value="publishForm.campaign_id"
                            @update:model-value="(value) => (publishForm.campaign_id = (value as string) ?? '')"
                        >
                            <SelectTrigger class="w-full"><SelectValue :placeholder="t('None')" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">{{ t('None') }}</SelectItem>
                                <SelectItem v-for="campaign in campaigns.data" :key="campaign.id" :value="String(campaign.id)">{{
                                    campaign.name
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="publishDialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="publishForm.processing">{{ t('Publish') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="enquiryPublication !== null" @update:open="(value) => !value && (enquiryPublication = null)">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('Record enquiry') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitEnquiry">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Lead') }}</Label>
                        <RecordPicker type="lead" v-model="enquiryLeadId" label="Search leads…" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="enquiry-reference">{{ t('Source reference') }}</Label>
                        <Input id="enquiry-reference" v-model="enquiryForm.source_reference" />
                        <InputError :message="enquiryForm.errors.source_reference" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="enquiry-received">{{ t('Received at') }}</Label>
                        <Input id="enquiry-received" v-model="enquiryForm.received_at" type="datetime-local" />
                        <InputError :message="enquiryForm.errors.received_at" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="enquiryPublication = null">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="enquiryForm.processing">{{ t('Save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
```

- [ ] **Step 2: Add Arabic**, only missing keys, then sync:

```json
{
    "Marketing & Portals": "التسويق والبوابات",
    "Campaigns": "الحملات",
    "New campaign": "حملة جديدة",
    "No campaigns yet.": "لا توجد حملات بعد.",
    "Budget": "الميزانية",
    "Publications": "المنشورات",
    "Publish listing": "نشر عرض",
    "No publications yet.": "لا توجد منشورات بعد.",
    "Listing": "العرض",
    "Portal": "البوابة",
    "Validated": "تم التحقق",
    "Record enquiry": "تسجيل استفسار",
    "Vendor ID": "معرّف المورد",
    "Budget (AED)": "الميزانية (د.إ)",
    "Starts": "البداية",
    "Ends": "النهاية",
    "Listing ID": "معرّف العرض",
    "Bayut": "بيوت",
    "Property Finder": "بروبرتي فايندر",
    "Dubizzle": "دوبيزل",
    "Campaign": "الحملة",
    "None": "بلا",
    "Publish": "نشر",
    "Source reference": "مرجع المصدر",
    "Received at": "وقت الاستلام",
    "Save": "حفظ"
}
```

Run: `docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'`

- [ ] **Step 3: Verify.** Format, typecheck, lint, build. Confirm `docker exec -e DB_DATABASE=testing_claude z1erp-web sh -lc 'cd /workspace && php artisan test --filter=PortalMarketingTest'` passes.

- [ ] **Step 4: Commit** `feat(marketing): portal campaigns, publications and enquiry recording`

---

### Task 5: Subscriptions — `resources/js/pages/marketing/Subscriptions.vue`

**Files:** Create the page.

**Interfaces:** Consumes nothing from earlier tasks besides shared components.

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

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
    status: string;
};

defineProps<{
    subscriptions: { data: Subscription[]; links: { label: string; url: string | null; active: boolean }[] };
    bills: { id: number; subscription_id: number; vendor_bill_id: number; period_from: string; period_to: string }[];
    canManage: boolean;
    canLinkBill: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Portal Subscriptions', href: '/marketing/subscriptions' }] },
});

const { t } = useLocale();
const portalLabel = (portal: string) =>
    ({ bayut: 'Bayut', property_finder: 'Property Finder', dubizzle: 'Dubizzle' })[portal] ?? portal;

const columns: DataTableColumn<Subscription>[] = [
    { key: 'portal', label: 'Portal' },
    { key: 'package', label: 'Package' },
    { key: 'credits_used', label: 'Credits' },
    { key: 'billing_cycle', label: 'Billing' },
    { key: 'renews_on', label: 'Renews' },
    { key: 'status', label: 'Status' },
    { key: 'id', label: '', align: 'end' },
];

const dialogOpen = ref(false);
const form = useForm({
    portal: 'bayut' as Subscription['portal'],
    package: '',
    company_id: '',
    branch_id: '',
    cost_centre_id: '',
    contract_value_aed: '',
    billing_cycle: 'monthly' as Subscription['billing_cycle'],
    credits_total: '',
    starts_on: '',
    renews_on: '',
});
function openCreate(): void {
    form.reset();
    dialogOpen.value = true;
}
function submit(): void {
    form
        .transform((data) => ({
            ...data,
            company_id: data.company_id === '' ? null : Number(data.company_id),
            branch_id: data.branch_id === '' ? null : Number(data.branch_id),
            cost_centre_id: data.cost_centre_id === '' ? null : Number(data.cost_centre_id),
            credits_total: data.credits_total === '' ? null : Number(data.credits_total),
        }))
        .post('/marketing/subscriptions', {
            preserveScroll: true,
            onSuccess: () => (dialogOpen.value = false),
        });
}

const linkingSubscription = ref<Subscription | null>(null);
const linkForm = useForm({ vendor_bill_id: '', period_from: '', period_to: '' });
function openLink(subscription: Subscription): void {
    linkForm.reset();
    linkingSubscription.value = subscription;
}
function submitLink(): void {
    if (!linkingSubscription.value) {
        return;
    }
    linkForm
        .transform((data) => ({ ...data, vendor_bill_id: Number(data.vendor_bill_id) }))
        .post(`/marketing/subscriptions/${linkingSubscription.value.id}/bills`, {
            preserveScroll: true,
            onSuccess: () => (linkingSubscription.value = null),
        });
}
</script>

<template>
    <Head :title="t('Portal Subscriptions')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Marketing" title="Portal Subscriptions">
            <template #actions>
                <Button v-if="canManage" @click="openCreate">{{ t('New subscription') }}</Button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="subscriptions.data"
            :row-key="(row) => row.id"
            :row-label="(row) => row.package"
            empty-title="No subscriptions yet."
        >
            <template #cell-portal="{ row }"><Badge variant="outline">{{ portalLabel(row.portal) }}</Badge></template>
            <template #cell-credits_used="{ row }">{{ row.credits_used }} / {{ row.credits_total }}</template>
            <template #cell-billing_cycle="{ row }">{{ t(row.billing_cycle) }}</template>
            <template #cell-renews_on="{ row }">{{ row.renews_on ?? '—' }}</template>
            <template #cell-status="{ row }"><StatusDot :status="row.status" /></template>
            <template #cell-id="{ row }">
                <Button v-if="canLinkBill" size="sm" variant="outline" @click="openLink(row)">{{ t('Link bill') }}</Button>
            </template>
        </DataTable>
        <Pagination :links="subscriptions.links" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('New subscription') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label>{{ t('Portal') }}</Label>
                            <Select v-model="form.portal">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="bayut">{{ t('Bayut') }}</SelectItem>
                                    <SelectItem value="property_finder">{{ t('Property Finder') }}</SelectItem>
                                    <SelectItem value="dubizzle">{{ t('Dubizzle') }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-package">{{ t('Package') }}</Label>
                            <Input id="sub-package" v-model="form.package" />
                            <InputError :message="form.errors.package" />
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-company"
                                >{{ t('Company ID') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="sub-company" v-model="form.company_id" type="number" min="1" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-branch"
                                >{{ t('Branch ID') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="sub-branch" v-model="form.branch_id" type="number" min="1" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-cost-centre"
                                >{{ t('Cost centre ID') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="sub-cost-centre" v-model="form.cost_centre_id" type="number" min="1" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-value">{{ t('Contract value (AED)') }}</Label>
                            <Input id="sub-value" v-model="form.contract_value_aed" type="number" min="0" step="0.01" />
                            <InputError :message="form.errors.contract_value_aed" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label>{{ t('Billing cycle') }}</Label>
                            <Select v-model="form.billing_cycle">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="monthly">{{ t('monthly') }}</SelectItem>
                                    <SelectItem value="quarterly">{{ t('quarterly') }}</SelectItem>
                                    <SelectItem value="annual">{{ t('annual') }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="sub-credits"
                            >{{ t('Credits total') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                        >
                        <Input id="sub-credits" v-model="form.credits_total" type="number" min="0" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-starts">{{ t('Starts on') }}</Label>
                            <Input id="sub-starts" v-model="form.starts_on" type="date" />
                            <InputError :message="form.errors.starts_on" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-renews"
                                >{{ t('Renews on') }} <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="sub-renews" v-model="form.renews_on" type="date" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ t('Create') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="linkingSubscription !== null" @update:open="(value) => !value && (linkingSubscription = null)">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('Link vendor bill') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submitLink">
                    <div class="flex flex-col gap-1.5">
                        <Label for="link-bill">{{ t('Vendor bill ID') }}</Label>
                        <Input id="link-bill" v-model="linkForm.vendor_bill_id" type="number" min="1" />
                        <InputError :message="linkForm.errors.vendor_bill_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="link-from">{{ t('Period from') }}</Label>
                            <Input id="link-from" v-model="linkForm.period_from" type="date" />
                            <InputError :message="linkForm.errors.period_from" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="link-to">{{ t('Period to') }}</Label>
                            <Input id="link-to" v-model="linkForm.period_to" type="date" />
                            <InputError :message="linkForm.errors.period_to" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="linkingSubscription = null">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="linkForm.processing">{{ t('Link') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
```

- [ ] **Step 2: Add Arabic**, only missing keys, then sync:

```json
{
    "Portal Subscriptions": "اشتراكات البوابات",
    "New subscription": "اشتراك جديد",
    "No subscriptions yet.": "لا توجد اشتراكات بعد.",
    "Package": "الباقة",
    "Credits": "الأرصدة",
    "Billing": "الفوترة",
    "Renews": "التجديد",
    "Link bill": "ربط فاتورة",
    "Company ID": "معرّف الشركة",
    "Branch ID": "معرّف الفرع",
    "Contract value (AED)": "قيمة العقد (د.إ)",
    "Billing cycle": "دورة الفوترة",
    "monthly": "شهري",
    "quarterly": "ربع سنوي",
    "annual": "سنوي",
    "Credits total": "إجمالي الأرصدة",
    "Starts on": "تاريخ البدء",
    "Renews on": "تاريخ التجديد",
    "Link vendor bill": "ربط فاتورة مورد",
    "Vendor bill ID": "معرّف فاتورة المورد",
    "Period from": "الفترة من",
    "Period to": "الفترة إلى",
    "Link": "ربط"
}
```

Run: `docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'`

- [ ] **Step 3: Verify.** Format, typecheck, lint, build.

- [ ] **Step 4: Commit** `feat(marketing): portal subscriptions with bill linking`

---

### Task 6: Costing — `resources/js/pages/marketing/Costing.vue`

**Files:** Create the page.

**Interfaces:** Consumes nothing from earlier tasks.

- [ ] **Step 1: Write the failing test for the spend-source-toggle logic**

Create `tests/Frontend/costing.test.mjs`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { clearBillIdIfEstimate } from '../../resources/js/lib/costing.ts';

await test('switching to estimate clears a previously entered vendor bill id', () => {
    assert.equal(clearBillIdIfEstimate('estimate', '42'), '');
});

await test('switching to bill_linked keeps whatever bill id was entered', () => {
    assert.equal(clearBillIdIfEstimate('bill_linked', '42'), '42');
});

await test('estimate with no bill id stays empty', () => {
    assert.equal(clearBillIdIfEstimate('estimate', ''), '');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/costing.test.mjs`
Expected: FAIL — `resources/js/lib/costing.ts` does not exist.

- [ ] **Step 3: Create `resources/js/lib/costing.ts`**

```ts
export type SpendSource = 'estimate' | 'bill_linked';

export function clearBillIdIfEstimate(source: SpendSource, currentBillId: string): string {
    return source === 'estimate' ? '' : currentBillId;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/costing.test.mjs`
Expected: PASS, 3/3.

- [ ] **Step 5: Create the page**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { useLocale } from '@/composables/useLocale';
import { clearBillIdIfEstimate } from '@/lib/costing';
import type { SpendSource } from '@/lib/costing';
import type { DataTableColumn } from '@/lib/data-table';

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
    channel: 'bayut' | 'property_finder' | 'dubizzle' | 'other';
    source: SpendSource;
    amount_aed: string;
    incurred_on: string;
    reason: string;
};

defineProps<{
    portals: PortalCost[];
    spend: { data: ListingSpend[]; links: { label: string; url: string | null; active: boolean }[] };
    canManage: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Listing Costing', href: '/marketing/costing' }] },
});

const { t } = useLocale();
const portalLabel = (portal: string) =>
    ({ bayut: 'Bayut', property_finder: 'Property Finder', dubizzle: 'Dubizzle' })[portal] ?? portal;

const spendColumns: DataTableColumn<ListingSpend>[] = [
    { key: 'listing_id', label: 'Listing' },
    { key: 'channel', label: 'Channel' },
    { key: 'source', label: 'Source' },
    { key: 'amount_aed', label: 'Amount' },
    { key: 'incurred_on', label: 'Date' },
];

const dialogOpen = ref(false);
const form = useForm({
    listing_id: '',
    publication_id: '',
    campaign_id: '',
    vendor_bill_id: '',
    channel: 'bayut' as ListingSpend['channel'],
    source: 'estimate' as SpendSource,
    amount_aed: '',
    incurred_on: '',
    reason: '',
});
watch(
    () => form.source,
    (source) => {
        form.vendor_bill_id = clearBillIdIfEstimate(source, form.vendor_bill_id);
    },
);
function openCreate(): void {
    form.reset();
    dialogOpen.value = true;
}
function submit(): void {
    form
        .transform((data) => ({
            ...data,
            listing_id: Number(data.listing_id),
            publication_id: data.publication_id === '' ? null : Number(data.publication_id),
            campaign_id: data.campaign_id === '' ? null : Number(data.campaign_id),
            vendor_bill_id: data.vendor_bill_id === '' ? null : Number(data.vendor_bill_id),
        }))
        .post('/marketing/costing', {
            preserveScroll: true,
            onSuccess: () => (dialogOpen.value = false),
        });
}
</script>

<template>
    <Head :title="t('Listing Costing')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Marketing" title="Listing Costing" />

        <div class="grid gap-4 md:grid-cols-3">
            <div v-for="portal in portals" :key="portal.portal" class="bg-card shadow-panel rounded-lg border p-5">
                <p class="font-display text-lg font-medium">{{ portalLabel(portal.portal) }}</p>
                <dl class="mt-3 flex flex-col gap-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('Local-validated listings') }}</dt>
                        <dd>{{ portal.local_validated_listings }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('Leads') }}</dt>
                        <dd>{{ portal.total_leads }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('Actual spend') }}</dt>
                        <dd><Money :value="portal.actual_spend_aed" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('Estimated spend') }}</dt>
                        <dd><Money :value="portal.estimated_spend_aed" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('Cost per listing') }}</dt>
                        <dd>
                            <Money v-if="portal.cost_per_listing !== null" :value="portal.cost_per_listing" />
                            <span v-else class="text-faint">—</span>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('Cost per lead') }}</dt>
                        <dd>
                            <Money v-if="portal.cost_per_lead !== null" :value="portal.cost_per_lead" />
                            <span v-else class="text-faint">—</span>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('Cost per deal') }}</dt>
                        <dd class="text-faint">—</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('ROI') }}</dt>
                        <dd class="text-faint">—</dd>
                    </div>
                </dl>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">{{ t('Spend log') }}</h2>
                <Button v-if="canManage" size="sm" @click="openCreate">{{ t('Record spend') }}</Button>
            </div>
            <DataTable
                :columns="spendColumns"
                :rows="spend.data"
                :row-key="(row) => row.id"
                empty-title="No spend recorded yet."
            >
                <template #cell-listing_id="{ row }">#{{ row.listing_id }}</template>
                <template #cell-channel="{ row }"><Badge variant="outline">{{ portalLabel(row.channel) }}</Badge></template>
                <template #cell-source="{ row }">{{ t(row.source === 'estimate' ? 'Estimate' : 'Bill-linked') }}</template>
                <template #cell-amount_aed="{ row }"><Money :value="row.amount_aed" /></template>
            </DataTable>
            <Pagination :links="spend.links" />
        </section>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ t('Record spend') }}</DialogTitle></DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label for="spend-listing">{{ t('Listing ID') }}</Label>
                        <Input id="spend-listing" v-model="form.listing_id" type="number" min="1" />
                        <InputError :message="form.errors.listing_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="spend-publication"
                                >{{ t('Publication ID') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="spend-publication" v-model="form.publication_id" type="number" min="1" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="spend-campaign"
                                >{{ t('Campaign ID') }}
                                <span class="text-muted-foreground font-normal">({{ t('optional') }})</span></Label
                            >
                            <Input id="spend-campaign" v-model="form.campaign_id" type="number" min="1" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label>{{ t('Channel') }}</Label>
                            <Select v-model="form.channel">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="bayut">{{ t('Bayut') }}</SelectItem>
                                    <SelectItem value="property_finder">{{ t('Property Finder') }}</SelectItem>
                                    <SelectItem value="dubizzle">{{ t('Dubizzle') }}</SelectItem>
                                    <SelectItem value="other">{{ t('Other') }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label>{{ t('Source') }}</Label>
                            <Select v-model="form.source">
                                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="estimate">{{ t('Estimate') }}</SelectItem>
                                    <SelectItem value="bill_linked">{{ t('Bill-linked') }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div v-if="form.source === 'bill_linked'" class="flex flex-col gap-1.5">
                        <Label for="spend-bill">{{ t('Vendor bill ID') }}</Label>
                        <Input id="spend-bill" v-model="form.vendor_bill_id" type="number" min="1" />
                        <InputError :message="form.errors.vendor_bill_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="spend-amount">{{ t('Amount (AED)') }}</Label>
                            <Input id="spend-amount" v-model="form.amount_aed" type="number" min="0.01" step="0.01" />
                            <InputError :message="form.errors.amount_aed" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="spend-date">{{ t('Incurred on') }}</Label>
                            <Input id="spend-date" v-model="form.incurred_on" type="date" />
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="spend-reason">{{ t('Reason') }}</Label>
                        <Textarea id="spend-reason" v-model="form.reason" />
                        <InputError :message="form.errors.reason" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">{{ t('Cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ t('Record') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
```

- [ ] **Step 6: Add Arabic**, only missing keys, then sync:

```json
{
    "Listing Costing": "تكلفة العروض",
    "Local-validated listings": "عروض تم التحقق منها محليًا",
    "Leads": "العملاء المحتملون",
    "Actual spend": "الإنفاق الفعلي",
    "Estimated spend": "الإنفاق التقديري",
    "Cost per listing": "التكلفة لكل عرض",
    "Cost per lead": "التكلفة لكل عميل محتمل",
    "Cost per deal": "التكلفة لكل صفقة",
    "ROI": "العائد على الاستثمار",
    "Spend log": "سجل الإنفاق",
    "Record spend": "تسجيل إنفاق",
    "No spend recorded yet.": "لا يوجد إنفاق مسجل بعد.",
    "Channel": "القناة",
    "Source": "المصدر",
    "Amount": "المبلغ",
    "Date": "التاريخ",
    "Estimate": "تقدير",
    "Bill-linked": "مرتبط بفاتورة",
    "Publication ID": "معرّف المنشور",
    "Campaign ID": "معرّف الحملة",
    "Other": "أخرى",
    "Amount (AED)": "المبلغ (د.إ)",
    "Incurred on": "تاريخ التكبد",
    "Reason": "السبب",
    "Record": "تسجيل"
}
```

Run: `docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'`

- [ ] **Step 7: Verify.** Format, typecheck, lint, build.

- [ ] **Step 8: Commit** `feat(marketing): listing costing report and spend log`

---

### Task 7: Navigation wiring and phase verification

**Files:**
- Modify: `resources/js/lib/navigation.ts`
- Modify: `tests/Frontend/navigation.test.mjs` if any soon-item example needs swapping (same pattern as Phase 3 Task 9)

- [ ] **Step 1: Update `navigation.ts`**

Replace:

```ts
soon('Off-Plan Projects', 'offPlan', 'listings'),
```

with:

```ts
{ label: 'Off-Plan Projects', href: '/real-estate/off-plan', icon: 'offPlan', ability: 'listings' },
```

Replace the Marketing group's five entries:

```ts
soon('Marketing & Portals', 'marketing', 'marketing'),
soon('Portal Listings', 'portalListings', 'marketing'),
soon('Portal Subscriptions', 'portalSubscriptions', 'marketing'),
soon('Portal Invoicing', 'portalInvoicing', 'marketing'),
soon('Listing Costing', 'costing', 'marketing'),
```

with:

```ts
{ label: 'Marketing & Portals', href: '/marketing/portals', icon: 'marketing' },
{ label: 'Portal Subscriptions', href: '/marketing/subscriptions', icon: 'portalSubscriptions' },
{ label: 'Listing Costing', href: '/marketing/costing', icon: 'costing' },
```

- [ ] **Step 2: Run the navigation test**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/navigation.test.mjs`
Expected: if any test used `'Off-Plan Projects'` or a marketing-group soon label as its "still soon" example, it now fails — swap that example to a different still-soon item (e.g. `'Secondary Market'`) the same way Phase 3 Task 9 did, then rerun to confirm 9/9 (or whatever the current count is) pass.

- [ ] **Step 3: Full phase verification**

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 npm run test:frontend
docker run --rm -v "$PWD":/workspace -w /workspace node:22 npm run typecheck
docker run --rm -v "$PWD":/workspace -w /workspace node:22 npm run lint
docker exec z1erp-web sh -lc 'cd /workspace && php artisan wayfinder:generate --with-form'
# The node:22 image has no php; install php8.5-cli ad hoc (matching the project's PHP 8.5) before building, in the same container invocation:
docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -lc '
apt-get update -qq >/tmp/apt.log 2>&1
apt-get install -y -qq ca-certificates wget >>/tmp/apt.log 2>&1
wget -qO /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
echo "deb https://packages.sury.org/php/ bookworm main" > /etc/apt/sources.list.d/php.list
apt-get update -qq >>/tmp/apt.log 2>&1
apt-get install -y -qq php8.5-cli php8.5-mbstring php8.5-xml php8.5-curl php8.5-mysql >>/tmp/apt.log 2>&1
update-alternatives --set php /usr/bin/php8.5 >/dev/null 2>&1
npm run build
'
docker exec -e DB_DATABASE=testing_claude z1erp-web sh -lc 'cd /workspace && php -d memory_limit=512M artisan test'
```

Expected: frontend tests all pass, typecheck/lint/build clean, PHP 350+ passed plus the same 5 known `testing_claude` guard failures — no new failures. Use `memory_limit=512M` from the start this time (Phase 3 discovered the CLI default of 128M is too low for `SignatureRequestsTest`, an unrelated pre-existing issue).

- [ ] **Step 4: Report to the owner**

Cover: what shipped (5 pages + nav wiring), that vendor-bill/cost-centre/vendor/publication/campaign/listing IDs are plain fields (no picker) until Codex's record-search endpoint and any needed list-props land, and ask about merging plus the next module-group priority (Finance & Operations or Compliance & reporting — Marketing & Off-Plan is now done).

---
