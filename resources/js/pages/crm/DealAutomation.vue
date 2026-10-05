<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CrmConditionEditor from '@/components/CrmConditionEditor.vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
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
import { cleanConditions } from '@/lib/crm-deal-filters';
import type { Condition, FilterField } from '@/lib/crm-deal-filters';
import {
    ACTION_LABELS,
    ACTIVITY_TYPES,
    delayText,
    outcomeLabel,
    ruleForm,
    rulePayload,
} from '@/lib/crm-deal-automation';
import type {
    DealExecution,
    DealRule,
    RuleForm,
} from '@/lib/crm-deal-automation';
import type { DataTableColumn } from '@/lib/data-table';

type Stage = {
    id: number;
    name: string;
    active: boolean | number;
    type: string;
};
type Pipeline = { id: number; name: string; stages: Stage[] };
type RuleRow = {
    id: number;
    name: string;
    pipeline: string;
    trigger: string;
    action: string;
    delay: string;
    status: string;
};

const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const rules = ref<DealRule[]>([]);
const executions = ref<DealExecution[]>([]);
const pipelines = ref<Pipeline[]>([]);
const fields = ref<FilterField[]>([]);
const loading = ref(true);
const loadError = ref('');
const message = ref('');
const tab = ref<'rules' | 'log'>('rules');

const open = ref(false);
const editing = ref<DealRule | null>(null);
const form = ref<RuleForm>(ruleForm(null, 0, 0));
const conditions = ref<Condition[]>([]);
const errors = ref<Record<string, string>>({});
const busy = ref(false);

const pipelineOf = (id: number): Pipeline | undefined =>
    pipelines.value.find((item) => item.id === id);
const stageName = (pipelineId: number, stageId: number | null): string =>
    pipelineOf(pipelineId)?.stages.find((stage) => stage.id === stageId)
        ?.name ?? '';
const activeStages = computed(() =>
    (pipelineOf(form.value.pipeline_id)?.stages ?? []).filter(
        (stage) => stage.active === true || stage.active === 1,
    ),
);
const isActive = (rule: DealRule): boolean =>
    rule.active === true || rule.active === 1;

const rows = computed<RuleRow[]>(() =>
    rules.value.map((rule) => ({
        id: rule.id,
        name: rule.name,
        pipeline: pipelineOf(rule.pipeline_id)?.name ?? '',
        trigger: stageName(rule.pipeline_id, rule.stage_id),
        action:
            t(ACTION_LABELS[rule.action] ?? rule.action) +
            (rule.action === 'change_stage' && rule.target_stage_id
                ? ` → ${stageName(rule.pipeline_id, rule.target_stage_id)}`
                : ''),
        delay: t(delayText(rule.delay_minutes)),
        status: isActive(rule) ? t('Active') : t('Archived'),
    })),
);
const columns = computed<DataTableColumn<RuleRow>[]>(() => [
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'pipeline', label: t('Pipeline'), sortable: true },
    { key: 'trigger', label: t('When a deal enters') },
    { key: 'action', label: t('Then') },
    { key: 'delay', label: t('Delay') },
    { key: 'status', label: t('Status') },
]);
const logRows = computed(() =>
    executions.value.map((execution) => ({
        id: execution.id,
        rule:
            rules.value.find((rule) => rule.id === execution.rule_id)?.name ??
            `#${execution.rule_id}`,
        outcome: t(outcomeLabel(execution.outcome)),
        scheduled_at: execution.scheduled_at,
    })),
);
const logColumns = computed<DataTableColumn<(typeof logRows.value)[number]>[]>(
    () => [
        { key: 'rule', label: t('Rule') },
        { key: 'outcome', label: t('Outcome') },
        { key: 'scheduled_at', label: t('Scheduled'), sortable: true },
    ],
);

async function load(): Promise<void> {
    try {
        const [automation, configuration, catalog] = await Promise.all([
            apiJson<{
                rules: DealRule[];
                executions: { data: DealExecution[] };
            }>('/crm/deals/automation'),
            apiJson<{ pipelines: Pipeline[] }>('/crm/deals/configuration'),
            apiJson<{ filterFields: FilterField[] }>('/crm/deals'),
        ]);
        rules.value = automation.rules;
        executions.value = automation.executions.data;
        pipelines.value = configuration.pipelines;
        fields.value = catalog.filterFields ?? [];
        loadError.value = '';
    } catch {
        loadError.value = t(
            'Only owners and administrators can manage deal automation.',
        );
    } finally {
        loading.value = false;
    }
}

function openForm(rule?: DealRule): void {
    const first = pipelines.value[0];
    editing.value = rule ?? null;
    form.value = ruleForm(
        rule ?? null,
        first?.id ?? 0,
        first?.stages[0]?.id ?? 0,
    );
    conditions.value = structuredClone((rule?.conditions ?? []) as Condition[]);
    errors.value = {};
    open.value = true;
}

function pickPipeline(): void {
    const stages = activeStages.value;
    form.value.stage_id = stages[0]?.id ?? 0;
    form.value.target_stage_id = null;
}

async function persist(
    rule: DealRule | null,
    data: Record<string, unknown>,
): Promise<void> {
    await apiJson(
        rule ? `/crm/deals/automation/${rule.id}` : '/crm/deals/automation',
        rule ? 'PUT' : 'POST',
        data,
    );
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await persist(
            editing.value,
            rulePayload(
                form.value,
                cleanConditions(fields.value, conditions.value),
            ),
        );
        open.value = false;
        message.value = t('Saved.');
        await load();
    } catch (failure) {
        if (failure instanceof ApiError) {
            const found = failure.fieldErrors();
            errors.value = Object.keys(found).length
                ? found
                : { form: failure.message };
        } else {
            errors.value = { form: t('Could not save.') };
        }
    } finally {
        busy.value = false;
    }
}

/** Rules are switched off rather than removed so past runs keep their context. */
async function archive(keys: (string | number)[]): Promise<void> {
    message.value = '';
    for (const key of keys) {
        const rule = rules.value.find((item) => item.id === key);
        if (rule && isActive(rule)) {
            try {
                await persist(
                    rule,
                    rulePayload(
                        {
                            ...ruleForm(rule, rule.pipeline_id, rule.stage_id),
                            active: false,
                        },
                        rule.conditions ?? [],
                    ),
                );
            } catch (failure) {
                message.value =
                    failure instanceof ApiError
                        ? failure.message
                        : t('Could not archive.');
            }
        }
    }
    await load();
}

function editSelected(keys: (string | number)[]): void {
    const rule = rules.value.find((item) => item.id === keys[0]);
    if (rule) {
        openForm(rule);
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('Deal automation rules')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Deal automation rules"
            description="Run an action automatically when a deal enters a stage."
        >
            <template #actions>
                <Link href="/deals" class="text-sm underline">{{
                    t('Back to deals')
                }}</Link>
            </template>
        </PageHeader>

        <nav :aria-label="t('Automation views')" class="flex gap-2">
            <button
                v-for="item in [
                    { key: 'rules', label: 'Rules' },
                    { key: 'log', label: 'Run log' },
                ] as const"
                :key="item.key"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="
                    item.key === tab
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted border'
                "
                :aria-current="item.key === tab ? 'page' : undefined"
                @click="tab = item.key"
            >
                {{ t(item.label) }}
            </button>
        </nav>

        <p v-if="message" role="status" class="text-sm">{{ message }}</p>
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>

        <template v-else>
            <CrmSettingsTable
                v-if="tab === 'rules'"
                title=""
                :columns="columns"
                :rows="rows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                add-label="Add rule"
                searchable
                can-edit
                @add="openForm()"
                @edit="editSelected"
                @delete="archive"
            >
                <template #cell-status="{ row }">
                    <Badge
                        :variant="
                            row.status === t('Active') ? 'secondary' : 'outline'
                        "
                        >{{ row.status }}</Badge
                    >
                </template>
            </CrmSettingsTable>
            <CrmSettingsTable
                v-else
                title=""
                add-label=""
                :columns="logColumns"
                :rows="logRows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.rule"
                :selectable="false"
            />
        </template>
        <p v-if="loading" class="text-muted-foreground text-sm">
            {{ t('Loading…') }}
        </p>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? t('Edit') : t('Add rule')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t(
                            'Run an action automatically when a deal enters a stage.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="save">
                    <InputError :message="errors.form" />
                    <div class="space-y-1">
                        <Label for="ar-name">{{ t('Name') }}</Label>
                        <Input id="ar-name" v-model="form.name" />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="ar-pipeline">{{ t('Pipeline') }}</Label>
                            <select
                                id="ar-pipeline"
                                v-model.number="form.pipeline_id"
                                :class="selectClass"
                                :disabled="!!editing"
                                @change="pickPipeline"
                            >
                                <option
                                    v-for="item in pipelines"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.name }}
                                </option>
                            </select>
                            <InputError :message="errors.pipeline_id" />
                        </div>
                        <div class="space-y-1">
                            <Label for="ar-stage">{{
                                t('When a deal enters')
                            }}</Label>
                            <select
                                id="ar-stage"
                                v-model.number="form.stage_id"
                                :class="selectClass"
                            >
                                <option
                                    v-for="stage in activeStages"
                                    :key="stage.id"
                                    :value="stage.id"
                                >
                                    {{ stage.name }}
                                </option>
                            </select>
                            <InputError :message="errors.stage_id" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="ar-action">{{ t('Then') }}</Label>
                        <select
                            id="ar-action"
                            v-model="form.action"
                            :class="selectClass"
                        >
                            <option
                                v-for="(label, key) in ACTION_LABELS"
                                :key="key"
                                :value="key"
                            >
                                {{ t(label) }}
                            </option>
                        </select>
                        <InputError :message="errors.action" />
                    </div>
                    <div
                        v-if="form.action === 'change_stage'"
                        class="space-y-1"
                    >
                        <Label for="ar-target">{{ t('Move to stage') }}</Label>
                        <select
                            id="ar-target"
                            v-model.number="form.target_stage_id"
                            :class="selectClass"
                        >
                            <option :value="null">—</option>
                            <option
                                v-for="stage in activeStages.filter(
                                    (item) =>
                                        item.type === 'normal' &&
                                        item.id !== form.stage_id,
                                )"
                                :key="stage.id"
                                :value="stage.id"
                            >
                                {{ stage.name }}
                            </option>
                        </select>
                        <InputError :message="errors.target_stage_id" />
                    </div>
                    <div
                        v-if="form.action === 'create_follow_up'"
                        class="grid grid-cols-2 gap-3"
                    >
                        <div class="space-y-1">
                            <Label for="ar-type">{{
                                t('Activity type')
                            }}</Label>
                            <select
                                id="ar-type"
                                v-model="form.activity_type"
                                :class="selectClass"
                            >
                                <option
                                    v-for="type in ACTIVITY_TYPES"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ t(type) }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="ar-due">{{ t('Due in (days)') }}</Label>
                            <Input
                                id="ar-due"
                                v-model.number="form.due_days"
                                type="number"
                                min="0"
                                max="365"
                            />
                            <InputError :message="errors.due_days" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="ar-delay">{{ t('Delay (minutes)') }}</Label>
                        <Input
                            id="ar-delay"
                            v-model.number="form.delay_minutes"
                            type="number"
                            min="0"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{ t(delayText(Number(form.delay_minutes) || 0)) }}
                        </p>
                        <InputError :message="errors.delay_minutes" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.working_hours_only"
                            type="checkbox"
                        />{{
                            t('Count the delay in working hours only')
                        }}</label
                    >
                    <div class="space-y-2">
                        <p class="text-sm font-medium">
                            {{ t('Only when the deal matches') }}
                        </p>
                        <CrmConditionEditor
                            v-model="conditions"
                            :fields="fields"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="form.active" type="checkbox" />{{
                            t('Active')
                        }}</label
                    >
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="busy">{{
                            t('Save')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
