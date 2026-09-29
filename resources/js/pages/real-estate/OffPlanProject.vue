<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPicker from '@/components/RecordPicker.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { useLocale } from '@/composables/useLocale';
import {
    dealTransitionsForUnit,
    milestonePercentageValid,
} from '@/lib/offplan';
import type { DealStatus } from '@/lib/offplan';
import type { DataTableColumn } from '@/lib/data-table';

type OffPlanProject = {
    id: number;
    developer_id: number;
    developer_name?: string;
    code: string;
    name: string;
    emirate: string;
    status: string;
    commission_rate: string;
};
type OffPlanUnit = {
    id: number;
    number: string;
    type: string | null;
    area_sqft: string | null;
    price_aed: string;
    status: 'available' | 'reserved' | 'sold';
};
type OffPlanMilestone = {
    id: number;
    sequence: number;
    label: string;
    percentage: string;
    due_on: string | null;
};
type OffPlanDeal = {
    id: number;
    unit_id: number;
    lead_id: number;
    reference: string;
    price_aed: string;
    status: DealStatus;
    contracted_on: string | null;
    notes: string | null;
};

const props = defineProps<{
    project: OffPlanProject;
    units: {
        data: OffPlanUnit[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    milestones: OffPlanMilestone[];
    deals: OffPlanDeal[];
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Off-Plan Projects', href: '/real-estate/off-plan' },
            { title: 'Project', href: '#' },
        ],
    },
});

const { t } = useLocale();

const unitColumns: DataTableColumn<OffPlanUnit>[] = [
    { key: 'number', label: 'Unit' },
    { key: 'type', label: 'Type' },
    { key: 'area_sqft', label: 'Area (sqft)' },
    { key: 'price_aed', label: 'Price' },
    { key: 'status', label: 'Status' },
];
const unitDialogOpen = ref(false);
const unitForm = useForm({
    number: '',
    type: '',
    area_sqft: '',
    price_aed: '',
});
function openUnit(): void {
    unitForm.reset();
    unitDialogOpen.value = true;
}
function submitUnit(): void {
    unitForm.post(`/real-estate/off-plan/${props.project.id}/units`, {
        preserveScroll: true,
        onSuccess: () => (unitDialogOpen.value = false),
    });
}

const milestoneTotal = computed(() =>
    props.milestones.reduce(
        (sum, milestone) => sum + Number(milestone.percentage),
        0,
    ),
);
const milestoneDialogOpen = ref(false);
const milestoneForm = useForm({
    sequence: '',
    label: '',
    percentage: '',
    due_on: '',
});
const milestoneError = ref<string | null>(null);
function openMilestone(): void {
    milestoneForm.reset();
    milestoneError.value = null;
    milestoneDialogOpen.value = true;
}
function submitMilestone(): void {
    if (
        !milestonePercentageValid(
            milestoneTotal.value,
            Number(milestoneForm.percentage),
        )
    ) {
        milestoneError.value = t(
            'Payment milestone percentages cannot exceed 100% in total.',
        );

        return;
    }
    milestoneError.value = null;
    milestoneForm.post(`/real-estate/off-plan/${props.project.id}/milestones`, {
        preserveScroll: true,
        onSuccess: () => (milestoneDialogOpen.value = false),
    });
}

const availableUnits = computed(() =>
    props.units.data.filter((unit) => unit.status === 'available'),
);
const dealColumns: DataTableColumn<OffPlanDeal>[] = [
    { key: 'reference', label: 'Reference' },
    { key: 'unit_id', label: 'Unit' },
    { key: 'price_aed', label: 'Price' },
    { key: 'status', label: 'Status' },
    { key: 'id', label: '', align: 'end' },
];
const dealDialogOpen = ref(false);
const dealLeadId = ref<number | null>(null);
const dealForm = useForm({
    unit_id: null as number | null,
    lead_id: null as number | null,
    reference: '',
    price_aed: '',
    notes: '',
});
function openDeal(): void {
    dealForm.reset();
    dealLeadId.value = null;
    dealDialogOpen.value = true;
}
function submitDeal(): void {
    dealForm
        .transform((data) => ({ ...data, lead_id: dealLeadId.value }))
        .post('/real-estate/off-plan/deals', {
            preserveScroll: true,
            onSuccess: () => (dealDialogOpen.value = false),
        });
}

function unitNumber(unitId: number): string {
    return (
        props.units.data.find((unit) => unit.id === unitId)?.number ??
        String(unitId)
    );
}

function dealTransitions(deal: OffPlanDeal): DealStatus[] {
    const unit = props.units.data.find(
        (candidate) => candidate.id === deal.unit_id,
    );

    return dealTransitionsForUnit(deal.status, unit?.status ?? 'available');
}

const contractDialogDeal = ref<OffPlanDeal | null>(null);
const contractForm = useForm({ contracted_on: '' });
function transition(deal: OffPlanDeal, status: DealStatus): void {
    if (status === 'contracted') {
        contractForm.reset();
        contractDialogDeal.value = deal;

        return;
    }
    const question =
        status === 'cancelled'
            ? t('Cancel this deal?')
            : t('Reserve this unit for this deal?');
    if (confirm(question)) {
        useForm({ status, contracted_on: null }).post(
            `/real-estate/off-plan/deals/${deal.id}/status`,
            {
                preserveScroll: true,
            },
        );
    }
}
function submitContract(): void {
    if (!contractDialogDeal.value) {
        return;
    }
    contractForm
        .transform((data) => ({
            status: 'contracted',
            contracted_on: data.contracted_on,
        }))
        .post(
            `/real-estate/off-plan/deals/${contractDialogDeal.value.id}/status`,
            {
                preserveScroll: true,
                onSuccess: () => (contractDialogDeal.value = null),
            },
        );
}
</script>

<template>
    <Head :title="project.name" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Off-Plan" :title="project.name" :translate="false">
            <template #actions>
                <StatusDot :status="project.status" />
            </template>
        </PageHeader>
        <p class="text-muted-foreground -mt-2 text-sm">
            {{ project.code }} · {{ project.emirate }} · {{ t('Commission') }}
            {{ project.commission_rate }}%
        </p>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Units') }}
                </h2>
                <Button v-if="canManage" size="sm" @click="openUnit">{{
                    t('Add unit')
                }}</Button>
            </div>
            <DataTable
                :columns="unitColumns"
                :rows="units.data"
                :row-key="(row) => row.id"
                :row-label="(row) => row.number"
                empty-title="No units yet."
            >
                <template #cell-area_sqft="{ row }">{{
                    row.area_sqft ?? '—'
                }}</template>
                <template #cell-price_aed="{ row }"
                    ><Money :value="row.price_aed"
                /></template>
                <template #cell-status="{ row }"
                    ><StatusDot :status="row.status"
                /></template>
            </DataTable>
            <Pagination :links="units.links" />
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Payment milestones') }}
                </h2>
                <Button v-if="canManage" size="sm" @click="openMilestone">{{
                    t('Add milestone')
                }}</Button>
            </div>
            <p class="text-muted-foreground text-xs">
                {{ t('Allocated') }}: {{ milestoneTotal }}%
            </p>
            <div class="bg-card shadow-panel overflow-hidden rounded-lg border">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-label border-b">
                            <th class="py-2.5 ps-5 pe-3 text-start font-medium">
                                {{ t('Sequence') }}
                            </th>
                            <th class="px-3 text-start font-medium">
                                {{ t('Label') }}
                            </th>
                            <th class="px-3 text-end font-medium">
                                {{ t('Percentage') }}
                            </th>
                            <th class="px-3 pe-5 text-start font-medium">
                                {{ t('Due') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="milestone in milestones"
                            :key="milestone.id"
                            class="border-b last:border-b-0"
                        >
                            <td class="py-3 ps-5 pe-3">
                                {{ milestone.sequence }}
                            </td>
                            <td class="px-3">{{ milestone.label }}</td>
                            <td class="px-3 text-end tabular-nums">
                                {{ milestone.percentage }}%
                            </td>
                            <td class="px-3 pe-5">
                                {{ milestone.due_on ?? '—' }}
                            </td>
                        </tr>
                        <tr v-if="milestones.length === 0">
                            <td
                                colspan="4"
                                class="text-muted-foreground p-8 text-center text-sm"
                            >
                                {{ t('No payment milestones yet.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Deals') }}
                </h2>
                <Button v-if="canManage" size="sm" @click="openDeal">{{
                    t('New deal')
                }}</Button>
            </div>
            <DataTable
                :columns="dealColumns"
                :rows="deals"
                :row-key="(row) => row.id"
                :row-label="(row) => row.reference"
                empty-title="No deals yet."
            >
                <template #cell-unit_id="{ row }">{{
                    unitNumber(row.unit_id)
                }}</template>
                <template #cell-price_aed="{ row }"
                    ><Money :value="row.price_aed"
                /></template>
                <template #cell-status="{ row }"
                    ><StatusDot :status="row.status"
                /></template>
                <template #cell-id="{ row }">
                    <div v-if="canManage" class="flex justify-end gap-2">
                        <Button
                            v-for="next in dealTransitions(row)"
                            :key="next"
                            size="sm"
                            :variant="
                                next === 'cancelled'
                                    ? 'destructive-outline'
                                    : 'outline'
                            "
                            @click="transition(row, next)"
                            >{{
                                t(
                                    next === 'reserved'
                                        ? 'Reserve'
                                        : next === 'contracted'
                                          ? 'Contract'
                                          : 'Cancel',
                                )
                            }}</Button
                        >
                    </div>
                </template>
            </DataTable>
        </section>

        <Dialog v-model:open="unitDialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Add unit')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submitUnit">
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit-number">{{ t('Unit number') }}</Label>
                        <Input id="unit-number" v-model="unitForm.number" />
                        <InputError :message="unitForm.errors.number" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit-type"
                            >{{ t('Type') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input id="unit-type" v-model="unitForm.type" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit-area"
                            >{{ t('Area (sqft)') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="unit-area"
                            v-model="unitForm.area_sqft"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                        <InputError :message="unitForm.errors.area_sqft" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit-price">{{ t('Price (AED)') }}</Label>
                        <Input
                            id="unit-price"
                            v-model="unitForm.price_aed"
                            type="number"
                            min="0.01"
                            step="0.01"
                        />
                        <InputError :message="unitForm.errors.price_aed" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="unitDialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="unitForm.processing">{{
                            t('Add')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="milestoneDialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Add milestone')
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitMilestone"
                >
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="milestone-sequence">{{
                                t('Sequence')
                            }}</Label>
                            <Input
                                id="milestone-sequence"
                                v-model="milestoneForm.sequence"
                                type="number"
                                min="1"
                                max="999"
                            />
                            <InputError
                                :message="milestoneForm.errors.sequence"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="milestone-percentage">{{
                                t('Percentage')
                            }}</Label>
                            <Input
                                id="milestone-percentage"
                                v-model="milestoneForm.percentage"
                                type="number"
                                min="0.01"
                                max="100"
                                step="0.01"
                            />
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="milestone-label">{{ t('Label') }}</Label>
                        <Input
                            id="milestone-label"
                            v-model="milestoneForm.label"
                        />
                        <InputError :message="milestoneForm.errors.label" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="milestone-due"
                            >{{ t('Due date') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="milestone-due"
                            v-model="milestoneForm.due_on"
                            type="date"
                        />
                        <InputError :message="milestoneForm.errors.due_on" />
                    </div>
                    <p v-if="milestoneError" class="text-destructive text-xs">
                        {{ milestoneError }}
                    </p>
                    <InputError :message="milestoneForm.errors.percentage" />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="milestoneDialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="milestoneForm.processing"
                            >{{ t('Add') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="dealDialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('New deal')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submitDeal">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Unit') }}</Label>
                        <Select
                            :model-value="
                                dealForm.unit_id ? String(dealForm.unit_id) : ''
                            "
                            @update:model-value="
                                dealForm.unit_id = Number($event)
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="unit in availableUnits"
                                    :key="unit.id"
                                    :value="String(unit.id)"
                                    >{{ unit.number }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="dealForm.errors.unit_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Lead') }}</Label>
                        <RecordPicker
                            type="lead"
                            v-model="dealLeadId"
                            label="Search leads…"
                        />
                        <InputError :message="dealForm.errors.lead_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="deal-reference">{{ t('Reference') }}</Label>
                        <Input
                            id="deal-reference"
                            v-model="dealForm.reference"
                        />
                        <InputError :message="dealForm.errors.reference" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="deal-price"
                            >{{ t('Price (AED)') }}
                            <span class="text-muted-foreground font-normal"
                                >({{
                                    t('optional, defaults to unit price')
                                }})</span
                            ></Label
                        >
                        <Input
                            id="deal-price"
                            v-model="dealForm.price_aed"
                            type="number"
                            min="0.01"
                            step="0.01"
                        />
                        <InputError :message="dealForm.errors.price_aed" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="deal-notes"
                            >{{ t('Notes') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Textarea id="deal-notes" v-model="dealForm.notes" />
                        <InputError :message="dealForm.errors.notes" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dealDialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="dealForm.processing">{{
                            t('Create deal')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="contractDialogDeal !== null"
            @update:open="(value) => !value && (contractDialogDeal = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Contract this deal')
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitContract"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="contract-date">{{
                            t('Contracted on')
                        }}</Label>
                        <Input
                            id="contract-date"
                            v-model="contractForm.contracted_on"
                            type="date"
                        />
                        <InputError
                            :message="contractForm.errors.contracted_on"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="contractDialogDeal = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="contractForm.processing"
                            >{{ t('Confirm') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
