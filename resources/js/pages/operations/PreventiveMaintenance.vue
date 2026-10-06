<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
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
import type { DataTableColumn } from '@/lib/data-table';

const { t } = useLocale();

type Plan = {
    id: number;
    title: string;
    frequency_days: number;
    next_due_on: string;
    is_active: boolean;
    auto_generate_enabled: boolean;
    requires_manager_confirmation: boolean;
    automation_last_run_at: string | null;
    automation_last_error: string | null;
    occurrences: {
        id: number;
        reference: string;
        preventive_due_on: string;
        status: string;
    }[];
    property: { name: string };
    vendor: { name: string } | null;
};
const props = defineProps<{
    plans: Plan[];
    properties: { id: number; name: string }[];
    vendors: { id: number; name: string }[];
    canManage: boolean;
    today: string;
}>();

const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const generating = ref<number | null>(null);
const generationError = ref('');
const settingsPending = ref<number | null>(null);
const settingsError = ref('');
const createOpen = ref(false);
const detailId = ref<number | null>(null);
const confirmation = ref<Record<number, boolean>>({});
const settings = reactive({
    is_active: true,
    auto_generate_enabled: false,
    requires_manager_confirmation: false,
});

const form = useForm({
    property_id: '',
    vendor_id: '',
    title: '',
    frequency_days: '90',
    next_due_on: '',
});
const detail = computed(() =>
    props.plans.find((plan) => plan.id === detailId.value),
);
const detailOpen = computed({
    get: () => detail.value !== undefined,
    set: (value: boolean) => {
        if (!value) {
            detailId.value = null;
        }
    },
});

function isDue(plan: Plan): boolean {
    return plan.is_active && plan.next_due_on <= props.today;
}
function openDetail(plan: Plan): void {
    settings.is_active = plan.is_active;
    settings.auto_generate_enabled = plan.auto_generate_enabled;
    settings.requires_manager_confirmation = plan.requires_manager_confirmation;
    settingsError.value = '';
    generationError.value = '';
    detailId.value = plan.id;
}
function create(): void {
    form.post('/preventive-maintenance', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
}
function saveSettings(plan: Plan): void {
    if (settingsPending.value !== null) return;
    settingsPending.value = plan.id;
    settingsError.value = '';
    router.put(
        `/preventive-maintenance/${plan.id}/automation`,
        {
            auto_generate_enabled: settings.auto_generate_enabled,
            requires_manager_confirmation:
                settings.requires_manager_confirmation,
            is_active: settings.is_active,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                settingsError.value =
                    Object.values(errors)[0] ?? t('Unable to save settings.');
            },
            onFinish: () => {
                settingsPending.value = null;
            },
        },
    );
}
function generate(plan: Plan): void {
    if (
        generating.value === null &&
        confirm(`${t('Generate work order for')} ${plan.title}?`)
    ) {
        generating.value = plan.id;
        generationError.value = '';
        router.post(
            `/preventive-maintenance/${plan.id}/generate`,
            {
                due_on: plan.next_due_on,
                requires_manager_confirmation:
                    confirmation.value[plan.id] ??
                    plan.requires_manager_confirmation,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    generationError.value =
                        errors.due_on ??
                        t('Unable to generate this work order.');
                },
                onFinish: () => {
                    generating.value = null;
                },
            },
        );
    }
}

type Row = {
    id: number;
    title: string;
    property: string;
    every: string;
    due: string;
    status: string;
    actions: string;
};
const rows = computed<Row[]>(() =>
    props.plans.map((plan) => ({
        id: plan.id,
        title: plan.title,
        property: plan.property.name,
        every: `${plan.frequency_days} ${t('days')}`,
        due: plan.next_due_on,
        status: !plan.is_active
            ? t('Paused')
            : isDue(plan)
              ? t('Due')
              : t('Scheduled'),
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'title', label: t('Service'), sortable: true },
    { key: 'property', label: t('Property'), sortable: true },
    { key: 'every', label: t('Every') },
    { key: 'due', label: t('Next due'), sortable: true },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);
const planOf = (id: number): Plan =>
    props.plans.find((plan) => plan.id === id)!;
</script>

<template>
    <Head :title="t('Preventive maintenance')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Preventive maintenance"
            description="Schedule recurring service and generate due work orders."
        />
        <p class="text-muted-foreground text-sm">
            {{
                t(
                    'Automatic generation runs every 15 minutes when the scheduler is running. Every missed occurrence is preserved; large backlogs continue in later batches. Generated jobs are unassigned until a manager allocates them.',
                )
            }}
        </p>
        <CrmSettingsTable
            :show-title="false"
            title="Service plans"
            add-label="New service plan"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.title"
            :selectable="false"
            searchable
            :can-edit="canManage"
            @add="createOpen = true"
        >
            <template #cell-title="{ row }">
                <button
                    type="button"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    @click="openDetail(planOf(row.id))"
                >
                    {{ row.title }}
                </button>
            </template>
            <template #cell-due="{ row }"
                ><span :class="isDue(planOf(row.id)) ? 'text-amber-600' : ''">{{
                    row.due
                }}</span></template
            >
            <template #cell-status="{ row }"
                ><Badge
                    :variant="
                        row.status === t('Due') ? 'destructive' : 'secondary'
                    "
                    >{{ row.status }}</Badge
                ></template
            >
            <template #cell-actions="{ row }">
                <div
                    v-if="canManage && isDue(planOf(row.id))"
                    class="flex justify-end"
                >
                    <Button
                        size="sm"
                        :disabled="generating !== null"
                        @click="generate(planOf(row.id))"
                        >{{ t('Generate work order') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>
        <InputError :message="generationError" />

        <Sheet v-model:open="createOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('New service plan')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Schedule recurring service and generate due work orders.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="plan-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="pm-property">{{ t('Property') }}</Label>
                        <select
                            id="pm-property"
                            v-model="form.property_id"
                            required
                            :class="selectClass"
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.property_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="pm-vendor">{{ t('Vendor') }}</Label>
                        <select
                            id="pm-vendor"
                            v-model="form.vendor_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('No vendor') }}</option>
                            <option
                                v-for="vendor in vendors"
                                :key="vendor.id"
                                :value="String(vendor.id)"
                            >
                                {{ vendor.name }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="pm-title">{{ t('Service title') }}</Label
                        ><Input
                            id="pm-title"
                            v-model="form.title"
                            required
                        /><InputError :message="form.errors.title" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="pm-freq">{{
                                t('Frequency days')
                            }}</Label
                            ><Input
                                id="pm-freq"
                                v-model="form.frequency_days"
                                type="number"
                                min="1"
                                required
                            /><InputError
                                :message="form.errors.frequency_days"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="pm-due">{{ t('Next due') }}</Label
                            ><Input
                                id="pm-due"
                                v-model="form.next_due_on"
                                type="date"
                                required
                            /><InputError :message="form.errors.next_due_on" />
                        </div>
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="plan-form"
                        :disabled="form.processing"
                        >{{ t('Create plan') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="createOpen = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <Sheet v-model:open="detailOpen">
            <SheetContent class="w-full gap-0 sm:max-w-lg" side="right">
                <template v-if="detail">
                    <SheetHeader class="border-b">
                        <SheetTitle class="font-display text-2xl font-medium">{{
                            detail.title
                        }}</SheetTitle>
                        <SheetDescription
                            >{{ detail.property.name
                            }}<template v-if="detail.vendor">
                                · {{ detail.vendor.name }}</template
                            >
                            · {{ t('every') }} {{ detail.frequency_days }}
                            {{ t('days') }} · {{ t('due') }}
                            {{ detail.next_due_on }}</SheetDescription
                        >
                    </SheetHeader>
                    <div class="flex-1 space-y-5 overflow-y-auto p-4">
                        <form
                            v-if="canManage"
                            class="space-y-3"
                            @submit.prevent="saveSettings(detail)"
                        >
                            <InputError :message="settingsError" />
                            <label class="flex items-center gap-2 text-sm"
                                ><input
                                    v-model="settings.is_active"
                                    type="checkbox"
                                />{{ t('Active plan') }}</label
                            >
                            <label class="flex items-center gap-2 text-sm"
                                ><input
                                    v-model="settings.auto_generate_enabled"
                                    type="checkbox"
                                />{{ t('Generate automatically') }}</label
                            >
                            <label class="flex items-center gap-2 text-sm"
                                ><input
                                    v-model="
                                        settings.requires_manager_confirmation
                                    "
                                    type="checkbox"
                                />{{
                                    t(
                                        'Require manager confirmation for automatic jobs',
                                    )
                                }}</label
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="settingsPending !== null"
                                >{{ t('Save settings') }}</Button
                            >
                            <p
                                v-if="detail.automation_last_run_at"
                                class="text-muted-foreground text-sm"
                            >
                                {{ t('Last automatic run:') }}
                                {{ detail.automation_last_run_at }}
                            </p>
                            <p
                                v-if="detail.automation_last_error"
                                role="alert"
                                class="text-destructive text-sm"
                            >
                                {{ detail.automation_last_error }}
                                {{
                                    t(
                                        'Failed plans retry after one hour; saving corrected settings clears this error.',
                                    )
                                }}
                            </p>
                        </form>
                        <div
                            v-if="canManage && isDue(detail)"
                            class="space-y-2 rounded-md border p-3"
                        >
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    :checked="
                                        confirmation[detail.id] ??
                                        detail.requires_manager_confirmation
                                    "
                                    :disabled="generating !== null"
                                    @change="
                                        confirmation[detail.id] = (
                                            $event.target as HTMLInputElement
                                        ).checked
                                    "
                                />
                                {{
                                    t(
                                        'Require manager confirmation for this work order',
                                    )
                                }}
                            </label>
                            <Button
                                size="sm"
                                :disabled="generating !== null"
                                @click="generate(detail)"
                                >{{ t('Generate work order') }}</Button
                            >
                        </div>
                        <div
                            v-if="detail.occurrences.length"
                            class="space-y-1 text-sm"
                        >
                            <p class="font-medium">
                                {{ t('Recent occurrences (up to 20)') }}
                            </p>
                            <p
                                v-for="occurrence in detail.occurrences"
                                :key="occurrence.id"
                            >
                                <Link
                                    :href="`/maintenance/${occurrence.id}/job-card`"
                                    class="text-primary underline-offset-4 hover:underline"
                                    >{{ occurrence.reference }}</Link
                                >
                                · {{ occurrence.preventive_due_on }} ·
                                {{ occurrence.status }}
                            </p>
                        </div>
                    </div>
                </template>
            </SheetContent>
        </Sheet>
    </div>
</template>
