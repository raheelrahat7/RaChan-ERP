<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';

type Route = {
    id: number;
    match_type: string;
    match_value: string;
    target_type: string;
    target_id: number | null;
    member_ids: number[] | null;
    active: boolean;
};
type Option = { id: number; name: string };
const props = defineProps<{
    route?: Route;
    members: Option[];
    departments: Option[];
    subdepartments: Option[];
    teams: Option[];
}>();
const form = useForm({
    match_type: props.route?.match_type ?? 'meta_form_id',
    match_value: props.route?.match_value ?? '',
    target_type: props.route?.target_type ?? 'members',
    target_id: props.route?.target_id ?? null,
    member_ids: props.route?.member_ids ?? ([] as number[]),
    active: props.route?.active ?? true,
});
function options(): Option[] {
    if (form.target_type === 'department') return props.departments;
    if (form.target_type === 'subdepartment') return props.subdepartments;
    if (form.target_type === 'team') return props.teams;
    return [];
}
function save(): void {
    const url = props.route
        ? `/crm/assignment/routes/${props.route.id}`
        : '/crm/assignment/routes';
    if (props.route) form.put(url, { preserveScroll: true });
    else
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
}
function remove(): void {
    if (props.route && confirm('Delete this assignment route?'))
        router.delete(`/crm/assignment/routes/${props.route.id}`, {
            preserveScroll: true,
        });
}
</script>

<template>
    <form class="space-y-3 rounded-md border p-4" @submit.prevent="save">
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="space-y-1 text-sm">
                <span>Match by</span>
                <select
                    v-model="form.match_type"
                    class="h-9 w-full rounded-md border px-3"
                >
                    <option value="meta_form_id">Meta form ID</option>
                    <option value="meta_form_name">Meta form name</option>
                    <option value="campaign">Campaign name</option>
                    <option value="project">Project name</option>
                </select>
            </label>
            <label class="space-y-1 text-sm">
                <span>Exact match value</span>
                <Input v-model="form.match_value" required maxlength="255" />
            </label>
            <label class="space-y-1 text-sm">
                <span>Assign within</span>
                <select
                    v-model="form.target_type"
                    class="h-9 w-full rounded-md border px-3"
                >
                    <option value="members">Selected members</option>
                    <option value="department">Department</option>
                    <option value="subdepartment">Subdepartment</option>
                    <option value="team">Team</option>
                </select>
            </label>
            <label
                v-if="form.target_type !== 'members'"
                class="space-y-1 text-sm"
            >
                <span>Destination</span>
                <select
                    v-model.number="form.target_id"
                    class="h-9 w-full rounded-md border px-3"
                    required
                >
                    <option :value="null">Select destination</option>
                    <option
                        v-for="option in options()"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.name }}
                    </option>
                </select>
            </label>
        </div>
        <div
            v-if="form.target_type === 'members'"
            class="grid gap-2 sm:grid-cols-2"
        >
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
                {{ member.name }}
            </label>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input v-model="form.active" type="checkbox" />{{
                t('Active')
            }}</label
        >
        <div aria-live="polite">
            <InputError
                v-for="(error, key) in form.errors"
                :key="key"
                :message="error"
            />
        </div>
        <div class="flex gap-2">
            <Button :disabled="form.processing">{{
                route ? 'Save route' : 'Add route'
            }}</Button>
            <Button
                v-if="route"
                type="button"
                variant="outline"
                @click="remove"
                >{{ t('Delete') }}</Button
            >
        </div>
    </form>
</template>
