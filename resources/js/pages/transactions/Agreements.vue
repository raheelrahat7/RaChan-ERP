<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import LeasesPanel from '@/components/LeasesPanel.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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

type Reservation = {
    id: number;
    reference: string;
    unit: { id: number; number: string } | null;
    listing: { id: number; reference: string; purpose: string } | null;
};
type Agreement = {
    id: number;
    reference: string;
    status: string;
    unit_id: number;
    broker_id: number | null;
    tenant: { id: number; name: string } | null;
    ends_on: string;
    rent_amount: string | null;
    sale_price?: string | null;
    is_expiring: boolean;
};
type Broker = { id: number; name: string };
type Tenant = { id: number; name: string };
type CommissionPlan = { id: number; name: string; basis: string; rate: string };
type Row = {
    id: number;
    reference: string;
    amount: string;
    status: string;
    actions: string;
};

const props = defineProps<{
    reservations: Reservation[];
    leases: Agreement[];
    salesContracts: Agreement[];
    brokers: Broker[];
    tenants: Tenant[];
    commissionPlans: CommissionPlan[];
    canManageTransactions: boolean;
}>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const tab = ref<'leases' | 'sales'>('leases');
const sheet = ref<'lease' | 'sales' | null>(null);
const sheetOpen = computed({
    get: () => sheet.value !== null,
    set: (value: boolean) => {
        if (!value) {
            sheet.value = null;
        }
    },
});
const commissionPlanId = ref('');
const panel = ref<InstanceType<typeof LeasesPanel> | null>(null);

const leaseReservations = computed(() =>
    props.reservations.filter(
        (reservation) =>
            !reservation.listing || reservation.listing.purpose === 'rent',
    ),
);
const saleReservations = computed(() =>
    props.reservations.filter(
        (reservation) =>
            !reservation.listing || reservation.listing.purpose === 'sale',
    ),
);

const leaseForm = useForm({
    reservation_id: '',
    starts_on: '',
    ends_on: '',
    rent_amount: '',
    broker_id: '',
    tenant_id: '',
    tenancy_number: '',
    renewal_due_on: '',
    advance_amount: '',
});
const salesForm = useForm({
    reservation_id: '',
    contracted_on: '',
    sale_price: '',
    broker_id: '',
});

const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'amount', label: t('Sale price') },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);
const rows = computed<Row[]>(() =>
    props.salesContracts.map((item) => ({
        id: item.id,
        reference: item.reference,
        amount: item.sale_price ?? '',
        status: item.status,
        actions: '',
    })),
);

function createLease(): void {
    leaseForm.post('/agreements/leases', {
        preserveScroll: true,
        onSuccess: () => {
            leaseForm.reset();
            sheet.value = null;
            panel.value?.reload();
        },
    });
}
function createSalesContract(): void {
    salesForm.post('/agreements/sales-contracts', {
        preserveScroll: true,
        onSuccess: () => {
            salesForm.reset();
            sheet.value = null;
        },
    });
}
function activate(row: Row): void {
    if (confirm(`${t('Activate')} ${row.reference}?`)) {
        router.post(
            `/agreements/sales-contracts/${row.id}/activate`,
            { commission_plan_id: commissionPlanId.value || null },
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <Head :title="t('Agreements')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Agreements"
            description="Create and activate lease and sale agreements from active reservations."
        >
            <template #actions>
                <Link href="/lease-compliance" class="text-sm underline">{{
                    t('Manage lease compliance')
                }}</Link>
            </template>
        </PageHeader>

        <nav :aria-label="t('Agreement types')" class="flex gap-2">
            <button
                v-for="item in [
                    { key: 'leases', label: 'Leases' },
                    { key: 'sales', label: 'Sales contracts' },
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

        <Card v-if="canManageTransactions && tab === 'sales'">
            <CardContent class="flex flex-wrap items-end gap-4">
                <div class="space-y-1">
                    <Label for="ag-plan">{{
                        t('Activation commission plan')
                    }}</Label>
                    <select
                        id="ag-plan"
                        v-model="commissionPlanId"
                        :class="selectClass"
                    >
                        <option value="">{{ t('No commission') }}</option>
                        <option
                            v-for="plan in commissionPlans"
                            :key="plan.id"
                            :value="String(plan.id)"
                        >
                            {{ plan.name }} ·
                            {{
                                plan.basis === 'fixed'
                                    ? `AED ${plan.rate}`
                                    : `${plan.rate}%`
                            }}
                        </option>
                    </select>
                </div>
            </CardContent>
        </Card>

        <div v-if="tab === 'leases'" class="space-y-3">
            <div
                v-if="canManageTransactions"
                class="flex flex-wrap items-end gap-4"
            >
                <div class="space-y-1">
                    <Label for="ag-plan-l">{{
                        t('Activation commission plan')
                    }}</Label>
                    <select
                        id="ag-plan-l"
                        v-model="commissionPlanId"
                        :class="selectClass"
                    >
                        <option value="">{{ t('No commission') }}</option>
                        <option
                            v-for="plan in commissionPlans"
                            :key="plan.id"
                            :value="String(plan.id)"
                        >
                            {{ plan.name }} ·
                            {{
                                plan.basis === 'fixed'
                                    ? `AED ${plan.rate}`
                                    : `${plan.rate}%`
                            }}
                        </option>
                    </select>
                </div>
                <Button type="button" @click="sheet = 'lease'">{{
                    t('Create lease')
                }}</Button>
            </div>
            <LeasesPanel
                ref="panel"
                :commission-plan-id="commissionPlanId"
                :can-manage="canManageTransactions"
            />
        </div>
        <CrmSettingsTable
            v-else
            :show-title="false"
            title="Sales contracts"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            add-label="Create contract"
            :selectable="false"
            searchable
            :can-edit="canManageTransactions"
            @add="sheet = 'sales'"
        >
            <template #cell-status="{ row }">
                <Badge variant="secondary">{{ row.status }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <div
                    v-if="canManageTransactions"
                    class="flex justify-end gap-2"
                >
                    <Button
                        v-if="row.status === 'draft'"
                        size="sm"
                        @click="activate(row)"
                        >{{ t('Activate') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="sheetOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        sheet === 'lease'
                            ? t('Create draft lease')
                            : t('Create draft sales contract')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t('Drafts are created from an active reservation.')
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    v-if="sheet === 'lease'"
                    id="agreement-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="createLease"
                >
                    <div class="space-y-1">
                        <Label for="lf-res">{{ t('Reservation') }}</Label>
                        <select
                            id="lf-res"
                            v-model="leaseForm.reservation_id"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="reservation in leaseReservations"
                                :key="reservation.id"
                                :value="String(reservation.id)"
                            >
                                {{ reservation.reference }} ·
                                {{ reservation.unit?.number
                                }}<template v-if="reservation.listing">
                                    ·
                                    {{
                                        reservation.listing.reference
                                    }}</template
                                >
                            </option>
                        </select>
                        <InputError
                            :message="leaseForm.errors.reservation_id"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="lf-start">{{ t('Starts on') }}</Label
                            ><Input
                                id="lf-start"
                                v-model="leaseForm.starts_on"
                                type="date"
                                required
                            /><InputError
                                :message="leaseForm.errors.starts_on"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="lf-end">{{ t('Ends on') }}</Label
                            ><Input
                                id="lf-end"
                                v-model="leaseForm.ends_on"
                                type="date"
                                required
                            /><InputError :message="leaseForm.errors.ends_on" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="lf-rent">{{ t('Rent') }}</Label
                        ><Input
                            id="lf-rent"
                            v-model="leaseForm.rent_amount"
                            type="number"
                            min="0"
                        /><InputError :message="leaseForm.errors.rent_amount" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="lf-tn">{{ t('Tenancy number') }}</Label
                            ><Input
                                id="lf-tn"
                                v-model="leaseForm.tenancy_number"
                            /><InputError
                                :message="leaseForm.errors.tenancy_number"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="lf-adv">{{ t('Advance amount') }}</Label
                            ><Input
                                id="lf-adv"
                                v-model="leaseForm.advance_amount"
                                type="number"
                                min="0"
                                step="0.01"
                            /><InputError
                                :message="leaseForm.errors.advance_amount"
                            />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="lf-due">{{ t('Renewal due on') }}</Label
                        ><Input
                            id="lf-due"
                            v-model="leaseForm.renewal_due_on"
                            type="date"
                        /><InputError
                            :message="leaseForm.errors.renewal_due_on"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="lf-tenant">{{ t('Tenant') }}</Label>
                        <select
                            id="lf-tenant"
                            v-model="leaseForm.tenant_id"
                            :class="selectClass"
                        >
                            <option value="">
                                {{ t('No tenant profile') }}
                            </option>
                            <option
                                v-for="tenant in tenants"
                                :key="tenant.id"
                                :value="String(tenant.id)"
                            >
                                {{ tenant.name }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="lf-broker">{{ t('Broker') }}</Label>
                        <select
                            id="lf-broker"
                            v-model="leaseForm.broker_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('No broker') }}</option>
                            <option
                                v-for="broker in brokers"
                                :key="broker.id"
                                :value="String(broker.id)"
                            >
                                {{ broker.name }}
                            </option>
                        </select>
                    </div>
                </form>
                <form
                    v-else-if="sheet === 'sales'"
                    id="agreement-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="createSalesContract"
                >
                    <div class="space-y-1">
                        <Label for="sf-res">{{ t('Reservation') }}</Label>
                        <select
                            id="sf-res"
                            v-model="salesForm.reservation_id"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="reservation in saleReservations"
                                :key="reservation.id"
                                :value="String(reservation.id)"
                            >
                                {{ reservation.reference }} ·
                                {{ reservation.unit?.number
                                }}<template v-if="reservation.listing">
                                    ·
                                    {{
                                        reservation.listing.reference
                                    }}</template
                                >
                            </option>
                        </select>
                        <InputError
                            :message="salesForm.errors.reservation_id"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="sf-date">{{ t('Contract date') }}</Label
                        ><Input
                            id="sf-date"
                            v-model="salesForm.contracted_on"
                            type="date"
                            required
                        /><InputError
                            :message="salesForm.errors.contracted_on"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="sf-price">{{ t('Sale price') }}</Label
                        ><Input
                            id="sf-price"
                            v-model="salesForm.sale_price"
                            type="number"
                            min="0"
                        /><InputError :message="salesForm.errors.sale_price" />
                    </div>
                    <div class="space-y-1">
                        <Label for="sf-broker">{{ t('Broker') }}</Label>
                        <select
                            id="sf-broker"
                            v-model="salesForm.broker_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('No broker') }}</option>
                            <option
                                v-for="broker in brokers"
                                :key="broker.id"
                                :value="String(broker.id)"
                            >
                                {{ broker.name }}
                            </option>
                        </select>
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="agreement-form"
                        :disabled="leaseForm.processing || salesForm.processing"
                        >{{
                            sheet === 'lease'
                                ? t('Create lease')
                                : t('Create contract')
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
    </div>
</template>
