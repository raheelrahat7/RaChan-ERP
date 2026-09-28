<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
import type { Pipeline, Stage } from '@/types/crm-pipeline';
const props = defineProps<{ pipeline: Pipeline; stage: Stage }>();
const form = useForm({
    restrict_transitions: props.stage.allowed_from_stage_ids != null,
    allowed_from_stage_ids:
        props.stage.allowed_from_stage_ids ?? ([] as number[]),
    restrict_roles: props.stage.entry_roles != null,
    entry_roles: props.stage.entry_roles ?? ([] as string[]),
    required_fields: props.stage.required_fields ?? ([] as string[]),
});
const roles = [
    { value: 'owner', label: 'Owner' },
    { value: 'administrator', label: 'Administrator' },
    { value: 'manager', label: 'Manager' },
];
const fields = [
    { value: 'first_name', label: 'First name' },
    { value: 'last_name', label: 'Last name' },
    { value: 'email', label: 'Email' },
    { value: 'phone', label: 'Phone' },
    { value: 'company', label: 'Company' },
    { value: 'source', label: 'Lead source' },
    { value: 'notes', label: 'Notes' },
];
function save(): void {
    form.put(
        `/crm/pipelines/${props.pipeline.id}/stages/${props.stage.id}/rules`,
        { preserveScroll: true },
    );
}
</script>
<template>
    <form class="mt-3 space-y-4 rounded-md border p-4" @submit.prevent="save">
        <h4 class="font-medium">Entry rules</h4>
        <fieldset class="space-y-2">
            <legend>Allowed source stages</legend>
            <label class="flex items-center gap-2"
                ><input
                    v-model="form.restrict_transitions"
                    type="checkbox"
                />Restrict entry to selected source stages</label
            >
            <p class="text-muted-foreground text-sm">
                With restrictions off, any stage may enter. With restrictions on
                and none selected, all movements into this stage are blocked.
                New lead creation has no source stage.
            </p>
            <div
                v-if="form.restrict_transitions"
                class="grid gap-2 sm:grid-cols-2"
            >
                <label
                    v-for="source in pipeline.stages.filter(
                        (source) => source.id !== stage.id,
                    )"
                    :key="source.id"
                    class="flex items-center gap-2"
                    ><input
                        v-model="form.allowed_from_stage_ids"
                        type="checkbox"
                        :value="source.id"
                    />{{ source.name }}</label
                >
            </div>
        </fieldset>
        <fieldset class="space-y-2">
            <legend>Roles permitted to enter</legend>
            <label class="flex items-center gap-2"
                ><input v-model="form.restrict_roles" type="checkbox" />Restrict
                entry to selected roles</label
            >
            <p class="text-muted-foreground text-sm">
                CRM management permission is always required. Owners and
                administrators follow these rules too. None selected blocks
                every role.
            </p>
            <div v-if="form.restrict_roles" class="flex flex-wrap gap-3">
                <label
                    v-for="role in roles"
                    :key="role.value"
                    class="flex items-center gap-2"
                    ><input
                        v-model="form.entry_roles"
                        type="checkbox"
                        :value="role.value"
                    />{{ role.label }}</label
                >
            </div>
        </fieldset>
        <fieldset class="space-y-2">
            <legend>Required lead details</legend>
            <div class="grid gap-2 sm:grid-cols-2">
                <label
                    v-for="field in fields"
                    :key="field.value"
                    class="flex items-center gap-2"
                    ><input
                        v-model="form.required_fields"
                        type="checkbox"
                        :value="field.value"
                    />{{ field.label }}</label
                >
            </div>
            <p class="text-muted-foreground text-sm">
                Required values must be present before entry, including customer
                conversion. These rules do not change leads already in the
                stage.
            </p>
        </fieldset>
        <div aria-live="polite">
            <InputError
                v-for="(error, field) in form.errors"
                :key="field"
                :message="error"
            />
        </div>
        <Button :disabled="form.processing">Save entry rules</Button>
    </form>
</template>
