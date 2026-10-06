<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
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

type PurchaseRequest = {
    id: number;
    reference: string;
    purpose: string;
    status: string;
    requested_by: number;
    lines: {
        id: number;
        description: string;
        quantity: string;
        unit: string;
    }[];
};
type Rfq = {
    id: number;
    reference: string;
    purchase_request_id: number;
    vendor_id: number;
    status: string;
};
type Quotation = {
    id: number;
    rfq_id: number;
    total: string;
    status: string;
    vendor_reference: string | null;
};
type Order = {
    id: number;
    reference: string;
    purchase_request_id: number;
    vendor_id: number;
    total: string;
    status: string;
    receipt_note: string | null;
    vendor_bill_id: number | null;
};
const props = defineProps<{
    requests: PurchaseRequest[];
    rfqs: Rfq[];
    quotations: Quotation[];
    orders: Order[];
    vendors: { id: number; name: string }[];
    properties: { id: number; name: string }[];
    canManage: boolean;
    canManageFinance: boolean;
    userId: number;
}>();
const form = useForm({
    purpose: '',
    property_id: '',
    lines: [{ description: '', quantity: '1', unit: 'each' }],
});
const selectedVendors = reactive<Record<number, string>>({});
function create(): void {
    form.post('/procurement/requests', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
}
function act(url: string, data: Record<string, string> = {}): void {
    router.post(url, data, { preserveScroll: true });
}
function requestRfq(id: number): void {
    const vendor_id = selectedVendors[id];
    if (vendor_id) act(`/procurement/requests/${id}/rfqs`, { vendor_id });
}
function recordQuote(id: number): void {
    const total = prompt('Quoted total (AED)');
    if (total) act(`/procurement/rfqs/${id}/quotation`, { total });
}
function receive(id: number): void {
    const receipt_note = prompt('Describe goods or services received');
    if (receipt_note)
        act(`/procurement/orders/${id}/receive`, { receipt_note });
}
function bill(id: number): void {
    const bill_date = prompt(
        'Bill date (YYYY-MM-DD)',
        new Date().toISOString().slice(0, 10),
    );
    if (bill_date) act(`/procurement/orders/${id}/bill`, { bill_date });
}
function vendorName(id: number): string {
    return props.vendors.find((vendor) => vendor.id === id)?.name ?? 'Vendor';
}
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const cellSelect =
    'border-input bg-background h-8 rounded-md border px-2 text-sm';
const tab = ref<'requests' | 'rfqs' | 'orders'>('requests');
const createOpen = ref(false);
const TABS = [
    { key: 'requests', label: 'Purchase requests' },
    { key: 'rfqs', label: 'RFQs and quotations' },
    { key: 'orders', label: 'Purchase orders and receipts' },
] as const;

type Row = {
    id: number;
    reference: string;
    subject: string;
    detail: string;
    status: string;
    actions: string;
};
const requestOf = (id: number): PurchaseRequest | undefined =>
    props.requests.find((item) => item.id === id);
const rfqOf = (id: number): Rfq | undefined =>
    props.rfqs.find((item) => item.id === id);
const orderOf = (id: number): Order | undefined =>
    props.orders.find((item) => item.id === id);

const rows = computed<Row[]>(() => {
    if (tab.value === 'requests') {
        return props.requests.map((item) => ({
            id: item.id,
            reference: item.reference,
            subject: item.purpose,
            detail: item.lines
                .map(
                    (line) =>
                        `${line.quantity} ${line.unit} ${line.description}`,
                )
                .join(' · '),
            status: item.status,
            actions: '',
        }));
    }
    if (tab.value === 'rfqs') {
        return props.rfqs.map((rfq) => ({
            id: rfq.id,
            reference: rfq.reference,
            subject: vendorName(rfq.vendor_id),
            detail: `${props.quotations.find((quote) => quote.rfq_id === rfq.id)?.total ?? t('Awaiting quote')} AED`,
            status: rfq.status,
            actions: '',
        }));
    }

    return props.orders.map((order) => ({
        id: order.id,
        reference: order.reference,
        subject: vendorName(order.vendor_id),
        detail: `AED ${order.total}${order.vendor_bill_id ? ` · ${t('Bill')} #${order.vendor_bill_id}` : ''}`,
        status: order.status,
        actions: '',
    }));
});
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    {
        key: 'subject',
        label: tab.value === 'requests' ? t('Purpose') : t('Vendor'),
        sortable: true,
    },
    {
        key: 'detail',
        label: tab.value === 'requests' ? t('Items') : t('Amount'),
    },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);
</script>

<template>
    <Head :title="t('Procurement')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Procurement"
            description="Request, compare, order, receive, and bill purchases in AED."
        />

        <nav :aria-label="t('Procurement stages')" class="flex flex-wrap gap-2">
            <button
                v-for="item in TABS"
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

        <CrmSettingsTable
            :show-title="false"
            :title="TABS.find((item) => item.key === tab)!.label"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            :add-label="tab === 'requests' ? 'New purchase request' : ''"
            :selectable="false"
            searchable
            :can-edit="canManage"
            @add="createOpen = true"
        >
            <template #cell-status="{ row }"
                ><Badge variant="secondary">{{ row.status }}</Badge></template
            >
            <template #cell-actions="{ row }">
                <div
                    v-if="tab === 'requests' && canManage && requestOf(row.id)"
                    class="flex flex-wrap items-center justify-end gap-2"
                >
                    <Button
                        v-if="row.status === 'draft'"
                        size="sm"
                        @click="act(`/procurement/requests/${row.id}/submit`)"
                        >{{ t('Submit') }}</Button
                    >
                    <Button
                        v-if="
                            row.status === 'submitted' &&
                            requestOf(row.id)!.requested_by !== userId
                        "
                        size="sm"
                        @click="act(`/procurement/requests/${row.id}/approve`)"
                        >{{ t('Approve') }}</Button
                    >
                    <template
                        v-if="row.status === 'approved' && vendors.length"
                    >
                        <select
                            v-model="selectedVendors[row.id]"
                            :aria-label="t('RFQ vendor')"
                            :class="cellSelect"
                        >
                            <option value="">{{ t('Select vendor') }}</option>
                            <option
                                v-for="vendor in vendors"
                                :key="vendor.id"
                                :value="String(vendor.id)"
                            >
                                {{ vendor.name }}
                            </option>
                        </select>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="!selectedVendors[row.id]"
                            @click="requestRfq(row.id)"
                            >{{ t('Send RFQ') }}</Button
                        >
                    </template>
                </div>
                <div
                    v-else-if="tab === 'rfqs' && canManage && rfqOf(row.id)"
                    class="flex justify-end gap-2"
                >
                    <Button
                        v-if="row.status === 'sent'"
                        size="sm"
                        @click="recordQuote(row.id)"
                        >{{ t('Record quote') }}</Button
                    >
                    <Button
                        v-for="quote in quotations.filter(
                            (item) =>
                                item.rfq_id === row.id &&
                                item.status === 'received',
                        )"
                        :key="quote.id"
                        size="sm"
                        variant="outline"
                        @click="
                            act(`/procurement/quotations/${quote.id}/order`)
                        "
                        >{{ t('Issue order') }}</Button
                    >
                </div>
                <div
                    v-else-if="tab === 'orders' && orderOf(row.id)"
                    class="flex justify-end gap-2"
                >
                    <Button
                        v-if="canManage && row.status === 'issued'"
                        size="sm"
                        @click="receive(row.id)"
                        >{{ t('Record receipt') }}</Button
                    >
                    <Button
                        v-if="canManageFinance && row.status === 'received'"
                        size="sm"
                        variant="outline"
                        @click="bill(row.id)"
                        >{{ t('Create draft bill') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="createOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('New purchase request')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Request, compare, order, receive, and bill purchases in AED.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="pr-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="pr-purpose">{{ t('Purpose') }}</Label
                        ><Input
                            id="pr-purpose"
                            v-model="form.purpose"
                            required
                        /><InputError :message="form.errors.purpose" />
                    </div>
                    <div class="space-y-1">
                        <Label for="pr-property">{{ t('Property') }}</Label>
                        <select
                            id="pr-property"
                            v-model="form.property_id"
                            :class="selectClass"
                        >
                            <option value="">{{ t('Unallocated') }}</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select>
                    </div>
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-medium">
                            {{ t('Items') }}
                        </legend>
                        <div
                            v-for="(line, index) in form.lines"
                            :key="index"
                            class="grid grid-cols-[1fr_4.5rem_5rem_auto] gap-2"
                        >
                            <Input
                                v-model="line.description"
                                :aria-label="t('Line description')"
                                :placeholder="t('Item or service')"
                                required
                            />
                            <Input
                                v-model="line.quantity"
                                :aria-label="t('Quantity')"
                                type="number"
                                min="0.01"
                                step="0.01"
                                required
                            />
                            <Input
                                v-model="line.unit"
                                :aria-label="t('Unit')"
                                :placeholder="t('Unit')"
                                required
                            />
                            <Button
                                v-if="form.lines.length > 1"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="form.lines.splice(index, 1)"
                                >{{ t('Remove') }}</Button
                            >
                        </div>
                        <InputError :message="form.errors.lines" />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="
                                form.lines.push({
                                    description: '',
                                    quantity: '1',
                                    unit: 'each',
                                })
                            "
                            >{{ t('Add line') }}</Button
                        >
                    </fieldset>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="pr-form"
                        :disabled="form.processing"
                        >{{ t('Create draft') }}</Button
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
    </div>
</template>
