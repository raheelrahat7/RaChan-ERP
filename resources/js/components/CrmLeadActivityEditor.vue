<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { activityDateInput } from '@/lib/crm-activity-views';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
const props = defineProps<{
    timezone: string;
    activity: {
        id: number;
        type: string;
        notes: string | null;
        due_at: string | null;
        updated_at: string;
    };
}>();
const { t } = useLocale();
const editing = ref(false);
const form = useForm({
    type: props.activity.type,
    notes: props.activity.notes ?? '',
    due_at: activityDateInput(props.activity.due_at, props.timezone),
    expected_updated_at: props.activity.updated_at,
});
function edit(): void {
    form.type = props.activity.type;
    form.notes = props.activity.notes ?? '';
    form.due_at = activityDateInput(props.activity.due_at, props.timezone);
    form.expected_updated_at = props.activity.updated_at;
    form.clearErrors();
    editing.value = true;
}
function save(): void {
    form.transform((data) => ({ ...data, due_at: data.due_at || null })).put(
        `/crm/activities/${props.activity.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = false;
            },
        },
    );
}
</script>
<template>
    <form v-if="editing" class="mt-3 space-y-3" @submit.prevent="save">
        <Label :for="`activity-type-${activity.id}`">{{
            t('Activity type')
        }}</Label>
        <select
            :id="`activity-type-${activity.id}`"
            v-model="form.type"
            class="border-input bg-background h-9 w-full rounded-md border px-3"
        >
            <option
                v-for="type in ['call', 'email', 'meeting', 'task', 'note']"
                :key="type"
                :value="type"
            >
                {{ type }}
            </option>
        </select>
        <Label :for="`activity-notes-${activity.id}`">{{ t('Notes') }}</Label>
        <textarea
            :id="`activity-notes-${activity.id}`"
            v-model="form.notes"
            maxlength="5000"
            class="border-input bg-background min-h-20 w-full rounded-md border p-2"
        />
        <Label :for="`activity-due-${activity.id}`">{{ t('Due date') }}</Label>
        <Input
            :id="`activity-due-${activity.id}`"
            v-model="form.due_at"
            type="datetime-local"
            step="1"
        />
        <p class="text-muted-foreground text-xs">
            Time zone: {{ timezone }}. Clear to remove the deadline.
        </p>
        <div aria-live="polite">
            <InputError
                v-for="(error, key) in form.errors"
                :key="key"
                :message="error"
            />
        </div>
        <Button :disabled="form.processing">{{ t('Save') }}</Button>
        <Button
            type="button"
            variant="ghost"
            :disabled="form.processing"
            @click="editing = false"
            >{{ t('Cancel') }}</Button
        >
    </form>
    <div v-else class="mt-3 flex gap-2">
        <Button size="sm" variant="outline" @click="edit">{{
            t('Edit')
        }}</Button>
        <Button
            size="sm"
            variant="outline"
            @click="
                router.post(
                    `/crm/activities/${activity.id}/complete`,
                    {},
                    { preserveScroll: true },
                )
            "
            >{{ t('Complete') }}</Button
        >
    </div>
</template>
