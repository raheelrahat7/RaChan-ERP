<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { readableOn } from '@/lib/crm-pipeline-board';
import {
    WORKFLOW_KINDS,
    activeStages,
    canCreateKind,
    estimateTotal,
    groupByStage,
    moveTargets,
    needsReason,
} from '@/lib/workflows';
import { uuid } from '@/lib/uuid';
import type {
    EstimateLine,
    WorkflowKind,
    WorkflowPipeline,
    WorkflowRecord,
} from '@/lib/workflows';

type History = {
    id: number;
    reason: string | null;
    created_at: string;
    snapshot: { from: string | null; to: string };
};
type Detail = {
    record: WorkflowRecord;
    history: { data: History[] };
    canEdit: boolean;
};

const { t } = useLocale();
const page = usePage();
const userId = computed(() => Number(page.props.auth.user.id));

const params = new URLSearchParams(window.location.search);
const initialKind = WORKFLOW_KINDS.find(
    (item) => item.key === params.get('kind'),
)?.key;
/** Set when an estimate is started from a lead, which the backend links to it. */
const leadId = ref(Number(params.get('lead_id')) || null);
const kind = ref<WorkflowKind>(initialKind ?? 'estimate');
const pipelines = ref<WorkflowPipeline[]>([]);
const records = ref<WorkflowRecord[]>([]);
const canConfigure = ref(false);
const pipelineId = ref(0);
const query = ref('');
const loading = ref(true);
const error = ref('');

const pipeline = computed(() =>
    pipelines.value.find((item) => item.id === pipelineId.value),
);
const stages = computed(() =>
    pipeline.value ? activeStages(pipeline.value) : [],
);
const groups = computed(() => groupByStage(stages.value, records.value));

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams({ kind: kind.value });
        if (pipelineId.value) {
            params.set('pipeline_id', String(pipelineId.value));
        }
        if (query.value.trim()) {
            params.set('q', query.value.trim());
        }
        const data = await apiJson<{
            pipelines: WorkflowPipeline[];
            records: { data: WorkflowRecord[] };
            canConfigure: boolean;
        }>(`/reference-workflows?${params}`);
        pipelines.value = data.pipelines.filter(
            (item) => item.kind === kind.value,
        );
        records.value = data.records.data;
        canConfigure.value = data.canConfigure;
        if (!pipelines.value.some((item) => item.id === pipelineId.value)) {
            pipelineId.value = pipelines.value[0]?.id ?? 0;
            if (pipelineId.value) {
                return load();
            }
        }
        error.value = '';
    } catch (failure) {
        error.value =
            failure instanceof ApiError && failure.status === 403
                ? t('You do not have access to these workflows.')
                : t('Could not load workflows.');
    } finally {
        loading.value = false;
    }
}

function pickKind(next: WorkflowKind): void {
    kind.value = next;
    pipelineId.value = 0;
    records.value = [];
    void load();
}

// Create ---------------------------------------------------------------
const createOpen = ref(false);
const createForm = ref({
    title: '',
    name: '',
    job_title: '',
    email: '',
    currency: 'AED',
    valid_until: '',
    notes: '',
});
const lines = ref<EstimateLine[]>([
    { description: '', quantity: '1', unit_price: '' },
]);
const createErrors = ref<Record<string, string>>({});
const busy = ref(false);
let key = uuid();

async function prefillFromLead(): Promise<void> {
    if (!leadId.value) {
        return;
    }
    createForm.value.title = params.get('title') ?? '';
    try {
        const data = await apiJson<{
            products: {
                name: string;
                quantity: string | number;
                unit_price: string | number;
                currency: string;
            }[];
        }>(`/crm/leads/${leadId.value}/products`);
        if (data.products.length) {
            lines.value = data.products.map((product) => ({
                description: product.name,
                quantity: String(product.quantity),
                unit_price: String(product.unit_price),
            }));
            createForm.value.currency = data.products[0].currency;
        }
    } catch {
        // The estimate can still be filled in by hand.
    }
}

function openCreate(): void {
    createForm.value = {
        title: '',
        name: '',
        job_title: '',
        email: '',
        currency: 'AED',
        valid_until: '',
        notes: '',
    };
    lines.value = [{ description: '', quantity: '1', unit_price: '' }];
    createErrors.value = {};
    key = uuid();
    createOpen.value = true;
    void prefillFromLead();
}

function collectErrors(
    failure: unknown,
    fallback: string,
): Record<string, string> {
    if (failure instanceof ApiError) {
        const fields = failure.fieldErrors();

        return Object.keys(fields).length ? fields : { form: failure.message };
    }

    return { form: fallback };
}

async function create(): Promise<void> {
    if (!pipeline.value) {
        return;
    }
    busy.value = true;
    createErrors.value = {};
    const form = createForm.value;
    const details =
        kind.value === 'estimate'
            ? {
                  currency: form.currency,
                  valid_until: form.valid_until || null,
                  notes: form.notes || null,
                  lines: lines.value,
              }
            : {
                  name: form.name,
                  job_title: form.job_title,
                  email: form.email || null,
                  notes: form.notes || null,
              };
    try {
        await apiJson('/reference-workflows', 'POST', {
            pipeline_id: pipeline.value.id,
            title: form.title,
            assigned_to: userId.value,
            operation_key: key,
            ...(kind.value === 'estimate' && leadId.value
                ? { lead_id: leadId.value }
                : {}),
            details,
        });
        createOpen.value = false;
        await load();
    } catch (failure) {
        createErrors.value = collectErrors(failure, t('Could not save.'));
    } finally {
        busy.value = false;
    }
}

// Move -----------------------------------------------------------------
const moving = ref<WorkflowRecord | null>(null);
const moveStageId = ref<number | ''>('');
const moveReason = ref('');
const moveError = ref('');

const targets = computed(() =>
    moving.value ? moveTargets(stages.value, moving.value.stage_id) : [],
);
const moveTarget = computed(() =>
    stages.value.find((stage) => stage.id === Number(moveStageId.value)),
);

function startMove(record: WorkflowRecord, stageId: number | '' = ''): void {
    moving.value = record;
    moveStageId.value = stageId;
    moveReason.value = '';
    moveError.value = '';
}

async function move(): Promise<void> {
    if (!moving.value || moveStageId.value === '') {
        return;
    }
    busy.value = true;
    moveError.value = '';
    try {
        await apiJson(`/reference-workflows/${moving.value.id}/stage`, 'PUT', {
            stage_id: moveStageId.value,
            expected_version: moving.value.version,
            reason: moveReason.value || null,
        });
        moving.value = null;
        await load();
    } catch (failure) {
        moveError.value = Object.values(
            collectErrors(failure, t('Could not move.')),
        )[0];
    } finally {
        busy.value = false;
    }
}

// Detail ---------------------------------------------------------------
const detail = ref<Detail | null>(null);
async function show(record: WorkflowRecord): Promise<void> {
    detail.value = await apiJson<Detail>(`/reference-workflows/${record.id}`);
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(() => void load(), 300);
});
watch(pipelineId, (next, previous) => {
    if (previous !== 0 && next !== previous && next !== 0) {
        void load();
    }
});
onMounted(async () => {
    await load();
    if (
        leadId.value &&
        params.get('new') === '1' &&
        canCreateKind(kind.value) &&
        pipeline.value
    ) {
        openCreate();
    }
});
</script>

<template>
    <Head :title="t('Workflows')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :title="t('Workflows')"
            :description="
                t(
                    'Estimates, invoices, documents and recruitment move through their own stages.',
                )
            "
        >
            <template #actions>
                <Link
                    v-if="canConfigure"
                    href="/workflows/pipelines"
                    class="text-sm underline"
                    >{{ t('Manage workflow pipelines') }}</Link
                >
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-3">
            <nav :aria-label="t('Workflow kinds')" class="flex flex-wrap gap-2">
                <button
                    v-for="item in WORKFLOW_KINDS"
                    :key="item.key"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium"
                    :class="
                        item.key === kind
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted border'
                    "
                    :aria-current="item.key === kind ? 'page' : undefined"
                    @click="pickKind(item.key)"
                >
                    {{ t(item.label) }}
                </button>
            </nav>
            <select
                v-if="pipelines.length > 1"
                v-model.number="pipelineId"
                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                :aria-label="t('Pipeline')"
            >
                <option
                    v-for="item in pipelines"
                    :key="item.id"
                    :value="item.id"
                >
                    {{ item.name }}
                </option>
            </select>
            <Input
                v-model="query"
                type="search"
                class="max-w-xs"
                :placeholder="t('Filter and search')"
                :aria-label="t('Filter and search')"
            />
            <Button
                v-if="canCreateKind(kind) && pipeline"
                type="button"
                class="ms-auto"
                @click="openCreate"
                ><Plus class="size-4" aria-hidden="true" />{{
                    t('Add')
                }}</Button
            >
        </div>

        <p v-if="error" role="alert" class="text-destructive text-sm">
            {{ error }}
        </p>
        <p
            v-else-if="!loading && !pipeline"
            class="text-muted-foreground text-sm"
        >
            {{ t('No pipelines for this kind yet.') }}
        </p>
        <p
            v-if="pipeline && !canCreateKind(kind)"
            class="text-muted-foreground text-xs"
        >
            {{
                kind === 'invoice'
                    ? t(
                          'Invoice workflow records are linked from the invoice itself.',
                      )
                    : t(
                          'Document workflow records are linked from the document itself.',
                      )
            }}
        </p>

        <div
            v-if="pipeline"
            role="region"
            tabindex="0"
            :aria-label="t('Workflow stages')"
            class="flex gap-2 overflow-x-auto pb-4"
        >
            <section
                v-for="stage in stages"
                :key="stage.id"
                class="bg-muted/40 flex min-h-48 w-72 shrink-0 flex-col rounded-md"
            >
                <h3
                    class="flex items-center justify-between px-4 py-2 text-sm font-medium"
                    :style="{
                        backgroundColor: stage.color,
                        color: readableOn(stage.color),
                    }"
                >
                    <span class="truncate">{{ stage.name }}</span>
                    <span>{{ groups.get(stage.id)?.length ?? 0 }}</span>
                </h3>
                <ul class="flex-1 space-y-2 overflow-y-auto p-2">
                    <li
                        v-for="record in groups.get(stage.id)"
                        :key="record.id"
                        class="bg-card rounded-md border p-3 text-sm"
                    >
                        <button
                            type="button"
                            class="text-start font-medium hover:underline"
                            @click="show(record)"
                        >
                            {{ record.title }}
                        </button>
                        <p
                            v-if="record.reference"
                            class="text-muted-foreground text-xs"
                        >
                            {{ record.reference }}
                        </p>
                        <p v-if="record.details.total" class="text-xs">
                            {{ record.details.currency }}
                            {{ record.details.total }}
                        </p>
                        <div class="mt-2 flex items-center justify-between">
                            <Badge v-if="record.closed_at" variant="outline">{{
                                t('Closed')
                            }}</Badge
                            ><span v-else />
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="startMove(record)"
                                >{{ t('Move') }}</Button
                            >
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <Dialog v-model:open="createOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        kind === 'estimate'
                            ? t('New estimate')
                            : t('New candidate')
                    }}</DialogTitle>
                    <DialogDescription>{{ pipeline?.name }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="create">
                    <InputError :message="createErrors.form" />
                    <div class="space-y-1">
                        <Label for="wf-title">{{ t('Title') }}</Label>
                        <Input id="wf-title" v-model="createForm.title" />
                        <InputError :message="createErrors.title" />
                    </div>
                    <template v-if="kind === 'estimate'">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <Label for="wf-cur">{{ t('Currency') }}</Label
                                ><Input
                                    id="wf-cur"
                                    v-model="createForm.currency"
                                    maxlength="3"
                                /><InputError
                                    :message="createErrors['details.currency']"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label for="wf-valid">{{
                                    t('Valid until')
                                }}</Label
                                ><Input
                                    id="wf-valid"
                                    v-model="createForm.valid_until"
                                    type="date"
                                /><InputError
                                    :message="
                                        createErrors['details.valid_until']
                                    "
                                />
                            </div>
                        </div>
                        <div
                            v-for="(line, index) in lines"
                            :key="index"
                            class="grid grid-cols-[1fr_5rem_6rem] gap-2"
                        >
                            <Input
                                v-model="line.description"
                                :placeholder="t('Description')"
                                :aria-label="t('Description')"
                            />
                            <Input
                                v-model="line.quantity"
                                inputmode="decimal"
                                :aria-label="t('Quantity')"
                            />
                            <Input
                                v-model="line.unit_price"
                                inputmode="decimal"
                                :placeholder="t('Price')"
                                :aria-label="t('Price')"
                            />
                        </div>
                        <InputError
                            :message="
                                createErrors['details.lines'] ??
                                createErrors['details.lines.0.description']
                            "
                        />
                        <div class="flex items-center justify-between text-sm">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="
                                    lines.push({
                                        description: '',
                                        quantity: '1',
                                        unit_price: '',
                                    })
                                "
                                >{{ t('Add line') }}</Button
                            >
                            <span
                                >{{ t('Total') }}: {{ createForm.currency }}
                                {{ estimateTotal(lines) }}</span
                            >
                        </div>
                    </template>
                    <template v-else>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <Label for="wf-name">{{ t('Name') }}</Label
                                ><Input
                                    id="wf-name"
                                    v-model="createForm.name"
                                /><InputError
                                    :message="createErrors['details.name']"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label for="wf-job">{{ t('Job title') }}</Label
                                ><Input
                                    id="wf-job"
                                    v-model="createForm.job_title"
                                /><InputError
                                    :message="createErrors['details.job_title']"
                                />
                            </div>
                        </div>
                        <div class="space-y-1">
                            <Label for="wf-email">{{ t('Email') }}</Label
                            ><Input
                                id="wf-email"
                                v-model="createForm.email"
                                type="email"
                            /><InputError
                                :message="createErrors['details.email']"
                            />
                        </div>
                    </template>
                    <div class="space-y-1">
                        <Label for="wf-notes">{{ t('Notes') }}</Label
                        ><Input id="wf-notes" v-model="createForm.notes" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="createOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="busy">{{
                            t('Save')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="moving !== null"
            @update:open="(value) => !value && (moving = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ t('Move') }}</DialogTitle>
                    <DialogDescription>{{ moving?.title }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="move">
                    <InputError :message="moveError" />
                    <div class="space-y-1">
                        <Label for="wf-stage">{{ t('Stage') }}</Label>
                        <select
                            id="wf-stage"
                            v-model.number="moveStageId"
                            class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                        >
                            <option value="" disabled>—</option>
                            <option
                                v-for="stage in targets"
                                :key="stage.id"
                                :value="stage.id"
                            >
                                {{ stage.name }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="moving && needsReason(moving, moveTarget)"
                        class="space-y-1"
                    >
                        <Label for="wf-reason">{{ t('Reason') }}</Label>
                        <Input id="wf-reason" v-model="moveReason" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="moving = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="busy || moveStageId === ''"
                            >{{ t('Move') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="detail !== null"
            @update:open="(value) => !value && (detail = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ detail?.record.title }}</DialogTitle>
                    <DialogDescription>{{
                        detail?.record.reference
                    }}</DialogDescription>
                </DialogHeader>
                <h4 class="text-eyebrow">{{ t('History') }}</h4>
                <ul class="space-y-1 text-sm">
                    <li v-for="entry in detail?.history.data" :key="entry.id">
                        {{ entry.snapshot.from ?? '—' }} →
                        {{ entry.snapshot.to }}
                        <span v-if="entry.reason" class="text-muted-foreground"
                            >· {{ entry.reason }}</span
                        >
                    </li>
                </ul>
            </DialogContent>
        </Dialog>
    </div>
</template>
