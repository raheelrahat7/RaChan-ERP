<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { reactive } from 'vue';

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
        onSuccess: () => form.reset(),
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
</script>

<template>
    <Head title="Procurement" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Procurement"
            description="Request, compare, order, receive, and bill purchases in AED."
        />
        <Card v-if="canManage">
            <CardHeader><CardTitle>New purchase request</CardTitle></CardHeader>
            <CardContent>
                <form class="space-y-3" @submit.prevent="create">
                    <Input
                        v-model="form.purpose"
                        aria-label="Purpose"
                        placeholder="Purpose"
                        required
                    />
                    <select
                        v-model="form.property_id"
                        aria-label="Property"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">Unallocated</option>
                        <option
                            v-for="property in properties"
                            :key="property.id"
                            :value="String(property.id)"
                        >
                            {{ property.name }}
                        </option>
                    </select>
                    <div
                        v-for="(line, index) in form.lines"
                        :key="index"
                        class="flex flex-wrap gap-2"
                    >
                        <Input
                            v-model="line.description"
                            aria-label="Line description"
                            placeholder="Item or service"
                            required
                        />
                        <Input
                            v-model="line.quantity"
                            aria-label="Quantity"
                            type="number"
                            min="0.01"
                            step="0.01"
                            required
                        />
                        <Input
                            v-model="line.unit"
                            aria-label="Unit"
                            placeholder="Unit"
                            required
                        />
                        <Button
                            v-if="form.lines.length > 1"
                            type="button"
                            variant="outline"
                            @click="form.lines.splice(index, 1)"
                            >{{ t('Remove') }}</Button
                        >
                    </div>
                    <p
                        v-if="form.errors.lines"
                        class="text-destructive text-sm"
                    >
                        {{ form.errors.lines }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="
                                form.lines.push({
                                    description: '',
                                    quantity: '1',
                                    unit: 'each',
                                })
                            "
                            >Add line</Button
                        ><Button :disabled="form.processing"
                            >Create draft</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Purchase requests</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="!requests.length"
                    class="text-muted-foreground text-sm"
                >
                    No purchase requests yet.
                </p>
                <div
                    v-for="item in requests"
                    :key="item.id"
                    class="space-y-1 border-b pb-3 last:border-0"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <strong
                            >{{ item.reference }} · {{ item.purpose }}</strong
                        ><span>{{ item.status }}</span>
                    </div>
                    <p class="text-muted-foreground text-sm">
                        {{
                            item.lines
                                .map(
                                    (line) =>
                                        `${line.quantity} ${line.unit} ${line.description}`,
                                )
                                .join(' · ')
                        }}
                    </p>
                    <div v-if="canManage" class="flex flex-wrap gap-2">
                        <Button
                            v-if="item.status === 'draft'"
                            size="sm"
                            @click="
                                act(`/procurement/requests/${item.id}/submit`)
                            "
                            >Submit</Button
                        >
                        <Button
                            v-if="
                                item.status === 'submitted' &&
                                item.requested_by !== userId
                            "
                            size="sm"
                            @click="
                                act(`/procurement/requests/${item.id}/approve`)
                            "
                            >{{ t('Approve') }}</Button
                        >
                        <template
                            v-if="item.status === 'approved' && vendors.length"
                        >
                            <select
                                v-model="selectedVendors[item.id]"
                                aria-label="RFQ vendor"
                                class="border-input h-9 rounded-md border px-3"
                            >
                                <option value="">Select vendor</option>
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
                                :disabled="!selectedVendors[item.id]"
                                @click="requestRfq(item.id)"
                                >Send RFQ</Button
                            >
                        </template>
                    </div>
                </div>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>RFQs and quotations</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p v-if="!rfqs.length" class="text-muted-foreground text-sm">
                    No RFQs yet.
                </p>
                <div
                    v-for="rfq in rfqs"
                    :key="rfq.id"
                    class="flex flex-wrap items-center justify-between gap-2 border-b pb-3 last:border-0"
                >
                    <span
                        >{{ rfq.reference }} · {{ vendorName(rfq.vendor_id) }} ·
                        {{ rfq.status }} ·
                        {{
                            quotations.find((q) => q.rfq_id === rfq.id)
                                ?.total ?? 'Awaiting quote'
                        }}
                        AED</span
                    >
                    <div v-if="canManage" class="flex gap-2">
                        <Button
                            v-if="rfq.status === 'sent'"
                            size="sm"
                            @click="recordQuote(rfq.id)"
                            >Record quote</Button
                        ><Button
                            v-for="quote in quotations.filter(
                                (q) =>
                                    q.rfq_id === rfq.id &&
                                    q.status === 'received',
                            )"
                            :key="quote.id"
                            size="sm"
                            variant="outline"
                            @click="
                                act(`/procurement/quotations/${quote.id}/order`)
                            "
                            >Issue order</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>Purchase orders and receipts</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p v-if="!orders.length" class="text-muted-foreground text-sm">
                    No purchase orders yet.
                </p>
                <div
                    v-for="order in orders"
                    :key="order.id"
                    class="flex flex-wrap items-center justify-between gap-2 border-b pb-3 last:border-0"
                >
                    <span
                        >{{ order.reference }} ·
                        {{ vendorName(order.vendor_id) }} · AED
                        {{ order.total }} · {{ order.status
                        }}<span v-if="order.vendor_bill_id">
                            · Bill #{{ order.vendor_bill_id }}</span
                        ></span
                    >
                    <div class="flex gap-2">
                        <Button
                            v-if="canManage && order.status === 'issued'"
                            size="sm"
                            @click="receive(order.id)"
                            >Record receipt</Button
                        ><Button
                            v-if="
                                canManageFinance && order.status === 'received'
                            "
                            size="sm"
                            variant="outline"
                            @click="bill(order.id)"
                            >Create draft bill</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
