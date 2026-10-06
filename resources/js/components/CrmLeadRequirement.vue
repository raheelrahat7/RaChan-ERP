<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    FIELD_LABELS,
    draftFrom,
    fieldErrors,
    payloadFrom,
    selectableChoices,
} from '@/lib/crm-requirements';
import type {
    Requirement,
    RequirementConfiguration,
    RequirementDraft,
} from '@/lib/crm-requirements';

const props = defineProps<{ leadId: number }>();
const { t } = useLocale();
const base = `/crm/leads/${props.leadId}/requirements`;
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const requirement = ref<Requirement | null>(null);
const configuration = ref<RequirementConfiguration | null>(null);
const draft = ref<RequirementDraft>({});
const errors = ref<Record<string, string>>({});
const loadError = ref('');
const message = ref('');
const busy = ref(false);

const choices = computed(() => configuration.value?.choices ?? {});
const canEdit = computed(() => requirement.value?.permissions.edit === true);
const changes = computed(() =>
    requirement.value ? payloadFrom(requirement.value.data, draft.value) : {},
);
const dirty = computed(() => Object.keys(changes.value).length > 0);

function apply(data: {
    requirement: Requirement;
    configuration: RequirementConfiguration;
}): void {
    requirement.value = data.requirement;
    configuration.value = data.configuration;
    draft.value = draftFrom(data.requirement.data);
}

async function load(): Promise<void> {
    try {
        apply(await apiJson(base));
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load the requirement.');
    }
}

function set(key: string, value: string | string[]): void {
    draft.value = { ...draft.value, [key]: value };
    message.value = '';
}
function toggleAmenity(code: string): void {
    const current = Array.isArray(draft.value.amenities)
        ? draft.value.amenities
        : [];
    set(
        'amenities',
        current.includes(code)
            ? current.filter((item) => item !== code)
            : [...current, code],
    );
}

async function save(): Promise<void> {
    if (!requirement.value || !dirty.value) {
        return;
    }
    busy.value = true;
    errors.value = {};
    message.value = '';
    try {
        apply(
            await apiJson(base, 'PUT', {
                expected_version: requirement.value.version,
                data: changes.value,
            }),
        );
        message.value = t('Saved.');
    } catch (failure) {
        if (failure instanceof ApiError) {
            const found = failure.fieldErrors();
            errors.value = Object.keys(found).length
                ? fieldErrors(found)
                : { form: failure.message };
        } else {
            errors.value = { form: t('Could not save.') };
        }
    } finally {
        busy.value = false;
    }
}
function discard(): void {
    if (requirement.value) {
        draft.value = draftFrom(requirement.value.data);
        errors.value = {};
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <template v-if="requirement">
            <p v-if="!canEdit" class="text-muted-foreground text-sm">
                {{ t('You can view this requirement but not change it.') }}
            </p>
            <InputError :message="errors.form" />
            <InputError :message="errors.lead" />
            <InputError :message="errors.expected_version" />

            <div class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        ><CardTitle class="text-eyebrow">{{
                            t('Profile')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent class="grid grid-cols-2 gap-4">
                        <div
                            v-for="field in [
                                'type',
                                'purpose',
                                'unit_category',
                                'temperature',
                                'language',
                            ]"
                            :key="field"
                            class="space-y-1"
                        >
                            <Label :for="`rq-${field}`">{{
                                t(FIELD_LABELS[field])
                            }}</Label>
                            <select
                                :id="`rq-${field}`"
                                :class="selectClass"
                                :disabled="!canEdit"
                                :value="draft[field]"
                                @change="
                                    set(
                                        field,
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="">—</option>
                                <option
                                    v-for="choice in selectableChoices(
                                        choices[field],
                                        draft[field],
                                    )"
                                    :key="choice.value"
                                    :value="choice.value"
                                >
                                    {{ choice.label
                                    }}<template v-if="!choice.active">
                                        ({{ t('archived') }})</template
                                    >
                                </option>
                            </select>
                            <InputError :message="errors[field]" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-score">{{
                                t('Lead score (0-100)')
                            }}</Label>
                            <Input
                                id="rq-score"
                                type="number"
                                min="0"
                                max="100"
                                :disabled="!canEdit"
                                :model-value="String(draft.lead_score ?? '')"
                                @update:model-value="
                                    set('lead_score', String($event))
                                "
                            />
                            <InputError :message="errors.lead_score" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        ><CardTitle class="text-eyebrow">{{
                            t('Property')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent class="grid grid-cols-2 gap-4">
                        <div
                            v-for="field in [
                                'emirate',
                                'property_type',
                                'furnishing',
                                'completion_status',
                            ]"
                            :key="field"
                            class="space-y-1"
                        >
                            <Label :for="`rq-${field}`">{{
                                t(FIELD_LABELS[field])
                            }}</Label>
                            <select
                                :id="`rq-${field}`"
                                :class="selectClass"
                                :disabled="!canEdit"
                                :value="draft[field]"
                                @change="
                                    set(
                                        field,
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="">—</option>
                                <option
                                    v-for="choice in selectableChoices(
                                        choices[field],
                                        draft[field],
                                    )"
                                    :key="choice.value"
                                    :value="choice.value"
                                >
                                    {{ choice.label
                                    }}<template v-if="!choice.active">
                                        ({{ t('archived') }})</template
                                    >
                                </option>
                            </select>
                            <InputError :message="errors[field]" />
                        </div>
                        <div class="col-span-2 space-y-1">
                            <Label for="rq-location">{{ t('Location') }}</Label>
                            <Input
                                id="rq-location"
                                maxlength="255"
                                :disabled="!canEdit"
                                :model-value="String(draft.location ?? '')"
                                @update:model-value="
                                    set('location', String($event))
                                "
                            />
                            <InputError :message="errors.location" />
                        </div>
                        <div
                            v-for="field in [
                                'bedrooms_min',
                                'bedrooms_max',
                                'bathrooms_min',
                            ]"
                            :key="field"
                            class="space-y-1"
                        >
                            <Label :for="`rq-${field}`">{{
                                t(
                                    {
                                        bedrooms_min: 'Bedrooms min',
                                        bedrooms_max: 'Bedrooms max',
                                        bathrooms_min: 'Bathrooms min',
                                    }[field] as string,
                                )
                            }}</Label>
                            <Input
                                :id="`rq-${field}`"
                                type="number"
                                min="0"
                                max="100"
                                :disabled="!canEdit"
                                :model-value="String(draft[field] ?? '')"
                                @update:model-value="set(field, String($event))"
                            />
                            <InputError :message="errors[field]" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-handover">{{
                                t('Handover date')
                            }}</Label>
                            <Input
                                id="rq-handover"
                                type="date"
                                :disabled="!canEdit"
                                :model-value="String(draft.handover_on ?? '')"
                                @update:model-value="
                                    set('handover_on', String($event))
                                "
                            />
                            <InputError :message="errors.handover_on" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-size-min">{{ t('Size min') }}</Label>
                            <Input
                                id="rq-size-min"
                                inputmode="decimal"
                                :disabled="!canEdit"
                                :model-value="String(draft.size_min ?? '')"
                                @update:model-value="
                                    set('size_min', String($event))
                                "
                            />
                            <InputError :message="errors.size_min" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-size-max">{{ t('Size max') }}</Label>
                            <Input
                                id="rq-size-max"
                                inputmode="decimal"
                                :disabled="!canEdit"
                                :model-value="String(draft.size_max ?? '')"
                                @update:model-value="
                                    set('size_max', String($event))
                                "
                            />
                            <InputError :message="errors.size_max" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-size-unit">{{
                                t('Size unit')
                            }}</Label>
                            <select
                                id="rq-size-unit"
                                :class="selectClass"
                                :disabled="!canEdit"
                                :value="draft.size_unit"
                                @change="
                                    set(
                                        'size_unit',
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="">—</option>
                                <option value="sq_ft">{{ t('sq ft') }}</option>
                                <option value="sq_m">{{ t('sq m') }}</option>
                            </select>
                            <InputError :message="errors.size_unit" />
                        </div>
                        <fieldset
                            class="col-span-2 space-y-1 rounded-md border p-3"
                        >
                            <legend class="px-1 text-sm">
                                {{ t('Amenities') }}
                            </legend>
                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                <label
                                    v-for="choice in selectableChoices(
                                        choices.amenities,
                                        draft.amenities,
                                    )"
                                    :key="choice.value"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        :disabled="!canEdit"
                                        :checked="
                                            Array.isArray(draft.amenities) &&
                                            draft.amenities.includes(
                                                choice.value,
                                            )
                                        "
                                        @change="toggleAmenity(choice.value)"
                                    />{{ choice.label }}
                                </label>
                            </div>
                            <InputError :message="errors.amenities" />
                        </fieldset>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        ><CardTitle class="text-eyebrow">{{
                            t('Budget and financing')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <Label for="rq-bmin">{{ t('Budget min') }}</Label>
                            <Input
                                id="rq-bmin"
                                inputmode="decimal"
                                :disabled="!canEdit"
                                :model-value="String(draft.budget_min ?? '')"
                                @update:model-value="
                                    set('budget_min', String($event))
                                "
                            />
                            <InputError :message="errors.budget_min" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-bmax">{{ t('Budget max') }}</Label>
                            <Input
                                id="rq-bmax"
                                inputmode="decimal"
                                :disabled="!canEdit"
                                :model-value="String(draft.budget_max ?? '')"
                                @update:model-value="
                                    set('budget_max', String($event))
                                "
                            />
                            <InputError :message="errors.budget_max" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-cur">{{
                                t('Budget currency')
                            }}</Label>
                            <Input
                                id="rq-cur"
                                maxlength="3"
                                placeholder="AED"
                                :disabled="!canEdit"
                                :model-value="
                                    String(draft.budget_currency ?? '')
                                "
                                @update:model-value="
                                    set('budget_currency', String($event))
                                "
                            />
                            <InputError :message="errors.budget_currency" />
                        </div>
                        <div
                            v-for="field in [
                                'rent_frequency',
                                'payment_method',
                                'financing_status',
                            ]"
                            :key="field"
                            class="space-y-1"
                        >
                            <Label :for="`rq-${field}`">{{
                                t(FIELD_LABELS[field])
                            }}</Label>
                            <select
                                :id="`rq-${field}`"
                                :class="selectClass"
                                :disabled="!canEdit"
                                :value="draft[field]"
                                @change="
                                    set(
                                        field,
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="">—</option>
                                <option
                                    v-for="choice in selectableChoices(
                                        choices[field],
                                        draft[field],
                                    )"
                                    :key="choice.value"
                                    :value="choice.value"
                                >
                                    {{ choice.label
                                    }}<template v-if="!choice.active">
                                        ({{ t('archived') }})</template
                                    >
                                </option>
                            </select>
                            <InputError :message="errors[field]" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-down">{{
                                t('Down payment %')
                            }}</Label>
                            <Input
                                id="rq-down"
                                inputmode="decimal"
                                :disabled="!canEdit"
                                :model-value="
                                    String(draft.down_payment_percent ?? '')
                                "
                                @update:model-value="
                                    set('down_payment_percent', String($event))
                                "
                            />
                            <InputError
                                :message="errors.down_payment_percent"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-roi">{{
                                t('ROI expectation (%)')
                            }}</Label>
                            <Input
                                id="rq-roi"
                                inputmode="decimal"
                                :disabled="!canEdit"
                                :model-value="String(draft.roi_percent ?? '')"
                                @update:model-value="
                                    set('roi_percent', String($event))
                                "
                            />
                            <InputError :message="errors.roi_percent" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        ><CardTitle class="text-eyebrow">{{
                            t('Timeline and notes')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent class="space-y-4">
                        <div class="space-y-1">
                            <Label for="rq-timeline">{{ t('Timeline') }}</Label>
                            <select
                                id="rq-timeline"
                                :class="selectClass"
                                :disabled="!canEdit"
                                :value="draft.timeline"
                                @change="
                                    set(
                                        'timeline',
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="">—</option>
                                <option
                                    v-for="choice in selectableChoices(
                                        choices.timeline,
                                        draft.timeline,
                                    )"
                                    :key="choice.value"
                                    :value="choice.value"
                                >
                                    {{ choice.label
                                    }}<template v-if="!choice.active">
                                        ({{ t('archived') }})</template
                                    >
                                </option>
                            </select>
                            <InputError :message="errors.timeline" />
                        </div>
                        <div class="space-y-1">
                            <Label for="rq-pref">{{ t('Preferences') }}</Label>
                            <textarea
                                id="rq-pref"
                                rows="5"
                                maxlength="10000"
                                :disabled="!canEdit"
                                class="border-input bg-background w-full rounded-md border p-2 text-sm"
                                :value="String(draft.preferences ?? '')"
                                @input="
                                    set(
                                        'preferences',
                                        ($event.target as HTMLTextAreaElement)
                                            .value,
                                    )
                                "
                            />
                            <InputError :message="errors.preferences" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div v-if="canEdit" class="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    :disabled="busy || !dirty"
                    @click="save"
                    >{{ t('Save requirement') }}</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy || !dirty"
                    @click="discard"
                    >{{ t('Discard changes') }}</Button
                >
                <span v-if="dirty" class="text-muted-foreground text-sm">{{
                    t('unsaved changes')
                }}</span>
                <span v-if="message" role="status" class="text-sm">{{
                    message
                }}</span>
            </div>
        </template>
    </div>
</template>
