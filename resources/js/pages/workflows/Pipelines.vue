<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { readableOn } from '@/lib/crm-pipeline-board';
import {
    ENTRY_ROLES,
    INVOICE_STATUSES,
    WORKFLOW_KINDS,
    requirableFields,
    stageRulesFrom,
    stageRulesPayload,
    toggleRule,
} from '@/lib/workflows';
import type { StageRules } from '@/lib/workflows';
import type {
    WorkflowKind,
    WorkflowPipeline,
    WorkflowStage,
} from '@/lib/workflows';

const { t } = useLocale();
const pipelines = ref<WorkflowPipeline[]>([]);
const loading = ref(true);
const error = ref('');
const message = ref('');
const busy = ref(false);

const newPipeline = ref<{ kind: WorkflowKind; name: string }>({
    kind: 'estimate',
    name: '',
});
const editing = ref<{ pipelineId: number; stage: number | 'new' } | null>(null);
const stageForm = ref({
    name: '',
    type: 'normal',
    color: '#64748b',
    active: true,
    position: 1,
});

const detailFields = ref<string[]>([]);
const rules = ref<StageRules>(stageRulesFrom());
const editingPipeline = computed(() =>
    pipelines.value.find((item) => item.id === editing.value?.pipelineId),
);
const otherStages = computed(() =>
    (editingPipeline.value?.stages ?? []).filter(
        (stage) =>
            editing.value?.stage === 'new' || stage.id !== editing.value?.stage,
    ),
);
const requirable = computed(() =>
    editingPipeline.value
        ? requirableFields(
              editingPipeline.value.kind,
              detailFields.value,
              (editingPipeline.value.field_definitions ?? []).map(
                  (field) => field.key,
              ),
          )
        : [],
);

const grouped = computed(() =>
    WORKFLOW_KINDS.map((kind) => ({
        ...kind,
        pipelines: pipelines.value.filter((item) => item.kind === kind.key),
    })),
);

function failure(cause: unknown): string {
    return cause instanceof ApiError
        ? Object.values(cause.fieldErrors())[0] || cause.message
        : t('Could not save.');
}

async function load(): Promise<void> {
    try {
        const data = await apiJson<{
            pipelines: WorkflowPipeline[];
            detailFields: string[];
        }>('/reference-workflows');
        pipelines.value = data.pipelines;
        detailFields.value = data.detailFields;
        error.value = '';
    } catch {
        error.value = t(
            'Only owners and administrators can manage workflow pipelines.',
        );
    } finally {
        loading.value = false;
    }
}

async function run(action: () => Promise<unknown>): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        await action();
        editing.value = null;
        await load();
    } catch (cause) {
        message.value = failure(cause);
    } finally {
        busy.value = false;
    }
}

const addPipeline = (): Promise<void> =>
    run(async () => {
        await apiJson(
            '/reference-workflows/pipelines',
            'POST',
            newPipeline.value,
        );
        newPipeline.value.name = '';
    });

function editStage(pipeline: WorkflowPipeline, stage?: WorkflowStage): void {
    editing.value = { pipelineId: pipeline.id, stage: stage?.id ?? 'new' };
    rules.value = stageRulesFrom(stage);
    stageForm.value = stage
        ? {
              name: stage.name,
              type: stage.type,
              color: stage.color,
              active: stage.active,
              position: stage.position,
          }
        : {
              name: '',
              type: 'normal',
              color: '#64748b',
              active: true,
              position: pipeline.stages.length + 1,
          };
}

const saveStage = (): Promise<void> =>
    run(() => {
        const current = editing.value!;
        const base = `/reference-workflows/pipelines/${current.pipelineId}/stages`;

        const body = {
            ...stageForm.value,
            ...stageRulesPayload(rules.value, editingPipeline.value!.kind),
        };

        return current.stage === 'new'
            ? apiJson(base, 'POST', body)
            : apiJson(`${base}/${current.stage}`, 'PUT', body);
    });

const toggle = (pipeline: WorkflowPipeline): Promise<void> =>
    run(() =>
        apiJson(`/reference-workflows/pipelines/${pipeline.id}`, 'PUT', {
            kind: pipeline.kind,
            name: pipeline.name,
            active: !pipeline.active,
        }),
    );

onMounted(load);
</script>

<template>
    <Head :title="t('Workflow pipelines')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Workflow pipelines')"
            :description="
                t('Stages for estimates, invoices, documents and recruitment.')
            "
        >
            <template #actions>
                <Link href="/workflows" class="text-sm underline">{{
                    t('Back to workflows')
                }}</Link>
            </template>
        </PageHeader>
        <p v-if="error" role="alert" class="text-destructive text-sm">
            {{ error }}
        </p>
        <InputError :message="message" />

        <Card>
            <CardContent>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="addPipeline"
                >
                    <div class="space-y-1">
                        <Label for="np-kind">{{ t('Kind') }}</Label>
                        <select
                            id="np-kind"
                            v-model="newPipeline.kind"
                            class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            <option
                                v-for="kind in WORKFLOW_KINDS"
                                :key="kind.key"
                                :value="kind.key"
                            >
                                {{ t(kind.label) }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="np-name">{{ t('Name') }}</Label>
                        <Input id="np-name" v-model="newPipeline.name" />
                    </div>
                    <Button
                        type="submit"
                        :disabled="busy || !newPipeline.name.trim()"
                        >{{ t('Add pipeline') }}</Button
                    >
                </form>
            </CardContent>
        </Card>

        <section v-for="group in grouped" :key="group.key" class="space-y-3">
            <h2 class="text-eyebrow">{{ t(group.label) }}</h2>
            <p
                v-if="!group.pipelines.length && !loading"
                class="text-muted-foreground text-sm"
            >
                {{ t('No pipelines for this kind yet.') }}
            </p>
            <Card v-for="pipeline in group.pipelines" :key="pipeline.id">
                <CardContent class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-display text-xl font-medium">
                            {{ pipeline.name }}
                        </h3>
                        <Badge v-if="!pipeline.active" variant="outline">{{
                            t('inactive')
                        }}</Badge>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="ms-auto"
                            :disabled="busy"
                            @click="toggle(pipeline)"
                            >{{
                                pipeline.active ? t('Archive') : t('Restore')
                            }}</Button
                        >
                    </div>
                    <ul class="flex flex-wrap gap-2">
                        <li v-for="stage in pipeline.stages" :key="stage.id">
                            <button
                                type="button"
                                class="rounded-md px-3 py-1 text-sm"
                                :class="{ 'opacity-50': !stage.active }"
                                :style="{
                                    backgroundColor: stage.color,
                                    color: readableOn(stage.color),
                                }"
                                @click="editStage(pipeline, stage)"
                            >
                                {{ stage.position }}. {{ stage.name }}
                            </button>
                        </li>
                        <li>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="editStage(pipeline)"
                                >{{ t('Add stage') }}</Button
                            >
                        </li>
                    </ul>
                    <form
                        v-if="editing?.pipelineId === pipeline.id"
                        class="grid gap-3 rounded-md border p-3 sm:grid-cols-5"
                        @submit.prevent="saveStage"
                    >
                        <div class="space-y-1 sm:col-span-2">
                            <Label for="st-name">{{ t('Name') }}</Label
                            ><Input id="st-name" v-model="stageForm.name" />
                        </div>
                        <div class="space-y-1">
                            <Label for="st-type">{{ t('Outcome') }}</Label>
                            <select
                                id="st-type"
                                v-model="stageForm.type"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                            >
                                <option value="normal">
                                    {{ t('Normal') }}
                                </option>
                                <option value="success">
                                    {{ t('Success') }}
                                </option>
                                <option value="failure">
                                    {{ t('Failed') }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="st-color">{{ t('Color') }}</Label
                            ><Input
                                id="st-color"
                                v-model="stageForm.color"
                                type="color"
                                class="h-9 p-1"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="st-pos">{{ t('Position') }}</Label
                            ><Input
                                id="st-pos"
                                v-model.number="stageForm.position"
                                type="number"
                                min="1"
                            />
                        </div>
                        <label
                            class="flex items-center gap-2 text-sm sm:col-span-3"
                            ><input
                                v-model="stageForm.active"
                                type="checkbox"
                            />{{ t('Active') }}</label
                        >
                        <details class="rounded-md border p-3 sm:col-span-5">
                            <summary class="cursor-pointer text-sm font-medium">
                                {{ t('Stage rules') }}
                            </summary>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <label
                                    class="flex items-center gap-2 text-sm sm:col-span-2"
                                    ><input
                                        v-model="rules.is_initial"
                                        type="checkbox"
                                    />{{
                                        t('New records start in this stage')
                                    }}</label
                                >
                                <fieldset class="space-y-1">
                                    <legend class="text-sm font-medium">
                                        {{ t('Can be entered from') }}
                                    </legend>
                                    <p class="text-muted-foreground text-xs">
                                        {{
                                            t('Leave empty to allow any stage.')
                                        }}
                                    </p>
                                    <label
                                        v-for="other in otherStages"
                                        :key="other.id"
                                        class="flex items-center gap-2 text-sm"
                                        ><input
                                            type="checkbox"
                                            :checked="
                                                rules.allowed_from_stage_ids?.includes(
                                                    other.id,
                                                ) ?? false
                                            "
                                            @change="
                                                rules.allowed_from_stage_ids =
                                                    toggleRule(
                                                        rules.allowed_from_stage_ids,
                                                        other.id,
                                                    )
                                            "
                                        />{{ other.name }}</label
                                    >
                                </fieldset>
                                <fieldset class="space-y-1">
                                    <legend class="text-sm font-medium">
                                        {{
                                            t(
                                                'Roles that can move records here',
                                            )
                                        }}
                                    </legend>
                                    <p class="text-muted-foreground text-xs">
                                        {{
                                            t(
                                                'Leave empty to allow every role.',
                                            )
                                        }}
                                    </p>
                                    <label
                                        v-for="role in ENTRY_ROLES"
                                        :key="role"
                                        class="flex items-center gap-2 text-sm capitalize"
                                        ><input
                                            type="checkbox"
                                            :checked="
                                                rules.entry_roles?.includes(
                                                    role,
                                                ) ?? false
                                            "
                                            @change="
                                                rules.entry_roles = toggleRule(
                                                    rules.entry_roles,
                                                    role,
                                                )
                                            "
                                        />{{ t(role) }}</label
                                    >
                                </fieldset>
                                <fieldset class="space-y-1">
                                    <legend class="text-sm font-medium">
                                        {{ t('Fields required to enter') }}
                                    </legend>
                                    <label
                                        v-for="field in requirable"
                                        :key="field.value"
                                        class="flex items-center gap-2 text-sm capitalize"
                                        ><input
                                            type="checkbox"
                                            :checked="
                                                rules.required_fields?.includes(
                                                    field.value,
                                                ) ?? false
                                            "
                                            @change="
                                                rules.required_fields =
                                                    toggleRule(
                                                        rules.required_fields,
                                                        field.value,
                                                    )
                                            "
                                        />{{ field.label }}</label
                                    >
                                </fieldset>
                                <fieldset
                                    v-if="editingPipeline?.kind === 'invoice'"
                                    class="space-y-1"
                                >
                                    <legend class="text-sm font-medium">
                                        {{ t('Invoice must be') }}
                                    </legend>
                                    <label
                                        v-for="status in INVOICE_STATUSES"
                                        :key="status"
                                        class="flex items-center gap-2 text-sm capitalize"
                                        ><input
                                            type="checkbox"
                                            :checked="
                                                rules.source_statuses?.includes(
                                                    status,
                                                ) ?? false
                                            "
                                            @change="
                                                rules.source_statuses =
                                                    toggleRule(
                                                        rules.source_statuses,
                                                        status,
                                                    )
                                            "
                                        />{{ t(status) }}</label
                                    >
                                </fieldset>
                            </div>
                        </details>
                        <div class="flex justify-end gap-2 sm:col-span-2">
                            <Button
                                type="button"
                                variant="outline"
                                @click="editing = null"
                                >{{ t('Cancel') }}</Button
                            >
                            <Button type="submit" :disabled="busy">{{
                                t('Save')
                            }}</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </section>
    </div>
</template>
