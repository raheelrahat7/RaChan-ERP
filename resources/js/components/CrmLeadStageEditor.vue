<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import type { Pipeline, TransitionOption } from '@/types/crm-pipeline';
const props = defineProps<{
    leadId: number;
    currentStageId: number;
    pipeline: Pipeline;
    initialStageId?: number | null;
    idPrefix?: string;
    transitionOptions?: TransitionOption[];
}>();
const emit = defineEmits<{
    moved: [stageId: number];
    processing: [busy: boolean];
}>();
const prefix = props.idPrefix ?? 'list';
const currentStage = computed(() =>
    props.pipeline.stages.find((stage) => stage.id === props.currentStageId),
);
const availableStages = computed(() =>
    props.pipeline.stages.filter(
        (stage) =>
            stage.active &&
            stage.id !== props.currentStageId &&
            (currentStage.value?.type !== 'lost' ||
                !['won', 'lost'].includes(stage.type)),
    ),
);
const form = useForm({
    stage_id: props.initialStageId ? String(props.initialStageId) : '',
    expected_stage_id: props.currentStageId,
    lost_reason_id: '',
    notes: '',
});
const blockedReasons = computed(
    () =>
        props.transitionOptions?.find(
            (option) => option.stage_id === Number(form.stage_id),
        )?.reasons ?? [],
);
const isLost = computed(
    () =>
        props.pipeline.stages.find(
            (stage) => stage.id === Number(form.stage_id),
        )?.type === 'lost',
);
watch(
    () => form.processing,
    (busy) => emit('processing', busy),
);
function move(): void {
    const stageId = Number(form.stage_id);
    form.expected_stage_id = props.currentStageId;
    form.put(`/crm/leads/${props.leadId}/stage`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('stage_id', 'lost_reason_id', 'notes');
            emit('moved', stageId);
        },
    });
}
</script>
<template>
    <form
        class="flex w-full flex-wrap items-end gap-3 rounded-md border p-3"
        @submit.prevent="move"
    >
        <div>
            <Label :for="`${prefix}-stage-${leadId}`">Move to stage</Label
            ><select
                :id="`${prefix}-stage-${leadId}`"
                v-model="form.stage_id"
                class="h-9 rounded-md border px-3"
                required
            >
                <option value="" disabled>Select stage</option>
                <option
                    v-for="stage in availableStages"
                    :key="stage.id"
                    :value="stage.id"
                >
                    {{ stage.name
                    }}{{
                        transitionOptions?.find(
                            (option) => option.stage_id === stage.id,
                        )?.reasons.length
                            ? ' (blocked)'
                            : ''
                    }}
                </option>
            </select>
        </div>
        <div v-if="isLost">
            <Label :for="`${prefix}-reason-${leadId}`">Lost reason</Label
            ><select
                :id="`${prefix}-reason-${leadId}`"
                v-model="form.lost_reason_id"
                class="h-9 rounded-md border px-3"
                required
            >
                <option value="" disabled>Select reason</option>
                <option
                    v-for="reason in pipeline.reasons.filter(
                        (reason) => reason.active,
                    )"
                    :key="reason.id"
                    :value="reason.id"
                >
                    {{ reason.name }}
                </option>
            </select>
        </div>
        <div>
            <Label :for="`${prefix}-stage-notes-${leadId}`"
                >Notes (optional)</Label
            ><Input
                :id="`${prefix}-stage-notes-${leadId}`"
                v-model="form.notes"
                maxlength="2000"
            />
        </div>
        <div v-if="blockedReasons.length" class="w-full text-sm" role="status">
            <p v-for="reason in blockedReasons" :key="reason">{{ reason }}</p>
        </div>
        <Button
            size="sm"
            :disabled="
                form.processing || !pipeline.active || !!blockedReasons.length
            "
            >Move lead</Button
        >
        <div class="w-full" aria-live="polite">
            <InputError
                v-for="(error, field) in form.errors"
                :key="field"
                :message="error"
            />
        </div>
    </form>
</template>
