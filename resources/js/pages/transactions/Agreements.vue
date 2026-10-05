<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
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
    party: string;
    ends_on: string;
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
const renewalEndsOn = ref('');
const renewalRentAmount = ref('');

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
});
const salesForm = useForm({
    reservation_id: '',
    contracted_on: '',
    sale_price: '',
    broker_id: '',
});

const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    ...(tab.value === 'leases'
        ? [{ key: 'party' as const, label: t('Tenant') }]
        : []),
    ...(tab.value === 'leases'
        ? [{ key: 'ends_on' as const, label: t('Ends on'), sortable: true }]
        : []),
    {
        key: 'amount',
        label: tab.value === 'leases' ? t('Rent') : t('Sale price'),
    },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);
const rows = computed<Row[]>(() =>
    (tab.value === 'leases' ? props.leases : props.salesContracts).map(
        (item) => ({
            id: item.id,
            reference: item.reference,
            party: item.tenant?.name ?? '',
            ends_on: item.ends_on ?? '',
            amount: item.rent_amount ?? item.sale_price ?? '',
            status: item.status,
            actions: '',
        }),
    ),
);
const agreementOf = (id: number): Agreement | undefined =>
    (tab.value === 'leases' ? props.leases : props.salesContracts).find(
        (item) => item.id === id,
    );
const isExpiring = (id: number): boolean =>
    tab.value === 'leases' && agreementOf(id)?.is_expiring === true;

function createLease(): void {
    leaseForm.post('/agreements/leases', {
        preserveScroll: true,
        onSuccess: () => {
            leaseForm.reset();
            sheet.value = null;
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
    const path = tab.value === 'leases' ? 'leases' : 'sales-contracts';
    if (confirm(`${t('Activate')} ${row.reference}?`)) {
        router.post(
            `/agreements/${path}/${row.id}/activate`,
            { commission_plan_id: commissionPlanId.value || null },
            { preserveScroll: true },
        );
    }
}
function renew(row: Row): void {
    if (!renewalEndsOn.value) {
        return;
    }
    router.post(
        `/agreements/leases/${row.id}/renew`,
        {
            ends_on: renewalEndsOn.value,
            rent_amount: renewalRentAmount.value || null,
        },
        { preserveScroll: true },
    );
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

        <Card v-if="canManageTransactions">
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
                <template v-if="tab === 'leases'">
                    <div class="space-y-1">
                        <Label for="ag-renew-end">{{
                            t('Renewal ends on')
                        }}</Label>
                        <Input
                            id="ag-renew-end"
                            v-model="renewalEndsOn"
                            type="date"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="ag-renew-rent">{{
                            t('New rent (optional)')
                        }}</Label>
                        <Input
                            id="ag-renew-rent"
                            v-model="renewalRentAmount"
                            type="number"
                            min="0"
                        />
                    </div>
                    <p class="text-muted-foreground pb-2 text-sm">
                        {{
                            t(
                                'Set terms, then select Renew on an active lease.',
                            )
                        }}
                    </p>
                </template>
            </CardContent>
        </Card>

        <CrmSettingsTable
            :title="tab === 'leases' ? 'Leases' : 'Sales contracts'"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            :add-label="tab === 'leases' ? 'Create lease' : 'Create contract'"
            :selectable="false"
            searchable
            :can-edit="canManageTransactions"
            @add="sheet = tab === 'leases' ? 'lease' : 'sales'"
        >
            <template #cell-reference="{ row }">
                {{ row.reference }}
                <Badge
                    v-if="isExpiring(row.id)"
                    variant="outline"
                    class="ms-2"
                    >{{ t('Expiring') }}</Badge
                >
            </template>
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
                    <Button
                        v-if="tab === 'leases' && row.status === 'active'"
                        size="sm"
                        variant="outline"
                        :disabled="!renewalEndsOn"
                        @click="renew(row)"
                        >{{ t('Renew') }}</Button
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
