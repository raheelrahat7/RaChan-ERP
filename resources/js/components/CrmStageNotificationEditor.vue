<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { Stage } from '@/types/crm-pipeline';
const props = defineProps<{ pipelineId: number; stage: Stage }>();
const form = useForm({
    enabled: props.stage.notify_assignee_on_entry ?? false,
});
function save(): void {
    form.put(
        `/crm/pipelines/${props.pipelineId}/stages/${props.stage.id}/assignee-notification`,
        { preserveScroll: true },
    );
}
</script>

<template>
    <form class="mt-3 space-y-3 rounded-md border p-4" @submit.prevent="save">
        <h4 class="font-medium">Assignee notification</h4>
        <label class="flex items-start gap-2 text-sm"
            ><input v-model="form.enabled" type="checkbox" class="mt-1" /><span
                >Notify the assigned member in the app whenever this stage is
                entered.</span
            ></label
        >
        <p class="text-muted-foreground text-sm">
            Each stage entry creates at most one notification for the assignee
            at that time. Unassigned leads do not generate one. No email or
            external message is sent.
        </p>
        <InputError :message="form.errors.enabled" />
        <Button :disabled="form.processing">Save notification rule</Button>
    </form>
</template>
