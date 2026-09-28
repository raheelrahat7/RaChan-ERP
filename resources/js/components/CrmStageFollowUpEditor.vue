<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Stage } from '@/types/crm-pipeline';

const props = defineProps<{ pipelineId: number; stage: Stage }>();
const form = useForm({
    enabled: props.stage.follow_up_due_days != null,
    due_days: props.stage.follow_up_due_days ?? 0,
});
function save(): void {
    form.put(
        `/crm/pipelines/${props.pipelineId}/stages/${props.stage.id}/follow-up-rule`,
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <form class="mt-3 space-y-3 rounded-md border p-4" @submit.prevent="save">
        <h4 class="font-medium">Stage-entry follow-up</h4>
        <label class="flex items-start gap-2 text-sm">
            <input v-model="form.enabled" type="checkbox" class="mt-1" />
            <span
                >Create a follow-up task when an assigned lead enters this
                stage.</span
            >
        </label>
        <label v-if="form.enabled" class="block space-y-1 text-sm">
            <span>Due after days (0–365)</span>
            <Input
                v-model.number="form.due_days"
                type="number"
                min="0"
                max="365"
                required
            />
        </label>
        <InputError :message="form.errors.enabled" />
        <InputError :message="form.errors.due_days" />
        <p class="text-muted-foreground text-sm">
            Zero means due when the stage is entered. Each entry creates at most
            one task. Unassigned and converted leads are skipped. Tasks follow
            the lead's current assignee.
        </p>
        <Button :disabled="form.processing">Save follow-up rule</Button>
    </form>
</template>
