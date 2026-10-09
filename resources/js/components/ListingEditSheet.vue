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
    detailForm,
    hasChanges,
    priceRangeError,
    statusChoices,
    updateBody,
} from '@/lib/listings';
import type { DetailForm, ListingRow, WorkflowStatus } from '@/lib/listings';

type Field = {
    key: keyof DetailForm;
    label: string;
    type?: 'text' | 'number' | 'date';
    step?: string;
};
type Person = { id: number; name: string };

const props = defineProps<{
    listing: ListingRow | null;
    secondary: boolean;
    workflowStatuses: WorkflowStatus[];
    owners: Person[];
    brokers: Person[];
    costCentres: Person[];
    buyerContacts: { id: number; first_name: string; last_name: string }[];
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const form = reactive<DetailForm>(detailForm());
const errors = ref<Record<string, string>>({});
const message = ref('');
const processing = ref(false);

const groups = computed<{ title: string; fields: Field[] }[]>(() => [
    {
        title: 'Classification',
        fields: [
            { key: 'listing_category', label: 'Listing category' },
            { key: 'unit_category', label: 'Unit category' },
            { key: 'grade', label: 'Grade' },
            { key: 'completion_status', label: 'Completion status' },
            { key: 'handover_date', label: 'Handover date', type: 'date' },
            { key: 'developer_name', label: 'Developer' },
        ],
    },
    {
        title: 'Location and permits',
        fields: [
            { key: 'emirate', label: 'Emirate' },
            { key: 'community', label: 'Community' },
            { key: 'sub_community', label: 'Sub-community' },
            { key: 'trakheesi_permit', label: 'Trakheesi permit' },
            { key: 'dld_permit', label: 'DLD permit' },
        ],
    },
    {
        title: 'Specifications',
        fields: [
            { key: 'bedroom_type', label: 'Bedroom type' },
            { key: 'bedrooms', label: 'Bedrooms', type: 'number' },
            { key: 'bathrooms', label: 'Bathrooms', type: 'number' },
            { key: 'balconies', label: 'Balconies', type: 'number' },
            { key: 'parking_spaces', label: 'Parking spaces', type: 'number' },
            { key: 'furnishing', label: 'Furnishing' },
            { key: 'fit_out', label: 'Fit-out' },
            {
                key: 'size_sqft',
                label: 'Size (sq ft)',
                type: 'number',
                step: '0.01',
            },
            {
                key: 'plot_size_sqft',
                label: 'Plot size (sq ft)',
                type: 'number',
                step: '0.01',
            },
        ],
    },
    {
        title: 'Pricing',
        fields: [
            { key: 'price', label: 'Price', type: 'number', step: '0.01' },
            { key: 'currency', label: 'Currency' },
            { key: 'price_type', label: 'Price type' },
            { key: 'price_label', label: 'Price label' },
            {
                key: 'price_min',
                label: 'Minimum price',
                type: 'number',
                step: '0.01',
            },
            {
                key: 'price_max',
                label: 'Maximum price',
                type: 'number',
                step: '0.01',
            },
        ],
    },
]);
const secondaryFields: Field[] = [
    {
        key: 'valuation_price',
        label: 'Valuation price',
        type: 'number',
        step: '0.01',
    },
    { key: 'mortgage_status', label: 'Mortgage status' },
    { key: 'noc_status', label: 'NOC status' },
    { key: 'transfer_status', label: 'Transfer status' },
];
const statuses = computed(() =>
    statusChoices(props.workflowStatuses, props.listing?.workflow_status ?? ''),
);

function load(): void {
    errors.value = {};
    message.value = '';
    Object.assign(form, detailForm(props.listing));
}

async function save(): Promise<void> {
    if (!props.listing) {
        return;
    }
    errors.value = {};
    message.value = '';
    if (priceRangeError(form)) {
        errors.value = {
            price_max: t('The maximum price cannot be below the minimum.'),
        };

        return;
    }
    const body = updateBody(props.listing, form, props.secondary);
    if (!hasChanges(body)) {
        open.value = false;

        return;
    }
    processing.value = true;
    try {
        await apiJson(`/real-estate/listings/${props.listing.id}`, 'PUT', body);
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

watch(open, (isOpen) => {
    if (isOpen) {
        load();
    }
});
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    t('Edit listing details')
                }}</SheetTitle>
                <SheetDescription>{{ listing?.reference }}</SheetDescription>
            </SheetHeader>
            <form
                id="listing-edit-form"
                class="flex-1 space-y-5 overflow-y-auto p-4"
                @submit.prevent="save"
            >
                <p v-if="message" class="text-destructive text-sm" role="alert">
                    {{ message }}
                </p>
                <fieldset class="space-y-3">
                    <legend class="text-eyebrow">{{ t('Status') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="ls-workflow">{{
                                t('Workflow status')
                            }}</Label>
                            <select
                                id="ls-workflow"
                                v-model="form.workflow_status"
                                :class="selectClass"
                            >
                                <option
                                    v-for="status in statuses"
                                    :key="status.code"
                                    :value="status.code"
                                >
                                    {{ status.name }}
                                </option>
                            </select>
                            <InputError :message="errors.workflow_status" />
                        </div>
                        <div class="space-y-1">
                            <Label for="ls-costcentre">{{
                                t('Cost centre')
                            }}</Label>
                            <select
                                id="ls-costcentre"
                                v-model="form.cost_centre_id"
                                :class="selectClass"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="centre in costCentres"
                                    :key="centre.id"
                                    :value="String(centre.id)"
                                >
                                    {{ centre.name }}
                                </option>
                            </select>
                            <InputError :message="errors.cost_centre_id" />
                        </div>
                        <div class="space-y-1">
                            <Label for="ls-owner">{{ t('Owner') }}</Label>
                            <select
                                id="ls-owner"
                                v-model="form.owner_id"
                                :class="selectClass"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="owner in owners"
                                    :key="owner.id"
                                    :value="String(owner.id)"
                                >
                                    {{ owner.name }}
                                </option>
                            </select>
                            <InputError :message="errors.owner_id" />
                        </div>
                        <div class="space-y-1">
                            <Label for="ls-broker">{{ t('Broker') }}</Label>
                            <select
                                id="ls-broker"
                                v-model="form.broker_id"
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
                            <InputError :message="errors.broker_id" />
                        </div>
                    </div>
                </fieldset>
                <fieldset
                    v-for="group in groups"
                    :key="group.title"
                    class="space-y-3"
                >
                    <legend class="text-eyebrow">{{ t(group.title) }}</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="field in group.fields"
                            :key="field.key"
                            class="space-y-1"
                        >
                            <Label :for="`ls-${field.key}`">{{
                                t(field.label)
                            }}</Label>
                            <Input
                                :id="`ls-${field.key}`"
                                v-model="form[field.key] as string"
                                :type="field.type ?? 'text'"
                                :step="field.step"
                                :min="field.type === 'number' ? 0 : undefined"
                            />
                            <InputError :message="errors[field.key]" />
                        </div>
                    </div>
                </fieldset>
                <fieldset class="space-y-3">
                    <legend class="text-eyebrow">{{ t('Marketing') }}</legend>
                    <div class="space-y-1">
                        <Label for="ls-portals">{{
                            t('Portals (comma separated)')
                        }}</Label>
                        <Input id="ls-portals" v-model="form.portals" />
                        <InputError :message="errors.portals" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="form.loading_bay" type="checkbox" />{{
                            t('Loading bay')
                        }}</label
                    >
                </fieldset>
                <fieldset v-if="secondary" class="space-y-3">
                    <legend class="text-eyebrow">
                        {{ t('Secondary market file') }}
                    </legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="field in secondaryFields"
                            :key="field.key"
                            class="space-y-1"
                        >
                            <Label :for="`ls-${field.key}`">{{
                                t(field.label)
                            }}</Label>
                            <Input
                                :id="`ls-${field.key}`"
                                v-model="form[field.key] as string"
                                :type="field.type ?? 'text'"
                                :step="field.step"
                            />
                            <InputError :message="errors[field.key]" />
                        </div>
                        <div class="space-y-1">
                            <Label for="ls-buyer">{{ t('Buyer') }}</Label>
                            <select
                                id="ls-buyer"
                                v-model="form.buyer_contact_id"
                                :class="selectClass"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="contact in buyerContacts"
                                    :key="contact.id"
                                    :value="String(contact.id)"
                                >
                                    {{ contact.first_name }}
                                    {{ contact.last_name }}
                                </option>
                            </select>
                            <InputError :message="errors.buyer_contact_id" />
                        </div>
                    </div>
                    <p class="text-muted-foreground text-xs">
                        {{
                            t(
                                'The seller is the listing owner. These fields track the file only; they do not create a reservation, contract or invoice.',
                            )
                        }}
                    </p>
                </fieldset>
            </form>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="listing-edit-form"
                    :disabled="processing"
                    >{{ t('Save') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
