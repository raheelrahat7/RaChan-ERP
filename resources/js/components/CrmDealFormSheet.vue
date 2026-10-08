<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
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
    commercialFields,
    commercialPayload,
    optionChoices,
    sharesTooHigh,
} from '@/lib/crm-deal-commercial';
import type { DealOption } from '@/lib/crm-deal-commercial';
import { creatablePipelines } from '@/lib/crm-deals';
import type { CategoryOption, Deal, DealPipeline } from '@/types/crm-deals';

type Prefill = {
    title?: string;
    first_name?: string | null;
    last_name?: string | null;
    email?: string | null;
    phone?: string | null;
    company?: string | null;
    source?: string | null;
};

const props = defineProps<{
    pipelines: DealPipeline[];
    categories: CategoryOption[];
    /** Present when editing an existing deal. */
    deal?: Deal | null;
    /** Present when creating a deal from a qualified lead. */
    leadId?: number | null;
    prefill?: Prefill;
    defaultPipelineId?: number | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [deal: Deal] }>();
const { t } = useLocale();

const editing = computed(() => !!props.deal);
const available = computed(() => creatablePipelines(props.pipelines));
const activeCategories = computed(() =>
    props.categories.filter(
        (category) => category.active || category.code === props.deal?.category,
    ),
);
const form = reactive({
    title: '',
    category: '',
    pipeline_id: 0 as number,
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company: '',
    source: '',
    amount: '',
    currency: 'AED',
    expected_close_date: '',
    assigned_to: '' as string | number,
    notes: '',
    ...commercialFields(),
});
const statusOptions = ref<DealOption[]>([]);
const scenarioOptions = ref<DealOption[]>([]);
const canSeeAmounts = computed(
    () => !props.deal || props.deal.permissions?.amount === true,
);
const statusChoices = computed(() =>
    optionChoices(statusOptions.value, props.deal?.deal_status),
);
const scenarioChoices = computed(() =>
    optionChoices(scenarioOptions.value, props.deal?.scenario),
);
const errors = ref<Record<string, string>>({});
const message = ref('');
const processing = ref(false);
const pipeline = computed(() =>
    props.pipelines.find((item) => item.id === Number(form.pipeline_id)),
);

function load(): void {
    errors.value = {};
    message.value = '';
    const deal = props.deal;
    const base = deal ?? props.prefill ?? {};
    Object.assign(form, {
        title: base.title ?? '',
        category: deal?.category ?? activeCategories.value[0]?.code ?? '',
        pipeline_id:
            deal?.pipeline_id ??
            props.defaultPipelineId ??
            available.value[0]?.id ??
            0,
        first_name: base.first_name ?? '',
        last_name: base.last_name ?? '',
        email: base.email ?? '',
        phone: base.phone ?? '',
        company: base.company ?? '',
        source: base.source ?? '',
        amount:
            deal?.amount !== undefined && deal?.amount !== null
                ? String(deal.amount)
                : '',
        currency: deal?.currency ?? 'AED',
        expected_close_date: deal?.expected_close_date ?? '',
        assigned_to: deal?.assigned_to ?? '',
        notes: deal?.notes ?? '',
        ...commercialFields(deal),
    });
}
async function loadOptions(): Promise<void> {
    if (statusOptions.value.length) {
        return;
    }
    try {
        const data = await apiJson<{
            dealStatusOptions?: DealOption[];
            dealScenarioOptions?: DealOption[];
        }>('/crm/deals/configuration');
        statusOptions.value = data.dealStatusOptions ?? [];
        scenarioOptions.value = data.dealScenarioOptions ?? [];
    } catch {
        // The commercial fields simply stay empty; the rest of the form still works.
    }
}
function payload(): Record<string, unknown> {
    const optional: Record<string, unknown> = {};
    for (const key of [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'source',
        'notes',
        'expected_close_date',
    ] as const) {
        optional[key] = form[key] === '' ? null : form[key];
    }
    if (form.amount !== '') {
        optional.amount = form.amount;
        optional.currency = form.currency.toUpperCase();
    } else if (editing.value) {
        optional.amount = null;
    }
    if (form.assigned_to !== '') {
        optional.assigned_to = Number(form.assigned_to);
    }

    const commercial = commercialPayload(form, canSeeAmounts.value);
    if (!editing.value) {
        for (const key of Object.keys(commercial)) {
            if (commercial[key] === null) {
                delete commercial[key];
            }
        }
    }

    return {
        title: form.title,
        category: form.category,
        ...optional,
        ...commercial,
    };
}
async function save(): Promise<void> {
    errors.value = {};
    message.value = '';
    if (sharesTooHigh(form)) {
        errors.value = {
            agent_share: t(
                'The co-broker and agent shares cannot add up to more than 100%.',
            ),
        };

        return;
    }
    processing.value = true;
    try {
        const result = props.deal
            ? await apiJson<{ deal: Deal }>(
                  `/crm/deals/${props.deal.id}`,
                  'PUT',
                  { ...payload(), expected_version: props.deal.version },
              )
            : props.leadId
              ? await apiJson<{ deal: Deal }>(
                    `/crm/leads/${props.leadId}/deal`,
                    'POST',
                    { ...payload(), pipeline_id: form.pipeline_id },
                )
              : await apiJson<{ deal: Deal }>('/crm/deals', 'POST', {
                    ...payload(),
                    pipeline_id: form.pipeline_id,
                });
        emit('saved', result.deal);
        open.value = false;
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.fieldErrors();
            message.value =
                error.status === 422 && !Object.keys(errors.value).length
                    ? error.message
                    : (errors.value.expected_version ?? '');
        } else {
            message.value = t('Something went wrong. Please try again.');
        }
    } finally {
        processing.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        load();
        void loadOptions();
    }
});
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    editing
                        ? t('Edit deal')
                        : leadId
                          ? t('Create deal from lead')
                          : t('New deal')
                }}</SheetTitle>
                <SheetDescription>{{
                    editing
                        ? t(
                              'Changes are checked against the latest version of the deal.',
                          )
                        : t('Fields marked * are required.')
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="crm-deal-form"
                class="flex-1 space-y-4 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <p v-if="message" class="text-destructive text-sm" role="alert">
                    {{ message }}
                </p>
                <div class="space-y-1">
                    <Label for="deal-title">{{ t('Deal title') }} *</Label
                    ><Input
                        id="deal-title"
                        v-model="form.title"
                        required
                        maxlength="255"
                    /><InputError :message="errors.title" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="deal-category">{{ t('Type') }} *</Label>
                        <select
                            id="deal-category"
                            v-model="form.category"
                            required
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option
                                v-for="category in activeCategories"
                                :key="category.code"
                                :value="category.code"
                            >
                                {{ category.name }}
                            </option></select
                        ><InputError :message="errors.category" />
                    </div>
                    <div v-if="!editing" class="space-y-1">
                        <Label for="deal-pipeline">{{ t('Pipeline') }} *</Label>
                        <select
                            id="deal-pipeline"
                            v-model="form.pipeline_id"
                            required
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option
                                v-for="item in available"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option></select
                        ><InputError :message="errors.pipeline_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-first">{{ t('First name') }}</Label
                        ><Input
                            id="deal-first"
                            v-model="form.first_name"
                        /><InputError :message="errors.first_name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-last">{{ t('Last name') }}</Label
                        ><Input
                            id="deal-last"
                            v-model="form.last_name"
                        /><InputError :message="errors.last_name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-email">{{ t('Email') }}</Label
                        ><Input
                            id="deal-email"
                            v-model="form.email"
                            type="email"
                        /><InputError :message="errors.email" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-phone">{{ t('Phone') }}</Label
                        ><Input
                            id="deal-phone"
                            v-model="form.phone"
                            type="tel"
                        /><InputError :message="errors.phone" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-company">{{ t('Company') }}</Label
                        ><Input
                            id="deal-company"
                            v-model="form.company"
                        /><InputError :message="errors.company" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-source">{{ t('Source') }}</Label
                        ><Input
                            id="deal-source"
                            v-model="form.source"
                        /><InputError :message="errors.source" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-amount">{{ t('Amount') }}</Label
                        ><Input
                            id="deal-amount"
                            v-model="form.amount"
                            type="number"
                            min="0"
                            step="0.01"
                        /><InputError :message="errors.amount" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-currency">{{ t('Currency') }}</Label
                        ><Input
                            id="deal-currency"
                            v-model="form.currency"
                            maxlength="3"
                        /><InputError :message="errors.currency" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-close">{{
                            t('Expected close date')
                        }}</Label
                        ><Input
                            id="deal-close"
                            v-model="form.expected_close_date"
                            type="date"
                        /><InputError :message="errors.expected_close_date" />
                    </div>
                    <div class="space-y-1">
                        <Label for="deal-assignee">{{
                            t('Responsible person')
                        }}</Label>
                        <select
                            id="deal-assignee"
                            v-model="form.assigned_to"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option value="">
                                {{ editing ? t('Unchanged') : t('Me') }}
                            </option>
                            <option
                                v-for="member in pipeline?.members ?? []"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option></select
                        ><InputError :message="errors.assigned_to" />
                    </div>
                </div>
                <fieldset class="space-y-3 rounded-md border p-3">
                    <legend class="px-1 text-sm font-medium">
                        {{ t('Commercial tracking') }}
                    </legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="deal-status">{{
                                t('Deal status')
                            }}</Label>
                            <select
                                id="deal-status"
                                v-model="form.deal_status"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="option in statusChoices"
                                    :key="option.code"
                                    :value="option.code"
                                >
                                    {{ option.name }}
                                </option></select
                            ><InputError :message="errors.deal_status" />
                        </div>
                        <div class="space-y-1">
                            <Label for="deal-scenario">{{
                                t('Scenario')
                            }}</Label>
                            <select
                                id="deal-scenario"
                                v-model="form.scenario"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="option in scenarioChoices"
                                    :key="option.code"
                                    :value="option.code"
                                >
                                    {{ option.name }}
                                </option></select
                            ><InputError :message="errors.scenario" />
                        </div>
                        <template v-if="canSeeAmounts">
                            <div class="space-y-1">
                                <Label for="deal-gross">{{
                                    t('Gross commission')
                                }}</Label
                                ><Input
                                    id="deal-gross"
                                    v-model="form.gross_commission"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                /><InputError
                                    :message="errors.gross_commission"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label for="deal-cobroker">{{
                                    t('Co-broker share (%)')
                                }}</Label
                                ><Input
                                    id="deal-cobroker"
                                    v-model="form.co_broker_share"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                /><InputError
                                    :message="errors.co_broker_share"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label for="deal-agent">{{
                                    t('Agent share (%)')
                                }}</Label
                                ><Input
                                    id="deal-agent"
                                    v-model="form.agent_share"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                /><InputError :message="errors.agent_share" />
                            </div>
                        </template>
                    </div>
                    <p class="text-muted-foreground text-xs">
                        {{
                            t(
                                'These are tracking and planning figures. They do not post payments or change finance records.',
                            )
                        }}
                    </p>
                </fieldset>
                <div class="space-y-1">
                    <Label for="deal-notes">{{ t('Notes') }}</Label
                    ><textarea
                        id="deal-notes"
                        v-model="form.notes"
                        rows="3"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    /><InputError :message="errors.notes" />
                </div>
            </form>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="crm-deal-form"
                    :disabled="processing || (!editing && !available.length)"
                    >{{ t('Save') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
