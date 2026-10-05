<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import CrmPipelinePreview from '@/components/CrmPipelinePreview.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { groupStages, moveWithin } from '@/lib/crm-pipeline-editor';
import { readableOn } from '@/lib/crm-pipeline-board';
import type { Stage } from '@/types/crm-pipeline';

type DealStageRow = Stage & { is_initial: boolean };
type Pipeline = {
    id: number;
    name: string;
    description: string | null;
    active: boolean;
    is_default: boolean;
    position: number;
    stages: DealStageRow[];
};

const { t } = useLocale();
const pipelines = ref<Pipeline[]>([]);
const selectedId = ref(0);
const loading = ref(true);
const error = ref('');
const fieldErrors = ref<Record<string, string>>({});
const busy = ref(false);
const newName = ref('');
const editingStage = ref<number | 'new' | null>(null);
const newType = ref<'normal' | 'won' | 'lost'>('normal');
const stageForm = ref({
    name: '',
    type: 'normal',
    color: '#64748b',
    active: true,
    is_initial: false,
});
const pipelineForm = ref({
    name: '',
    description: '',
    active: true,
    is_default: false,
});

const selected = computed(() =>
    pipelines.value.find((item) => item.id === selectedId.value),
);
const groups = computed(() => groupStages(selected.value?.stages ?? []));

function fail(cause: unknown): void {
    error.value =
        cause instanceof ApiError
            ? cause.message
            : t('Something went wrong. Please try again.');
    fieldErrors.value = cause instanceof ApiError ? cause.fieldErrors() : {};
}
async function load(keep = true): Promise<void> {
    try {
        const data = await apiJson<{ pipelines: Pipeline[] }>(
            '/crm/deals/configuration',
        );
        pipelines.value = data.pipelines;
        if (
            !keep ||
            !pipelines.value.some((item) => item.id === selectedId.value)
        ) {
            selectedId.value =
                pipelines.value.find((item) => item.is_default)?.id ??
                pipelines.value[0]?.id ??
                0;
        }
        const current = pipelines.value.find(
            (item) => item.id === selectedId.value,
        );
        if (current) {
            pipelineForm.value = {
                name: current.name,
                description: current.description ?? '',
                active: current.active,
                is_default: current.is_default,
            };
        }
    } catch (cause) {
        fail(cause);
    } finally {
        loading.value = false;
    }
}
async function run(action: () => Promise<unknown>): Promise<void> {
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        await action();
        await load();
    } catch (cause) {
        fail(cause);
    } finally {
        busy.value = false;
    }
}
function select(id: number): void {
    selectedId.value = id;
    editingStage.value = null;
    const current = pipelines.value.find((item) => item.id === id);
    if (current) {
        pipelineForm.value = {
            name: current.name,
            description: current.description ?? '',
            active: current.active,
            is_default: current.is_default,
        };
    }
}
async function addPipeline(): Promise<void> {
    await run(async () => {
        const result = await apiJson<{ pipeline: { id: number } }>(
            '/crm/deals/pipelines',
            'POST',
            { name: newName.value },
        );
        newName.value = '';
        selectedId.value = result.pipeline.id;
    });
}
async function savePipeline(): Promise<void> {
    if (!selected.value) return;
    await run(() =>
        apiJson(`/crm/deals/pipelines/${selected.value!.id}`, 'PUT', {
            ...pipelineForm.value,
            description: pipelineForm.value.description || null,
            position: selected.value!.position,
        }),
    );
}
function openStage(
    stage: DealStageRow | null,
    type: 'normal' | 'won' | 'lost' = 'normal',
): void {
    fieldErrors.value = {};
    if (stage) {
        editingStage.value = stage.id;
        stageForm.value = {
            name: stage.name,
            type: stage.type,
            color: stage.color,
            active: stage.active,
            is_initial: stage.is_initial,
        };
    } else {
        editingStage.value =
            editingStage.value === 'new' && newType.value === type
                ? null
                : 'new';
        newType.value = type;
        stageForm.value = {
            name: '',
            type,
            color:
                type === 'won'
                    ? '#65a30d'
                    : type === 'lost'
                      ? '#ef4444'
                      : '#64748b',
            active: true,
            is_initial: false,
        };
    }
}
async function saveStage(): Promise<void> {
    if (!selected.value) return;
    const pipelineId = selected.value.id;
    const existing =
        editingStage.value !== 'new'
            ? selected.value.stages.find(
                  (stage) => stage.id === editingStage.value,
              )
            : undefined;
    const position =
        existing?.position ??
        Math.max(0, ...selected.value.stages.map((stage) => stage.position)) +
            1;
    await run(async () => {
        const body = { ...stageForm.value, position };
        await apiJson(
            existing
                ? `/crm/deals/pipelines/${pipelineId}/stages/${existing.id}`
                : `/crm/deals/pipelines/${pipelineId}/stages`,
            existing ? 'PUT' : 'POST',
            body,
        );
        editingStage.value = null;
    });
}
async function reorder(
    list: DealStageRow[],
    stage: DealStageRow,
    delta: number,
): Promise<void> {
    if (!selected.value) return;
    const pipelineId = selected.value.id;
    const changes = moveWithin(
        list as Stage[],
        stage.id,
        list.findIndex((item) => item.id === stage.id) + delta,
    );
    await run(async () => {
        for (const change of changes) {
            const row = selected.value!.stages.find(
                (item) => item.id === change.id,
            )!;
            await apiJson(
                `/crm/deals/pipelines/${pipelineId}/stages/${row.id}`,
                'PUT',
                {
                    name: row.name,
                    type: row.type,
                    color: row.color,
                    active: row.active,
                    position: change.position,
                },
            );
        }
    });
}
async function removeStage(stage: DealStageRow): Promise<void> {
    if (confirm(`${t('Delete unused stage')} “${stage.name}”?`)) {
        await run(() =>
            apiJson(`/crm/deals/configuration/stage/${stage.id}`, 'DELETE'),
        );
        editingStage.value = null;
    }
}
async function removePipeline(): Promise<void> {
    if (
        selected.value &&
        confirm(`${t('Delete unused pipeline')} “${selected.value.name}”?`)
    ) {
        const id = selected.value.id;
        await run(() =>
            apiJson(`/crm/deals/configuration/pipeline/${id}`, 'DELETE'),
        );
        await load(false);
    }
}
onMounted(() => load(false));
</script>

<template>
    <Head :title="t('Deal pipelines')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Deal pipelines')"
            :description="
                t(
                    'Each pipeline has its own stages. Who can use it is set under Access permissions.',
                )
            "
            :translate="false"
        >
            <template #actions
                ><Link href="/crm/permissions" class="text-sm underline">{{
                    t('Access permissions')
                }}</Link
                ><Link href="/deals" class="text-sm underline">{{
                    t('Back to deals')
                }}</Link></template
            >
        </PageHeader>
        <div aria-live="polite"><InputError :message="error" /></div>
        <p v-if="loading" class="text-muted-foreground text-sm" role="status">
            {{ t('Loading…') }}
        </p>
        <div v-else class="grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <nav :aria-label="t('Deal pipelines')" class="space-y-3">
                <ul class="space-y-1">
                    <li v-for="pipeline in pipelines" :key="pipeline.id">
                        <button
                            type="button"
                            class="hover:bg-muted flex w-full items-center gap-2 rounded-md px-3 py-2 text-start text-sm"
                            :class="
                                pipeline.id === selectedId
                                    ? 'bg-muted font-medium'
                                    : ''
                            "
                            :aria-current="
                                pipeline.id === selectedId ? 'true' : undefined
                            "
                            @click="select(pipeline.id)"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                pipeline.name
                            }}</span
                            ><Badge
                                v-if="pipeline.is_default"
                                variant="secondary"
                                >{{ t('default') }}</Badge
                            ><Badge v-if="!pipeline.active" variant="outline">{{
                                t('inactive')
                            }}</Badge>
                        </button>
                    </li>
                </ul>
                <form
                    class="space-y-2 border-t pt-3"
                    @submit.prevent="addPipeline"
                >
                    <Label for="new-deal-pipeline" class="text-xs">{{
                        t('Add pipeline')
                    }}</Label>
                    <div class="flex gap-2">
                        <Input
                            id="new-deal-pipeline"
                            v-model="newName"
                            maxlength="100"
                            required
                            :placeholder="t('Pipeline name')"
                        /><Button
                            type="submit"
                            size="icon"
                            :disabled="busy || !newName.trim()"
                            :aria-label="t('Add pipeline')"
                            ><Plus class="size-4" aria-hidden="true"
                        /></Button>
                    </div>
                    <InputError :message="fieldErrors.name" />
                </form>
            </nav>

            <div v-if="selected" class="min-w-0 space-y-6">
                <details class="rounded-md border p-3">
                    <summary class="cursor-pointer text-sm font-medium">
                        {{ t('Pipeline settings') }}: {{ selected.name }}
                    </summary>
                    <form
                        class="mt-3 grid gap-3 sm:grid-cols-2"
                        @submit.prevent="savePipeline"
                    >
                        <div class="space-y-1">
                            <Label for="dp-name">{{ t('Name') }}</Label
                            ><Input
                                id="dp-name"
                                v-model="pipelineForm.name"
                                required
                                maxlength="100"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="dp-desc">{{ t('Description') }}</Label
                            ><Input
                                id="dp-desc"
                                v-model="pipelineForm.description"
                                maxlength="2000"
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="pipelineForm.active"
                                type="checkbox"
                            />{{ t('Active') }}</label
                        >
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="pipelineForm.is_default"
                                type="checkbox"
                            />{{ t('Default pipeline') }}</label
                        >
                        <div class="flex gap-2 sm:col-span-2">
                            <Button type="submit" :disabled="busy">{{
                                t('Save changes')
                            }}</Button
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="busy"
                                @click="removePipeline"
                                >{{ t('Delete unused') }}</Button
                            >
                        </div>
                        <InputError
                            :message="
                                fieldErrors.active || fieldErrors.is_default
                            "
                            class="sm:col-span-2"
                        />
                    </form>
                </details>

                <template
                    v-for="section in [
                        {
                            key: 'initial',
                            label: 'Initial stage',
                            list: groups.initial
                                ? [groups.initial as DealStageRow]
                                : [],
                            sortable: false,
                            add: null,
                        },
                        {
                            key: 'additional',
                            label: 'Additional stages',
                            list: groups.additional as DealStageRow[],
                            sortable: true,
                            add: 'normal' as const,
                        },
                    ]"
                    :key="section.key"
                >
                    <section
                        class="bg-card space-y-2 rounded-md border p-3"
                        :aria-label="t(section.label)"
                    >
                        <h2 class="text-eyebrow">{{ t(section.label) }}</h2>
                        <div
                            v-for="stage in section.list"
                            :key="stage.id"
                            class="space-y-2"
                        >
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="flex h-9 min-w-0 flex-1 items-center gap-2 rounded px-3 text-start text-sm font-medium"
                                    :style="{
                                        backgroundColor: stage.color,
                                        color: readableOn(stage.color),
                                    }"
                                    :aria-expanded="editingStage === stage.id"
                                    @click="openStage(stage)"
                                >
                                    <span class="truncate"
                                        >{{ stage.position }}.
                                        {{ stage.name }}</span
                                    ><span
                                        v-if="!stage.active"
                                        class="text-xs opacity-80"
                                        >({{ t('inactive') }})</span
                                    >
                                </button>
                                <template v-if="section.sortable"
                                    ><button
                                        type="button"
                                        class="hover:bg-muted rounded p-1 disabled:opacity-30"
                                        :disabled="
                                            busy ||
                                            section.list[0].id === stage.id
                                        "
                                        :aria-label="`${t('Move up')}: ${stage.name}`"
                                        @click="
                                            reorder(section.list, stage, -1)
                                        "
                                    >
                                        <ArrowUp
                                            class="size-4"
                                            aria-hidden="true"
                                        /></button
                                    ><button
                                        type="button"
                                        class="hover:bg-muted rounded p-1 disabled:opacity-30"
                                        :disabled="
                                            busy ||
                                            section.list[
                                                section.list.length - 1
                                            ].id === stage.id
                                        "
                                        :aria-label="`${t('Move down')}: ${stage.name}`"
                                        @click="reorder(section.list, stage, 1)"
                                    >
                                        <ArrowDown
                                            class="size-4"
                                            aria-hidden="true"
                                        /></button
                                ></template>
                            </div>
                            <form
                                v-if="editingStage === stage.id"
                                class="grid gap-3 rounded-md border p-4 sm:grid-cols-2"
                                @submit.prevent="saveStage"
                            >
                                <div class="space-y-1">
                                    <Label :for="`stage-name-${stage.id}`">{{
                                        t('Name')
                                    }}</Label
                                    ><Input
                                        :id="`stage-name-${stage.id}`"
                                        v-model="stageForm.name"
                                        required
                                        maxlength="100"
                                    /><InputError :message="fieldErrors.name" />
                                </div>
                                <div class="space-y-1">
                                    <Label :for="`stage-type-${stage.id}`">{{
                                        t('Stage type')
                                    }}</Label
                                    ><select
                                        :id="`stage-type-${stage.id}`"
                                        v-model="stageForm.type"
                                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                    >
                                        <option value="normal">
                                            {{ t('Normal') }}
                                        </option>
                                        <option value="on_hold">
                                            {{ t('On hold') }}
                                        </option>
                                        <option value="won">
                                            {{ t('Won') }}
                                        </option>
                                        <option value="lost">
                                            {{ t('Lost') }}
                                        </option></select
                                    ><InputError :message="fieldErrors.type" />
                                </div>
                                <div class="space-y-1">
                                    <Label :for="`stage-color-${stage.id}`">{{
                                        t('Color')
                                    }}</Label
                                    ><Input
                                        :id="`stage-color-${stage.id}`"
                                        v-model="stageForm.color"
                                        type="color"
                                        class="h-9 p-1"
                                    />
                                </div>
                                <div
                                    class="flex flex-wrap items-center gap-4 text-sm"
                                >
                                    <label class="flex items-center gap-2"
                                        ><input
                                            v-model="stageForm.active"
                                            type="checkbox"
                                        />{{ t('Active') }}</label
                                    ><label class="flex items-center gap-2"
                                        ><input
                                            v-model="stageForm.is_initial"
                                            type="checkbox"
                                        />{{ t('Initial stage') }}</label
                                    >
                                </div>
                                <InputError
                                    :message="
                                        fieldErrors.is_initial ||
                                        fieldErrors.active
                                    "
                                    class="sm:col-span-2"
                                />
                                <div class="flex gap-2 sm:col-span-2">
                                    <Button type="submit" :disabled="busy">{{
                                        t('Save changes')
                                    }}</Button
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :disabled="busy"
                                        @click="removeStage(stage)"
                                        >{{ t('Delete unused') }}</Button
                                    >
                                </div>
                            </form>
                        </div>
                        <p
                            v-if="!section.list.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No stages yet.') }}
                        </p>
                        <button
                            v-if="section.add"
                            type="button"
                            class="text-primary inline-flex items-center gap-1 text-xs hover:underline"
                            @click="openStage(null, section.add)"
                        >
                            <Plus class="size-3.5" aria-hidden="true" />{{
                                t('Add stage')
                            }}
                        </button>
                    </section>
                </template>

                <div class="grid gap-4 lg:grid-cols-2">
                    <section
                        v-for="column in [
                            {
                                key: 'won',
                                label: 'Success stage',
                                list: groups.won as DealStageRow[],
                                add: 'won' as const,
                            },
                            {
                                key: 'lost',
                                label: 'Failed stages',
                                list: groups.lost as DealStageRow[],
                                add: 'lost' as const,
                            },
                        ]"
                        :key="column.key"
                        class="bg-card space-y-2 rounded-md border p-3"
                        :aria-label="t(column.label)"
                    >
                        <h2 class="text-eyebrow">{{ t(column.label) }}</h2>
                        <div
                            v-for="stage in column.list"
                            :key="stage.id"
                            class="space-y-2"
                        >
                            <button
                                type="button"
                                class="flex h-9 w-full items-center gap-2 rounded px-3 text-start text-sm font-medium"
                                :style="{
                                    backgroundColor: stage.color,
                                    color: readableOn(stage.color),
                                }"
                                :aria-expanded="editingStage === stage.id"
                                @click="openStage(stage)"
                            >
                                <span class="truncate"
                                    >{{ stage.position }}.
                                    {{ stage.name }}</span
                                ><span
                                    v-if="!stage.active"
                                    class="text-xs opacity-80"
                                    >({{ t('inactive') }})</span
                                >
                            </button>
                            <form
                                v-if="editingStage === stage.id"
                                class="grid gap-3 rounded-md border p-4"
                                @submit.prevent="saveStage"
                            >
                                <div class="space-y-1">
                                    <Label :for="`stage-name-${stage.id}`">{{
                                        t('Name')
                                    }}</Label
                                    ><Input
                                        :id="`stage-name-${stage.id}`"
                                        v-model="stageForm.name"
                                        required
                                        maxlength="100"
                                    /><InputError :message="fieldErrors.name" />
                                </div>
                                <div class="space-y-1">
                                    <Label :for="`stage-color-${stage.id}`">{{
                                        t('Color')
                                    }}</Label
                                    ><Input
                                        :id="`stage-color-${stage.id}`"
                                        v-model="stageForm.color"
                                        type="color"
                                        class="h-9 p-1"
                                    />
                                </div>
                                <label class="flex items-center gap-2 text-sm"
                                    ><input
                                        v-model="stageForm.active"
                                        type="checkbox"
                                    />{{ t('Active') }}</label
                                >
                                <div class="flex gap-2">
                                    <Button type="submit" :disabled="busy">{{
                                        t('Save changes')
                                    }}</Button
                                    ><Button
                                        type="button"
                                        variant="outline"
                                        :disabled="busy"
                                        @click="removeStage(stage)"
                                        >{{ t('Delete unused') }}</Button
                                    >
                                </div>
                            </form>
                        </div>
                        <p
                            v-if="!column.list.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No stages yet.') }}
                        </p>
                        <button
                            type="button"
                            class="text-primary inline-flex items-center gap-1 text-xs hover:underline"
                            @click="openStage(null, column.add)"
                        >
                            <Plus class="size-3.5" aria-hidden="true" />{{
                                t('Add stage')
                            }}
                        </button>
                    </section>
                </div>

                <form
                    v-if="editingStage === 'new'"
                    class="bg-card grid gap-3 rounded-md border p-4 sm:grid-cols-2"
                    @submit.prevent="saveStage"
                >
                    <h3 class="text-eyebrow sm:col-span-2">
                        {{ t('New stage') }}
                    </h3>
                    <div class="space-y-1">
                        <Label for="new-stage-name">{{ t('Name') }}</Label
                        ><Input
                            id="new-stage-name"
                            v-model="stageForm.name"
                            required
                            maxlength="100"
                        /><InputError :message="fieldErrors.name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="new-stage-type">{{ t('Stage type') }}</Label
                        ><select
                            id="new-stage-type"
                            v-model="stageForm.type"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option value="normal">{{ t('Normal') }}</option>
                            <option value="on_hold">{{ t('On hold') }}</option>
                            <option value="won">{{ t('Won') }}</option>
                            <option value="lost">{{ t('Lost') }}</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="new-stage-color">{{ t('Color') }}</Label
                        ><Input
                            id="new-stage-color"
                            v-model="stageForm.color"
                            type="color"
                            class="h-9 p-1"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="stageForm.active" type="checkbox" />{{
                            t('Active')
                        }}</label
                    >
                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="busy">{{
                            t('Add stage')
                        }}</Button
                        ><Button
                            type="button"
                            variant="outline"
                            @click="editingStage = null"
                            >{{ t('Cancel') }}</Button
                        >
                    </div>
                </form>

                <CrmPipelinePreview :stages="selected.stages" />
            </div>
            <p v-else class="text-muted-foreground text-sm">
                {{ t('No deal pipelines yet. Add one to get started.') }}
            </p>
        </div>
    </div>
</template>
