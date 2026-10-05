<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, GripVertical, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrmConfigurationEditor from '@/components/CrmConfigurationEditor.vue';
import CrmStageAssignmentEditor from '@/components/CrmStageAssignmentEditor.vue';
import CrmStageFollowUpEditor from '@/components/CrmStageFollowUpEditor.vue';
import CrmStageNotificationEditor from '@/components/CrmStageNotificationEditor.vue';
import CrmStageRulesEditor from '@/components/CrmStageRulesEditor.vue';
import { useLocale } from '@/composables/useLocale';
import { groupStages, moveWithin } from '@/lib/crm-pipeline-editor';
import { readableOn } from '@/lib/crm-pipeline-board';
import type { Pipeline, Stage } from '@/types/crm-pipeline';

const props = defineProps<{
    pipeline: Pipeline;
    members: { id: number; name: string }[];
}>();
const { t } = useLocale();
const groups = computed(() => groupStages(props.pipeline.stages));
const openId = ref<number | null>(null);
const adding = ref<'normal' | 'lost' | 'won' | null>(null);
const dragId = ref<number | null>(null);
const announcement = ref('');
const busy = ref(false);

function toggle(stage: Stage): void {
    adding.value = null;
    openId.value = openId.value === stage.id ? null : stage.id;
}
function startAdd(kind: 'normal' | 'lost' | 'won'): void {
    openId.value = null;
    adding.value = adding.value === kind ? null : kind;
}
function persist(list: Stage[], stageId: number, to: number): void {
    const changes = moveWithin(list, stageId, to);
    if (!changes.length || busy.value) {
        return;
    }
    busy.value = true;
    const queue = [...changes];
    const next = (): void => {
        const change = queue.shift();
        if (!change) {
            busy.value = false;
            announcement.value = t('Stage order saved.');

            return;
        }
        const stage = props.pipeline.stages.find(
            (item) => item.id === change.id,
        );
        if (!stage) {
            next();

            return;
        }
        router.put(
            `/crm/pipelines/${props.pipeline.id}/stages/${stage.id}`,
            {
                name: stage.name,
                description: stage.description ?? '',
                active: stage.active,
                position: change.position,
                type: stage.type,
                color: stage.color,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: next,
                onError: () => (busy.value = false),
            },
        );
    };
    next();
}
function move(list: Stage[], stage: Stage, delta: number): void {
    persist(
        list,
        stage.id,
        list.findIndex((item) => item.id === stage.id) + delta,
    );
}
function drop(list: Stage[], target: Stage): void {
    if (dragId.value !== null && dragId.value !== target.id) {
        persist(
            list,
            dragId.value,
            list.findIndex((item) => item.id === target.id),
        );
    }
    dragId.value = null;
}
</script>

<template>
    <div class="space-y-6">
        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <template
            v-for="section in [
                {
                    key: 'initial',
                    label: 'Initial stage',
                    list: groups.initial ? [groups.initial] : [],
                    sortable: false,
                    add: null,
                },
                {
                    key: 'additional',
                    label: 'Additional stages',
                    list: groups.additional,
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
                <h3 class="text-eyebrow">{{ t(section.label) }}</h3>
                <div
                    v-for="stage in section.list"
                    :key="stage.id"
                    :draggable="section.sortable && !busy"
                    class="space-y-2"
                    @dragstart="dragId = stage.id"
                    @dragover.prevent
                    @drop.prevent="drop(section.list, stage)"
                    @dragend="dragId = null"
                >
                    <div
                        class="flex items-center gap-1"
                        :class="{ 'opacity-50': dragId === stage.id }"
                    >
                        <GripVertical
                            v-if="section.sortable"
                            class="text-muted-foreground size-4 shrink-0 cursor-grab"
                            aria-hidden="true"
                        />
                        <button
                            type="button"
                            class="flex h-9 min-w-0 flex-1 items-center gap-2 rounded px-3 text-start text-sm font-medium"
                            :style="{
                                backgroundColor: stage.color,
                                color: readableOn(stage.color),
                            }"
                            :aria-expanded="openId === stage.id"
                            @click="toggle(stage)"
                        >
                            <span class="truncate"
                                >{{ stage.position }}. {{ stage.name }}</span
                            >
                            <span
                                v-if="!stage.active"
                                class="text-xs opacity-80"
                                >({{ t('inactive') }})</span
                            >
                        </button>
                        <template v-if="section.sortable">
                            <button
                                type="button"
                                class="hover:bg-muted rounded p-1 disabled:opacity-30"
                                :disabled="
                                    busy || section.list[0].id === stage.id
                                "
                                :aria-label="`${t('Move up')}: ${stage.name}`"
                                @click="move(section.list, stage, -1)"
                            >
                                <ArrowUp class="size-4" aria-hidden="true" />
                            </button>
                            <button
                                type="button"
                                class="hover:bg-muted rounded p-1 disabled:opacity-30"
                                :disabled="
                                    busy ||
                                    section.list[section.list.length - 1].id ===
                                        stage.id
                                "
                                :aria-label="`${t('Move down')}: ${stage.name}`"
                                @click="move(section.list, stage, 1)"
                            >
                                <ArrowDown class="size-4" aria-hidden="true" />
                            </button>
                        </template>
                    </div>
                    <div v-if="openId === stage.id" class="space-y-4 ps-5">
                        <CrmConfigurationEditor
                            kind="stage"
                            :pipeline-id="pipeline.id"
                            :item="stage"
                        />
                        <details class="rounded-md border p-3">
                            <summary class="cursor-pointer text-sm font-medium">
                                {{ t('Stage behaviour') }}
                            </summary>
                            <div class="mt-3 space-y-3">
                                <CrmStageRulesEditor
                                    :pipeline="pipeline"
                                    :stage="stage"
                                />
                                <CrmStageNotificationEditor
                                    :pipeline-id="pipeline.id"
                                    :stage="stage"
                                />
                                <CrmStageFollowUpEditor
                                    :pipeline-id="pipeline.id"
                                    :stage="stage"
                                />
                                <CrmStageAssignmentEditor
                                    :pipeline-id="pipeline.id"
                                    :stage="stage"
                                    :members="members"
                                />
                            </div>
                        </details>
                    </div>
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
                    :aria-expanded="adding === section.add"
                    @click="startAdd(section.add)"
                >
                    <Plus class="size-3.5" aria-hidden="true" />{{
                        t('Add stage')
                    }}
                </button>
                <CrmConfigurationEditor
                    v-if="section.add && adding === section.add"
                    :key="`add-${section.key}`"
                    kind="stage"
                    :pipeline-id="pipeline.id"
                    :default-type="section.add"
                />
            </section>
        </template>

        <div class="relative">
            <p
                class="text-muted-foreground bg-background relative z-10 mx-auto w-fit px-3 text-xs font-semibold tracking-wide"
            >
                {{ t('FINAL') }}
            </p>
            <div
                class="bg-border absolute inset-x-0 top-1/2 h-px"
                aria-hidden="true"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section
                v-for="column in [
                    {
                        key: 'won',
                        label: 'Success stage',
                        tone: 'text-emerald-700',
                        list: groups.won,
                        add: 'won' as const,
                    },
                    {
                        key: 'lost',
                        label: 'Failed stages',
                        tone: 'text-destructive',
                        list: groups.lost,
                        add: 'lost' as const,
                    },
                ]"
                :key="column.key"
                class="bg-card space-y-2 rounded-md border p-3"
                :aria-label="t(column.label)"
            >
                <h3 class="text-eyebrow" :class="column.tone">
                    {{ t(column.label) }}
                </h3>
                <template v-for="stage in column.list" :key="stage.id">
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="flex h-9 min-w-0 flex-1 items-center gap-2 rounded px-3 text-start text-sm font-medium"
                            :style="{
                                backgroundColor: stage.color,
                                color: readableOn(stage.color),
                            }"
                            :aria-expanded="openId === stage.id"
                            @click="toggle(stage)"
                        >
                            <span class="truncate"
                                >{{ stage.position }}. {{ stage.name }}</span
                            >
                            <span
                                v-if="!stage.active"
                                class="text-xs opacity-80"
                                >({{ t('inactive') }})</span
                            >
                        </button>
                    </div>
                    <div v-if="openId === stage.id" class="space-y-4 ps-3">
                        <CrmConfigurationEditor
                            kind="stage"
                            :pipeline-id="pipeline.id"
                            :item="stage"
                        />
                    </div>
                </template>
                <p
                    v-if="!column.list.length"
                    class="text-muted-foreground text-sm"
                >
                    {{ t('No stages yet.') }}
                </p>
                <button
                    type="button"
                    class="text-primary inline-flex items-center gap-1 text-xs hover:underline"
                    :aria-expanded="adding === column.add"
                    @click="startAdd(column.add)"
                >
                    <Plus class="size-3.5" aria-hidden="true" />{{
                        t('Add stage')
                    }}
                </button>
                <CrmConfigurationEditor
                    v-if="adding === column.add"
                    :key="`add-${column.key}`"
                    kind="stage"
                    :pipeline-id="pipeline.id"
                    :default-type="column.add"
                />
            </section>
        </div>
    </div>
</template>
