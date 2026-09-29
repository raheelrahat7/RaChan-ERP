<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import CrmLeadStageEditor from '@/components/CrmLeadStageEditor.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    boardColumns,
    canMoveOnBoard,
    canDropOnStage,
} from '@/lib/crm-pipeline-board';
import type { BoardLead } from '@/lib/crm-pipeline-board';
import type { Pipeline, Stage } from '@/types/crm-pipeline';

const props = defineProps<{
    pipeline: Pipeline;
    leads: BoardLead[];
    canManage: boolean;
    stageCounts: { id: number; count: number }[];
}>();
const columns = computed(() => boardColumns(props.pipeline, props.leads));
const draggedId = ref<number | null>(null);
const hoveredStageId = ref<number | null>(null);
const pendingLead = ref<BoardLead | null>(null);
const pendingStageId = ref<number | null>(null);
const dialogOpen = ref(false);
const moving = ref(false);
const announcement = ref('');
let returnFocus: HTMLElement | null = null;
const draggedLead = computed(() =>
    props.leads.find((lead) => lead.id === draggedId.value),
);
function movable(lead: BoardLead): boolean {
    return canMoveOnBoard(props.pipeline, lead, props.canManage);
}
function accepts(stage: Stage): boolean {
    return (
        !!draggedLead.value &&
        canDropOnStage(
            props.pipeline,
            draggedLead.value,
            stage,
            props.canManage,
        )
    );
}
function openMove(
    lead: BoardLead,
    stageId: number | null,
    origin?: HTMLElement,
): void {
    if (!movable(lead)) return;
    pendingLead.value = { ...lead };
    pendingStageId.value = stageId;
    returnFocus = origin ?? returnFocus;
    dialogOpen.value = true;
}
function startDrag(lead: BoardLead, event: DragEvent): void {
    if (!movable(lead) || dialogOpen.value || !event.dataTransfer) {
        event.preventDefault();
        return;
    }
    draggedId.value = lead.id;
    returnFocus = (event.currentTarget as HTMLElement).querySelector('button');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(lead.id));
    announcement.value = `Moving ${lead.first_name} ${lead.last_name}. Drop on an active stage, then confirm.`;
}
function endDrag(): void {
    draggedId.value = null;
    hoveredStageId.value = null;
}
function dragOver(stage: Stage, event: DragEvent): void {
    if (!accepts(stage)) {
        hoveredStageId.value = stage.id;
        const reasons =
            draggedLead.value?.transitionOptions?.find(
                (option) => option.stage_id === stage.id,
            )?.reasons ?? [];
        if (reasons.length)
            announcement.value = `${stage.name}: ${reasons.join(' ')}`;
        return;
    }
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    hoveredStageId.value = stage.id;
}
function drop(stage: Stage, event: DragEvent): void {
    event.preventDefault();
    const lead = draggedLead.value;
    if (lead && accepts(stage)) openMove(lead, stage.id);
    endDrag();
}
function moved(stageId: number): void {
    const target = props.pipeline.stages.find((stage) => stage.id === stageId);
    announcement.value = `${pendingLead.value?.first_name} ${pendingLead.value?.last_name} stage updated${target ? ` to ${target.name}` : ''}.`;
    moving.value = false;
    dialogOpen.value = false;
}
function restoreFocus(event: Event): void {
    event.preventDefault();
    if (returnFocus?.isConnected) returnFocus.focus();
    else
        document.getElementById(`board-move-${pendingLead.value?.id}`)?.focus();
}
</script>
<template>
    <section aria-labelledby="pipeline-board-title" class="space-y-3">
        <h2 id="pipeline-board-title" class="font-semibold">
            {{ pipeline.name }} board
        </h2>
        <p id="pipeline-board-help" class="text-muted-foreground text-sm">
            Drag a lead to a stage and confirm the move, or use its Move button
            with a keyboard or touch screen. Counts show visible leads and the
            total for the selected pipeline and assignee.
        </p>
        <p v-if="!pipeline.active" class="text-muted-foreground text-sm">
            This pipeline is inactive. Leads remain visible; movement is
            unavailable.
        </p>
        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>
        <div
            tabindex="0"
            role="region"
            aria-label="Scrollable pipeline stage columns"
            aria-describedby="pipeline-board-help"
            class="focus-visible:outline-ring flex gap-4 overflow-x-auto rounded-md pb-4 focus-visible:outline-2"
        >
            <section
                v-for="column in columns"
                :key="column.stage.id"
                :aria-labelledby="`board-column-stage-${column.stage.id}`"
                class="bg-muted/30 w-72 shrink-0 rounded-md border p-3"
                :class="{
                    'ring-ring ring-2':
                        hoveredStageId === column.stage.id &&
                        accepts(column.stage),
                }"
                :style="{ borderTop: `4px solid ${column.stage.color}` }"
                @dragover="dragOver(column.stage, $event)"
                @dragleave="hoveredStageId = null"
                @drop="drop(column.stage, $event)"
            >
                <h3
                    :id="`board-column-stage-${column.stage.id}`"
                    class="font-medium"
                >
                    {{ column.stage.name }}
                    <span
                        v-if="!column.stage.active"
                        class="text-muted-foreground text-sm"
                        >(inactive)</span
                    >
                </h3>
                <p class="text-muted-foreground mb-3 text-sm">
                    {{ column.leads.length }} shown ·
                    {{
                        stageCounts.find(
                            (count) => count.id === column.stage.id,
                        )?.count ?? 0
                    }}
                    total
                </p>
                <div
                    v-if="
                        draggedLead &&
                        hoveredStageId === column.stage.id &&
                        !accepts(column.stage)
                    "
                    class="mb-3 text-sm"
                    role="status"
                >
                    <p
                        v-for="reason in draggedLead.transitionOptions?.find(
                            (option) => option.stage_id === column.stage.id,
                        )?.reasons ?? []"
                        :key="reason"
                    >
                        {{ reason }}
                    </p>
                </div>
                <p
                    v-if="!column.leads.length"
                    class="text-muted-foreground rounded-md border border-dashed p-4 text-sm"
                >
                    No leads in the current filters.
                </p>
                <article
                    v-for="lead in column.leads"
                    :key="lead.id"
                    :draggable="movable(lead) && !dialogOpen"
                    class="bg-background mb-3 space-y-2 rounded-md border p-3"
                    :class="{
                        'cursor-grab': movable(lead) && !dialogOpen,
                        'opacity-50': draggedId === lead.id,
                    }"
                    @dragstart="startDrag(lead, $event)"
                    @dragend="endDrag"
                >
                    <h4 class="font-medium">
                        <Link
                            :href="`/crm/leads/${lead.id}`"
                            class="hover:underline"
                            >{{ lead.first_name }} {{ lead.last_name }}</Link
                        >
                    </h4>
                    <p class="text-muted-foreground text-sm break-words">
                        {{
                            lead.company ||
                            lead.email ||
                            lead.phone ||
                            'No contact details'
                        }}
                    </p>
                    <p class="text-sm">
                        {{ lead.assignee?.name ?? 'Unassigned' }}
                    </p>
                    <Badge v-if="lead.converted" variant="secondary"
                        >Converted</Badge
                    >
                    <Button
                        v-if="movable(lead)"
                        :id="`board-move-${lead.id}`"
                        size="sm"
                        variant="outline"
                        :aria-label="`Move ${lead.first_name} ${lead.last_name} to another stage`"
                        @click="
                            openMove(
                                lead,
                                null,
                                $event.currentTarget as HTMLElement,
                            )
                        "
                        >Move</Button
                    >
                </article>
            </section>
        </div>
        <Dialog
            :open="dialogOpen"
            @update:open="
                (open) => {
                    if (!moving) dialogOpen = open;
                }
            "
        >
            <DialogContent
                @close-auto-focus="restoreFocus"
                @escape-key-down="
                    (event) => {
                        if (moving) event.preventDefault();
                    }
                "
                @interact-outside="
                    (event) => {
                        if (moving) event.preventDefault();
                    }
                "
            >
                <DialogHeader
                    ><DialogTitle
                        >Move {{ pendingLead?.first_name }}
                        {{ pendingLead?.last_name }}</DialogTitle
                    ><DialogDescription
                        >Select a destination and confirm. Lost stages require a
                        reason. Won movement keeps customer conversion as a
                        separate action.</DialogDescription
                    ></DialogHeader
                >
                <CrmLeadStageEditor
                    v-if="pendingLead"
                    :key="`${pendingLead.id}-${pendingLead.current_stage_id}-${pendingStageId}`"
                    :lead-id="pendingLead.id"
                    :current-stage-id="pendingLead.current_stage_id"
                    :transition-options="pendingLead.transitionOptions"
                    :pipeline="pipeline"
                    :initial-stage-id="pendingStageId"
                    id-prefix="board"
                    @moved="moved"
                    @processing="moving = $event"
                />
                <Button
                    variant="outline"
                    :disabled="moving"
                    @click="dialogOpen = false"
                    >{{ t('Cancel') }}</Button
                >
            </DialogContent>
        </Dialog>
    </section>
</template>
