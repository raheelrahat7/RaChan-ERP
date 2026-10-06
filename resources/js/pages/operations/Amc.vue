<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
type Equipment = {
    id: number;
    reference: string;
    name: string;
    property_id: number;
    serial_number: string | null;
};
type Contract = {
    id: number;
    reference: string;
    title: string;
    starts_on: string;
    ends_on: string;
    service_limit: number | null;
    used: number;
    over_limit: boolean;
    coverage_status: string;
    terms: string | null;
    properties: { id: number; name: string }[];
    equipment: Equipment[];
};
const props = defineProps<{
    properties: { id: number; name: string }[];
    equipment: Equipment[];
    vendors: { id: number; name: string }[];
    contracts: {
        data: Contract[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    visits: {
        id: number;
        amc_contract_id: number;
        maintenance_request_id: number;
        service_on: string;
        override_reason: string | null;
    }[];
}>();
const equipmentForm = useForm({
    property_id: '',
    reference: '',
    name: '',
    serial_number: '',
});
const contractForm = useForm({
    vendor_id: '',
    reference: '',
    title: '',
    starts_on: '',
    ends_on: '',
    service_limit: '',
    terms: '',
    property_ids: [] as number[],
    equipment_ids: [] as number[],
});
const visitForm = useForm({
    contract_id: '',
    maintenance_request_id: '',
    operations_equipment_id: '',
    service_on: '',
    override_reason: '',
});
const cancellation = useForm({ contract_id: '', reason: '' });
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const tab = ref<'contracts' | 'equipment' | 'visits'>('contracts');
const sheet = ref<'contract' | 'equipment' | 'visit' | null>(null);
const sheetOpen = computed({
    get: () => sheet.value !== null,
    set: (value: boolean) => {
        if (!value) {
            sheet.value = null;
        }
    },
});
const cancelOpen = ref(false);

function equipmentCreate(): void {
    equipmentForm.post('/operations/amc/equipment', {
        preserveScroll: true,
        onSuccess: () => {
            equipmentForm.reset();
            sheet.value = null;
        },
    });
}
function contractCreate(): void {
    contractForm.post('/operations/amc', {
        preserveScroll: true,
        onSuccess: () => {
            contractForm.reset();
            sheet.value = null;
        },
    });
}
function reserve(): void {
    visitForm.post(`/operations/amc/${visitForm.contract_id}/visits`, {
        preserveScroll: true,
        onSuccess: () => {
            visitForm.reset();
            sheet.value = null;
        },
    });
}
function cancel(): void {
    cancellation.post(`/operations/amc/${cancellation.contract_id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancellation.reset();
            cancelOpen.value = false;
        },
    });
}
function linkJob(id: number): void {
    visitForm.reset();
    visitForm.contract_id = String(id);
    sheet.value = 'visit';
}
function startCancel(id: number): void {
    cancellation.reset();
    cancellation.contract_id = String(id);
    cancelOpen.value = true;
}

type ContractRow = {
    id: number;
    reference: string;
    title: string;
    period: string;
    usage: string;
    coverage: string;
    status: string;
    actions: string;
};
type EquipmentRow = {
    id: number;
    reference: string;
    name: string;
    property: string;
    serial: string;
};
type VisitRow = {
    id: number;
    contract: string;
    job: string;
    service_on: string;
    override: string;
};
const contractRows = computed<ContractRow[]>(() =>
    props.contracts.data.map((item) => ({
        id: item.id,
        reference: item.reference,
        title: item.title,
        period: `${item.starts_on} → ${item.ends_on}`,
        usage: `${item.used} / ${item.service_limit ?? t('unlimited')}`,
        coverage: [
            item.properties.map((p) => p.name).join(', ') ||
                t('Equipment coverage only'),
            item.equipment.length
                ? `${item.equipment.length} ${t('equipment')}`
                : '',
        ]
            .filter(Boolean)
            .join(' · '),
        status: item.coverage_status,
        actions: '',
    })),
);
const equipmentRows = computed<EquipmentRow[]>(() =>
    props.equipment.map((item) => ({
        id: item.id,
        reference: item.reference,
        name: item.name,
        property:
            props.properties.find(
                (property) => property.id === item.property_id,
            )?.name ?? '',
        serial: item.serial_number ?? '',
    })),
);
const visitRows = computed<VisitRow[]>(() =>
    props.visits.map((visit) => ({
        id: visit.id,
        contract: `#${visit.amc_contract_id}`,
        job: visit.maintenance_request_id.toString(),
        service_on: visit.service_on,
        override: visit.override_reason ?? '',
    })),
);
const contractColumns = computed<DataTableColumn<ContractRow>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'title', label: t('Title'), sortable: true },
    { key: 'period', label: t('Period') },
    { key: 'usage', label: t('Visits used') },
    { key: 'coverage', label: t('Coverage') },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);
const equipmentColumns = computed<DataTableColumn<EquipmentRow>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'property', label: t('Property') },
    { key: 'serial', label: t('Serial number') },
]);
const visitColumns = computed<DataTableColumn<VisitRow>[]>(() => [
    { key: 'service_on', label: t('Service date'), sortable: true },
    { key: 'contract', label: t('Contract') },
    { key: 'job', label: t('Job') },
    { key: 'override', label: t('Limit override reason') },
]);
const contractOf = (id: number) =>
    props.contracts.data.find((item) => item.id === id);
const addLabel = computed(() =>
    tab.value === 'contracts'
        ? 'Create contract'
        : tab.value === 'equipment'
          ? 'Register equipment'
          : 'Reserve visit',
);
function add(): void {
    if (tab.value === 'visits') {
        visitForm.reset();
    }
    sheet.value =
        tab.value === 'contracts'
            ? 'contract'
            : tab.value === 'equipment'
              ? 'equipment'
              : 'visit';
}
</script>

<template>
    <Head :title="t('AMC coverage')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="AMC contracts and coverage"
            description="Record service coverage, equipment and visit limits."
        >
            <template #actions>
                <Link href="/maintenance" class="text-sm underline">{{
                    t('Maintenance')
                }}</Link>
            </template>
        </PageHeader>

        <nav :aria-label="t('AMC views')" class="flex flex-wrap gap-2">
            <button
                v-for="item in [
                    { key: 'contracts', label: 'Contracts' },
                    { key: 'equipment', label: 'Equipment' },
                    { key: 'visits', label: 'Latest 50 service reservations' },
                ] as const"
                :key="item.key"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="
                    item.key === tab
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted border'
                "
                :aria-current="item.key === tab ? 'page' : undefined"
                @click="tab = item.key"
            >
                {{ t(item.label) }}
            </button>
        </nav>

        <template v-if="tab === 'contracts'">
            <CrmSettingsTable
                :show-title="false"
                title="Contracts"
                :add-label="addLabel"
                :columns="contractColumns"
                :rows="contractRows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.reference"
                :selectable="false"
                searchable
                can-edit
                @add="add"
            >
                <template #cell-reference="{ row }">
                    {{ row.reference }}
                    <Badge
                        v-if="contractOf(row.id)?.over_limit"
                        variant="destructive"
                        class="ms-2"
                        >{{ t('Limit exceeded') }}</Badge
                    >
                </template>
                <template #cell-status="{ row }"
                    ><Badge variant="secondary">{{
                        row.status
                    }}</Badge></template
                >
                <template #cell-actions="{ row }">
                    <div class="flex justify-end gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="linkJob(row.id)"
                            >{{ t('Link job') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="startCancel(row.id)"
                            >{{ t('Cancel contract') }}</Button
                        >
                    </div>
                </template>
            </CrmSettingsTable>
            <Pagination :links="contracts.links" />
            <p class="text-muted-foreground text-xs">
                {{
                    t(
                        'Terms are preserved after creation. Cancel an incorrect contract with a reason and create a replacement. Coverage records do not create invoices.',
                    )
                }}
            </p>
        </template>
        <CrmSettingsTable
            v-else-if="tab === 'equipment'"
            :show-title="false"
            title="Equipment"
            :add-label="addLabel"
            :columns="equipmentColumns"
            :rows="equipmentRows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            :selectable="false"
            searchable
            can-edit
            @add="add"
        />
        <CrmSettingsTable
            v-else
            :show-title="false"
            title="Latest 50 service reservations"
            :add-label="addLabel"
            :columns="visitColumns"
            :rows="visitRows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.service_on"
            :selectable="false"
            can-edit
            @add="add"
        >
            <template #cell-job="{ row }">
                <Link
                    :href="`/maintenance/${row.job}/job-card`"
                    class="text-primary underline-offset-2 hover:underline"
                    >{{ t('Job') }} #{{ row.job }}</Link
                >
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="sheetOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        sheet === 'contract'
                            ? t('Create coverage contract')
                            : sheet === 'equipment'
                              ? t('Register equipment')
                              : t('Link a job to coverage')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        sheet === 'visit'
                            ? t(
                                  'Each linked job reserves one visit. Cancelled jobs release usage; reopening counts the same visit again. Exceeding a limit requires a manager reason.',
                              )
                            : t(
                                  'Record service coverage, equipment and visit limits.',
                              )
                    }}</SheetDescription>
                </SheetHeader>

                <form
                    v-if="sheet === 'equipment'"
                    id="amc-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="equipmentCreate"
                >
                    <div class="space-y-1">
                        <Label for="eq-property">{{ t('Property') }}</Label>
                        <select
                            id="eq-property"
                            v-model="equipmentForm.property_id"
                            required
                            :class="selectClass"
                        >
                            <option value="">—</option>
                            <option
                                v-for="item in properties"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                        <InputError
                            :message="equipmentForm.errors.property_id"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="eq-ref">{{ t('Reference') }}</Label
                        ><Input
                            id="eq-ref"
                            v-model="equipmentForm.reference"
                            required
                            maxlength="255"
                        /><InputError
                            :message="equipmentForm.errors.reference"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="eq-name">{{ t('Name') }}</Label
                        ><Input
                            id="eq-name"
                            v-model="equipmentForm.name"
                            required
                            maxlength="255"
                        /><InputError :message="equipmentForm.errors.name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="eq-serial">{{ t('Serial number') }}</Label
                        ><Input
                            id="eq-serial"
                            v-model="equipmentForm.serial_number"
                            maxlength="255"
                        /><InputError
                            :message="equipmentForm.errors.serial_number"
                        />
                    </div>
                </form>

                <form
                    v-else-if="sheet === 'contract'"
                    id="amc-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="contractCreate"
                >
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="ct-ref">{{ t('Reference') }}</Label
                            ><Input
                                id="ct-ref"
                                v-model="contractForm.reference"
                                required
                                maxlength="255"
                            /><InputError
                                :message="contractForm.errors.reference"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="ct-title">{{ t('Title') }}</Label
                            ><Input
                                id="ct-title"
                                v-model="contractForm.title"
                                required
                                maxlength="255"
                            /><InputError
                                :message="contractForm.errors.title"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="ct-start">{{ t('Starts on') }}</Label
                            ><Input
                                id="ct-start"
                                v-model="contractForm.starts_on"
                                required
                                type="date"
                            /><InputError
                                :message="contractForm.errors.starts_on"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="ct-end">{{ t('Ends on') }}</Label
                            ><Input
                                id="ct-end"
                                v-model="contractForm.ends_on"
                                required
                                type="date"
                            /><InputError
                                :message="contractForm.errors.ends_on"
                            />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="ct-limit">{{
                            t('Service limit (blank for unlimited)')
                        }}</Label
                        ><Input
                            id="ct-limit"
                            v-model="contractForm.service_limit"
                            type="number"
                            min="1"
                            max="1000000"
                        /><InputError
                            :message="contractForm.errors.service_limit"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="ct-vendor">{{ t('Vendor') }}</Label>
                        <select
                            id="ct-vendor"
                            v-model="contractForm.vendor_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('No vendor') }}</option>
                            <option
                                v-for="item in vendors"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                    </div>
                    <fieldset class="space-y-2 rounded-md border p-3">
                        <legend class="px-1 text-sm">
                            {{ t('Covered properties') }}
                        </legend>
                        <label
                            v-for="item in properties"
                            :key="item.id"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="contractForm.property_ids"
                                type="checkbox"
                                :value="item.id"
                            />{{ item.name }}</label
                        >
                    </fieldset>
                    <fieldset class="space-y-2 rounded-md border p-3">
                        <legend class="px-1 text-sm">
                            {{ t('Covered equipment') }}
                        </legend>
                        <label
                            v-for="item in equipment"
                            :key="item.id"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="contractForm.equipment_ids"
                                type="checkbox"
                                :value="item.id"
                            />{{ item.reference }} · {{ item.name }}</label
                        >
                    </fieldset>
                    <div class="space-y-1">
                        <Label for="ct-terms">{{ t('Service terms') }}</Label>
                        <textarea
                            id="ct-terms"
                            v-model="contractForm.terms"
                            maxlength="10000"
                            class="border-input bg-background block min-h-24 w-full rounded-md border p-3 text-sm"
                        />
                        <InputError :message="contractForm.errors.terms" />
                    </div>
                    <InputError :message="contractForm.errors.property_ids" />
                </form>

                <form
                    v-else-if="sheet === 'visit'"
                    id="amc-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="reserve"
                >
                    <div class="space-y-1">
                        <Label for="vs-contract">{{ t('Contract ID') }}</Label>
                        <Input
                            id="vs-contract"
                            v-model="visitForm.contract_id"
                            required
                            list="amc-contract-options"
                            inputmode="numeric"
                        />
                        <datalist id="amc-contract-options">
                            <option
                                v-for="item in contracts.data"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.reference }} · {{ item.title }}
                            </option>
                        </datalist>
                        <p class="text-muted-foreground text-xs">
                            {{
                                t(
                                    'Pick from this page or type the ID of a contract on another page.',
                                )
                            }}
                        </p>
                        <InputError :message="visitForm.errors.contract_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="vs-job">{{ t('Job ID') }}</Label
                        ><Input
                            id="vs-job"
                            v-model="visitForm.maintenance_request_id"
                            required
                            type="number"
                            min="1"
                        /><InputError
                            :message="visitForm.errors.maintenance_request_id"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="vs-eq">{{
                            t('Equipment (optional)')
                        }}</Label>
                        <select
                            id="vs-eq"
                            v-model="visitForm.operations_equipment_id"
                            :class="selectClass"
                        >
                            <option value="">
                                {{ t('Property coverage') }}
                            </option>
                            <option
                                v-for="item in equipment"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.reference }} · {{ item.name }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="vs-date">{{ t('Service date') }}</Label
                        ><Input
                            id="vs-date"
                            v-model="visitForm.service_on"
                            required
                            type="date"
                        /><InputError :message="visitForm.errors.service_on" />
                    </div>
                    <div class="space-y-1">
                        <Label for="vs-override">{{
                            t('Limit override reason')
                        }}</Label
                        ><Input
                            id="vs-override"
                            v-model="visitForm.override_reason"
                            maxlength="2000"
                        /><InputError
                            :message="visitForm.errors.override_reason"
                        />
                    </div>
                </form>

                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="amc-form"
                        :disabled="
                            equipmentForm.processing ||
                            contractForm.processing ||
                            visitForm.processing
                        "
                        >{{
                            sheet === 'contract'
                                ? t('Create contract')
                                : sheet === 'equipment'
                                  ? t('Register equipment')
                                  : t('Reserve visit')
                        }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="sheet = null"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <Dialog v-model:open="cancelOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ t('Cancel a contract') }}</DialogTitle>
                    <DialogDescription>{{
                        contractOf(Number(cancellation.contract_id))?.reference
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="cancel">
                    <div class="space-y-1">
                        <Label for="cc-reason">{{
                            t('Cancellation reason')
                        }}</Label
                        ><Input
                            id="cc-reason"
                            v-model="cancellation.reason"
                            required
                            maxlength="2000"
                        /><InputError
                            :message="cancellation.errors.reason"
                        /><InputError
                            :message="cancellation.errors.contract_id"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="cancelOpen = false"
                            >{{ t('Close') }}</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="cancellation.processing"
                            >{{ t('Cancel contract') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
