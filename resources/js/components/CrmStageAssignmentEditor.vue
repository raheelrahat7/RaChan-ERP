<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { Stage } from '@/types/crm-pipeline';

const props = defineProps<{
    pipelineId: number;
    stage: Stage;
    members: { id: number; name: string }[];
}>();
const form = useForm({ member_ids: props.stage.assignment_member_ids ?? [] });

function save(): void {
    form.put(
        `/crm/pipelines/${props.pipelineId}/stages/${props.stage.id}/assignment-rule`,
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <form class="mt-3 space-y-3 rounded-md border p-4" @submit.prevent="save">
        <h4 class="font-medium">Automatic assignment</h4>
        <p class="text-muted-foreground text-sm">
            Select members for round-robin assignment when an unassigned lead
            enters this stage. The initial stage also controls assignment when a
            lead is created. Only members checked in today and below their
            active-lead quota can receive a lead. Specific form, campaign and
            project routes take priority. Existing assignees stay unchanged.
            Clear all selections to turn this default rule off.
        </p>
        <div v-if="members.length" class="grid gap-2 sm:grid-cols-2">
            <label
                v-for="member in members"
                :key="member.id"
                class="flex items-center gap-2 text-sm"
            >
                <input
                    v-model="form.member_ids"
                    type="checkbox"
                    :value="member.id"
                />
                <span>{{ member.name }}</span>
            </label>
        </div>
        <p v-else class="text-muted-foreground text-sm">
            Add organization members to use this rule.
        </p>
        <InputError :message="form.errors.member_ids" />
        <Button :disabled="form.processing">Save assignment rule</Button>
    </form>
</template>
