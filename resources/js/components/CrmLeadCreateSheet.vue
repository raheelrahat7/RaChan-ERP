<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CrmLeadStageBar from '@/components/CrmLeadStageBar.vue';
import CustomLeadFields from '@/components/CustomLeadFields.vue';
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
import type { Pipeline } from '@/types/crm-pipeline';

type FieldValue = string | number | boolean | string[] | null;
type CustomField = {
    id: number;
    key: string;
    name: string;
    type: string;
    options: string[] | null;
    required: boolean;
};

const props = defineProps<{
    pipelines: Pipeline[];
    pipelineId: number | null;
    members: { id: number; name: string }[];
    customFields: CustomField[];
    userId: number | null;
}>();
const open = defineModel<boolean>('open', { required: true });

const { t } = useLocale();
const showSource = ref(false);
const activePipelines = computed(() =>
    props.pipelines.filter((pipeline) => pipeline.active),
);
const form = useForm({
    pipeline_id: (props.pipelineId ??
        props.pipelines.find((pipeline) => pipeline.is_default)?.id ??
        null) as number | null,
    // A manually created lead belongs to its creator unless someone else is chosen.
    assigned_to: props.userId !== null ? String(props.userId) : '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company: '',
    city: '',
    source: '',
    project_name: '',
    campaign_name: '',
    meta_form_id: '',
    meta_form_name: '',
    notes: '',
    custom_fields: {} as Record<string, FieldValue>,
});
const pipeline = computed(() =>
    props.pipelines.find((item) => item.id === Number(form.pipeline_id)),
);
const initialStageId = computed(
    () =>
        pipeline.value?.stages.find(
            (stage) =>
                stage.is_initial && stage.active && stage.type === 'normal',
        )?.id ?? 0,
);
const customErrors = computed(() =>
    Object.entries(form.errors).filter(([key]) =>
        key.startsWith('custom_fields.'),
    ),
);

function error(field: keyof typeof form.errors): string | undefined {
    return form.errors[field];
}
function close(): void {
    if (form.isDirty && !confirm(t('Discard this new lead?'))) {
        return;
    }
    form.reset();
    form.clearErrors();
    open.value = false;
}
function requestOpen(value: boolean): void {
    if (value) {
        open.value = true;
    } else {
        close();
    }
}
function save(): void {
    form.post('/crm/leads', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}

watch(open, (isOpen) => {
    if (isOpen && props.pipelineId !== null && !form.isDirty) {
        form.pipeline_id = props.pipelineId;
        form.defaults('pipeline_id', props.pipelineId);
    }
});
</script>

<template>
    <Sheet :open="open" @update:open="requestOpen">
        <SheetContent class="w-full gap-0 sm:max-w-2xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    t('New lead')
                }}</SheetTitle>
                <SheetDescription>{{
                    t('Fields marked * are required.')
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="crm-create-lead"
                class="flex-1 space-y-6 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <section class="space-y-2">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div class="space-y-1">
                            <Label for="new-lead-pipeline">{{
                                t('Pipeline')
                            }}</Label>
                            <select
                                id="new-lead-pipeline"
                                v-model="form.pipeline_id"
                                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                required
                            >
                                <option
                                    v-for="item in activePipelines"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.name }}
                                </option>
                            </select>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{ t('The lead starts in the highlighted stage.') }}
                        </p>
                    </div>
                    <CrmLeadStageBar
                        v-if="pipeline"
                        :lead-id="0"
                        :pipeline="pipeline"
                        :current-stage-id="initialStageId"
                        :transition-options="[]"
                        :can-move="false"
                    />
                    <InputError :message="error('pipeline_id')" />
                </section>

                <section class="space-y-4">
                    <h3 class="text-eyebrow">{{ t('General') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="new-lead-first"
                                >{{ t('First name') }} *</Label
                            >
                            <Input
                                id="new-lead-first"
                                v-model="form.first_name"
                                required
                                autocomplete="off"
                            />
                            <InputError :message="error('first_name')" />
                        </div>
                        <div class="space-y-1">
                            <Label for="new-lead-last"
                                >{{ t('Last name') }} *</Label
                            >
                            <Input
                                id="new-lead-last"
                                v-model="form.last_name"
                                required
                                autocomplete="off"
                            />
                            <InputError :message="error('last_name')" />
                        </div>
                        <div class="space-y-1">
                            <Label for="new-lead-email">{{ t('Email') }}</Label>
                            <Input
                                id="new-lead-email"
                                v-model="form.email"
                                type="email"
                            />
                            <InputError :message="error('email')" />
                        </div>
                        <div class="space-y-1">
                            <Label for="new-lead-phone">{{ t('Phone') }}</Label>
                            <Input
                                id="new-lead-phone"
                                v-model="form.phone"
                                type="tel"
                            />
                            <InputError :message="error('phone')" />
                        </div>
                        <div class="space-y-1">
                            <Label for="new-lead-company">{{
                                t('Company')
                            }}</Label>
                            <Input
                                id="new-lead-company"
                                v-model="form.company"
                            />
                            <InputError :message="error('company')" />
                        </div>
                        <div class="space-y-1">
                            <Label for="new-lead-city">{{ t('City') }}</Label>
                            <Input id="new-lead-city" v-model="form.city" />
                            <InputError :message="error('city')" />
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <Label for="new-lead-responsible">{{
                                t('Responsible person')
                            }}</Label>
                            <select
                                id="new-lead-responsible"
                                v-model="form.assigned_to"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                :disabled="members.length <= 1"
                            >
                                <option v-if="userId === null" value="">
                                    {{ t('Assign automatically') }}
                                </option>
                                <option
                                    v-for="member in members"
                                    :key="member.id"
                                    :value="String(member.id)"
                                >
                                    {{ member.name
                                    }}{{
                                        member.id === userId
                                            ? ` (${t('You')})`
                                            : ''
                                    }}
                                </option>
                            </select>
                            <InputError :message="error('assigned_to')" />
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <Label for="new-lead-notes">{{ t('Notes') }}</Label>
                            <textarea
                                id="new-lead-notes"
                                v-model="form.notes"
                                maxlength="5000"
                                rows="3"
                                class="border-input bg-background w-full rounded-md border p-2 text-sm"
                            />
                            <InputError :message="error('notes')" />
                        </div>
                    </div>
                </section>

                <section class="space-y-3">
                    <button
                        type="button"
                        class="text-eyebrow flex w-full items-center justify-between"
                        :aria-expanded="showSource"
                        aria-controls="new-lead-source-fields"
                        @click="showSource = !showSource"
                    >
                        {{ t('Source and campaign') }}
                        <ChevronDown
                            class="size-4 transition-transform"
                            :class="{ 'rotate-180': showSource }"
                            aria-hidden="true"
                        />
                    </button>
                    <div
                        v-show="showSource"
                        id="new-lead-source-fields"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <div class="space-y-1">
                            <Label for="new-lead-source">{{
                                t('Source')
                            }}</Label>
                            <Input
                                id="new-lead-source"
                                v-model="form.source"
                                placeholder="Referral, web, campaign…"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="new-lead-project">{{
                                t('Project')
                            }}</Label>
                            <Input
                                id="new-lead-project"
                                v-model="form.project_name"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="new-lead-campaign">{{
                                t('Campaign')
                            }}</Label>
                            <Input
                                id="new-lead-campaign"
                                v-model="form.campaign_name"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="new-lead-meta-id">{{
                                t('Meta form ID')
                            }}</Label>
                            <Input
                                id="new-lead-meta-id"
                                v-model="form.meta_form_id"
                            />
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <Label for="new-lead-meta-name">{{
                                t('Meta form name')
                            }}</Label>
                            <Input
                                id="new-lead-meta-name"
                                v-model="form.meta_form_name"
                            />
                        </div>
                    </div>
                </section>

                <section v-if="customFields.length" class="space-y-4">
                    <h3 class="text-eyebrow">{{ t('Custom fields') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <CustomLeadFields
                            v-model="form.custom_fields"
                            :fields="customFields"
                            :members="members"
                            prefix="new-lead"
                        />
                    </div>
                    <InputError
                        v-for="[key, message] in customErrors"
                        :key="key"
                        :message="message"
                    />
                </section>
            </form>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="close">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="crm-create-lead"
                    :disabled="form.processing"
                    >{{ t('Save') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
