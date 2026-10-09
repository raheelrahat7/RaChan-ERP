<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    datesOrderError,
    hasChanges,
    projectForm,
    statusChoices,
    updateBody,
} from '@/lib/offplan-projects';
import type { ProjectRow, ProjectStatus } from '@/lib/offplan-projects';

const props = defineProps<{
    project: ProjectRow | null;
    statuses: ProjectStatus[];
    brokers: { id: number; name: string }[];
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const form = reactive(projectForm());
const errors = ref<Record<string, string>>({});
const message = ref('');
const processing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(form, projectForm(props.project));
        errors.value = {};
        message.value = '';
    }
});

async function save(): Promise<void> {
    if (!props.project) {
        return;
    }
    errors.value = {};
    message.value = '';
    if (datesOrderError(form)) {
        errors.value = {
            handover_on: t('Handover cannot be before the launch date.'),
        };

        return;
    }
    const body = updateBody(props.project, form);
    if (!hasChanges(body)) {
        open.value = false;

        return;
    }
    processing.value = true;
    try {
        await apiJson(
            `/real-estate/off-plan/projects/${props.project.id}`,
            'PUT',
            body,
        );
        emit('saved');
        open.value = false;
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.fieldErrors();
            message.value =
                errors.value.expected_version ??
                (Object.keys(errors.value).length ? '' : error.message);
        } else {
            message.value = t('Something went wrong. Please try again.');
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    t('Edit project')
                }}</SheetTitle>
                <SheetDescription
                    >{{ project?.code }} · {{ project?.name }}</SheetDescription
                >
            </SheetHeader>
            <form
                id="offplan-project-form"
                class="flex-1 space-y-4 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <p v-if="message" class="text-destructive text-sm" role="alert">
                    {{ message }}
                </p>
                <div class="space-y-1">
                    <Label for="op-status">{{ t('Workflow status') }}</Label>
                    <select
                        id="op-status"
                        v-model="form.workflow_status"
                        :class="selectClass"
                    >
                        <option
                            v-for="status in statusChoices(
                                statuses,
                                project?.workflow_status,
                            )"
                            :key="status.code"
                            :value="status.code"
                        >
                            {{ status.name }}
                        </option>
                    </select>
                    <InputError :message="errors.workflow_status" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="op-launch">{{ t('Launch date') }}</Label>
                        <Input
                            id="op-launch"
                            v-model="form.launch_on"
                            type="date"
                        />
                        <InputError :message="errors.launch_on" />
                    </div>
                    <div class="space-y-1">
                        <Label for="op-handover">{{
                            t('Handover date')
                        }}</Label>
                        <Input
                            id="op-handover"
                            v-model="form.handover_on"
                            type="date"
                        />
                        <InputError :message="errors.handover_on" />
                    </div>
                    <div class="space-y-1">
                        <Label for="op-completion">{{
                            t('Completion date')
                        }}</Label>
                        <Input
                            id="op-completion"
                            v-model="form.completion_on"
                            type="date"
                        />
                        <InputError :message="errors.completion_on" />
                    </div>
                    <div class="space-y-1">
                        <Label for="op-commission">{{
                            t('Commission rate %')
                        }}</Label>
                        <Input
                            id="op-commission"
                            v-model="form.commission_rate"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                        />
                        <InputError :message="errors.commission_rate" />
                    </div>
                </div>
                <div v-if="brokers.length" class="space-y-1">
                    <Label for="op-broker">{{ t('Assigned broker') }}</Label>
                    <select
                        id="op-broker"
                        v-model="form.assigned_broker_id"
                        :class="selectClass"
                    >
                        <option value="">—</option>
                        <option
                            v-for="broker in brokers"
                            :key="broker.id"
                            :value="String(broker.id)"
                        >
                            {{ broker.name }}
                        </option>
                    </select>
                    <InputError :message="errors.assigned_broker_id" />
                </div>
                <p v-else-if="project?.assigned_broker_name" class="text-sm">
                    {{ t('Assigned broker') }}:
                    {{ project.assigned_broker_name }}
                </p>
            </form>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="offplan-project-form"
                    :disabled="processing"
                    >{{ t('Save') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
