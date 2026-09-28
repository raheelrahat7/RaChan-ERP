<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { Pipeline, Stage } from '@/types/crm-pipeline';

const props = defineProps<{
    leadId: number;
    pipelineId: number;
    stageId: number;
    stageType: Stage['type'];
    pipelines: Pipeline[];
}>();
const form = useForm({
    pipeline_id: null as number | null,
    stage_id: null as number | null,
    expected_pipeline_id: props.pipelineId,
    expected_stage_id: props.stageId,
    lost_reason_id: null as number | null,
    notes: '',
    confirmed: false,
});
const destinations = computed(() =>
    props.pipelines.filter(
        (item) => item.active && item.id !== props.pipelineId,
    ),
);
const destination = computed(() =>
    destinations.value.find((item) => item.id === form.pipeline_id),
);
const stage = computed(() =>
    destination.value?.stages.find((item) => item.id === form.stage_id),
);
const stages = computed(
    () =>
        destination.value?.stages.filter(
            (item) =>
                item.active &&
                (props.stageType !== 'lost' ||
                    item.type === 'normal' ||
                    item.type === 'on_hold'),
        ) ?? [],
);
watch(
    () => form.pipeline_id,
    () => {
        form.stage_id = null;
        form.lost_reason_id = null;
        form.confirmed = false;
    },
);
watch(
    () => form.stage_id,
    () => {
        form.lost_reason_id = null;
        form.confirmed = false;
    },
);
function submit(): void {
    form.post(`/crm/leads/${props.leadId}/transfer`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-3 pt-3">
        <p class="text-muted-foreground text-sm">
            Choose the destination stage explicitly. Stage entry rules apply.
            Moving to Won does not create a customer; customer conversion
            remains a separate action.
        </p>
        <p v-if="!destinations.length" class="text-muted-foreground text-sm">
            Create another active pipeline before transferring this lead.
        </p>
        <form v-else class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
            <div>
                <Label :for="`transfer-pipeline-${leadId}`"
                    >Destination pipeline</Label
                ><select
                    :id="`transfer-pipeline-${leadId}`"
                    v-model="form.pipeline_id"
                    required
                    class="w-full rounded-md border p-2"
                >
                    <option :value="null" disabled>Select pipeline</option>
                    <option
                        v-for="item in destinations"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option></select
                ><InputError :message="form.errors.pipeline_id" />
            </div>
            <div>
                <Label :for="`transfer-stage-${leadId}`"
                    >Destination stage</Label
                ><select
                    :id="`transfer-stage-${leadId}`"
                    v-model="form.stage_id"
                    required
                    :disabled="!destination"
                    class="w-full rounded-md border p-2"
                >
                    <option :value="null" disabled>Select stage</option>
                    <option
                        v-for="item in stages"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }} ({{ item.type }})
                    </option></select
                ><InputError :message="form.errors.stage_id" />
            </div>
            <div v-if="stage?.type === 'lost'">
                <Label :for="`transfer-reason-${leadId}`">Lost reason</Label
                ><select
                    :id="`transfer-reason-${leadId}`"
                    v-model="form.lost_reason_id"
                    required
                    class="w-full rounded-md border p-2"
                >
                    <option :value="null" disabled>Select reason</option>
                    <option
                        v-for="item in destination?.reasons.filter(
                            (reason) => reason.active,
                        )"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option></select
                ><InputError :message="form.errors.lost_reason_id" />
            </div>
            <div class="sm:col-span-2">
                <Label :for="`transfer-notes-${leadId}`"
                    >Transfer notes (optional)</Label
                ><textarea
                    :id="`transfer-notes-${leadId}`"
                    v-model="form.notes"
                    maxlength="2000"
                    rows="2"
                    class="w-full rounded-md border p-2"
                /><InputError :message="form.errors.notes" />
            </div>
            <label class="flex items-start gap-2 text-sm sm:col-span-2"
                ><input
                    v-model="form.confirmed"
                    type="checkbox"
                    required
                    class="mt-1"
                /><span
                    >I confirm this lead should move to the selected pipeline
                    and stage.</span
                ></label
            ><InputError :message="form.errors.confirmed" />
            <Button class="w-fit" :disabled="form.processing || !form.confirmed"
                >Transfer lead</Button
            >
        </form>
    </div>
</template>
