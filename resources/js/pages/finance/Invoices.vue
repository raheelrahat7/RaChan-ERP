<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import CustomerCreditNotes from '@/components/CustomerCreditNotes.vue';
import CustomerRefunds from '@/components/CustomerRefunds.vue';
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

type Invoice = {
    id: number;
    reference: string;
    status: string;
    accounting_treatment: string | null;
    vat_treatment: string | null;
    vat_amount: string | null;
    total: string;
    paid_amount: string;
    credited_amount: string;
    creditable_amount: string;
    refundable_cash_amount: string;
    credit_notes: {
        id: number;
        reference: string;
        status: string;
        amount: string;
        vat_amount: string;
        reason: string;
        posted_on: string | null;
        reversed_on: string | null;
        reversal_reason: string | null;
        refund_available: string;
    }[];
    refunds: {
        id: number;
        reference: string;
        status: string;
        amount: string;
        reason: string;
        posted_on: string | null;
        reversal_reason: string | null;
        rejection_reason: string | null;
        requester?: { name: string } | null;
        credit_note?: { reference: string } | null;
    }[];
    balance: number;
    is_overdue: boolean;
    currency: string;
    due_on: string | null;
};
const props = defineProps<{
    invoices: Invoice[];
    canManageFinance: boolean;
    canApproveCustomerRefunds: boolean;
    vatEnabled: boolean;
}>();
const form = useForm({
    description: '',
    quantity: '1',
    unit_price: '',
    due_on: '',
    accounting_treatment: '',
    vat_treatment: '',
});
function createInvoice(): void {
    form.post('/invoices', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
}
function postInvoice(invoice: Invoice): void {
    router.post(`/invoices/${invoice.id}/post`, {}, { preserveScroll: true });
}
function updateTreatment(invoice: Invoice, event: Event): void {
    const accounting_treatment = (event.target as HTMLSelectElement).value;
    if (accounting_treatment)
        router.put(
            `/invoices/${invoice.id}/accounting-treatment`,
            { accounting_treatment, vat_treatment: invoice.vat_treatment },
            { preserveScroll: true },
        );
}
function updateVatTreatment(invoice: Invoice, event: Event): void {
    const vat_treatment = (event.target as HTMLSelectElement).value;
    if (vat_treatment && invoice.accounting_treatment)
        router.put(
            `/invoices/${invoice.id}/accounting-treatment`,
            {
                accounting_treatment: invoice.accounting_treatment,
                vat_treatment,
            },
            { preserveScroll: true },
        );
}
function payInvoice(invoice: Invoice): void {
    const amount = prompt('Payment amount (AED)');
    if (amount)
        router.post(
            `/invoices/${invoice.id}/payments`,
            { amount },
            { preserveScroll: true },
        );
}
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const createOpen = ref(false);
const detailId = ref<number | null>(null);
const detail = computed(() =>
    props.invoices.find((invoice) => invoice.id === detailId.value),
);
const detailOpen = computed({
    get: () => detail.value !== undefined,
    set: (value: boolean) => {
        if (!value) {
            detailId.value = null;
        }
    },
});

type Row = {
    id: number;
    reference: string;
    due_on: string;
    total: string;
    paid: string;
    balance: string;
    status: string;
    actions: string;
};
const rows = computed<Row[]>(() =>
    props.invoices.map((invoice) => ({
        id: invoice.id,
        reference: invoice.reference,
        due_on: invoice.due_on ?? '',
        total: `${invoice.currency} ${invoice.total}`,
        paid: `${invoice.currency} ${invoice.paid_amount}`,
        balance: `${invoice.currency} ${invoice.balance.toFixed(2)}`,
        status: invoice.status,
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'due_on', label: t('Due'), sortable: true },
    { key: 'total', label: t('Total'), align: 'end' },
    { key: 'paid', label: t('Paid'), align: 'end' },
    { key: 'balance', label: t('Balance'), align: 'end' },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);
const invoiceOf = (id: number): Invoice | undefined =>
    props.invoices.find((invoice) => invoice.id === id);
</script>

<template>
    <Head :title="t('Invoices')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Invoices"
            description="AED receivables and payment allocation."
        />

        <CrmSettingsTable
            :show-title="false"
            title="Invoices"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            add-label="Create invoice"
            :selectable="false"
            searchable
            :can-edit="canManageFinance"
            @add="createOpen = true"
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    @click="detailId = row.id"
                >
                    {{ row.reference }}
                </button>
                <Badge
                    v-if="invoiceOf(row.id)?.is_overdue"
                    variant="destructive"
                    class="ms-2"
                    >{{ t('Overdue') }}</Badge
                >
            </template>
            <template #cell-status="{ row }">
                <Badge variant="secondary">{{ row.status }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <div
                    v-if="canManageFinance && invoiceOf(row.id)"
                    class="flex justify-end gap-2"
                >
                    <Button
                        v-if="row.status === 'draft'"
                        size="sm"
                        @click="postInvoice(invoiceOf(row.id)!)"
                        >{{ t('Post') }}</Button
                    >
                    <Button
                        v-if="['posted', 'partial'].includes(row.status)"
                        size="sm"
                        variant="outline"
                        @click="payInvoice(invoiceOf(row.id)!)"
                        >{{ t('Record payment') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="createOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Create invoice')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t('AED receivables and payment allocation.')
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="invoice-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="createInvoice"
                >
                    <div class="space-y-1">
                        <Label for="inv-desc">{{ t('Description') }}</Label>
                        <Input
                            id="inv-desc"
                            v-model="form.description"
                            required
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="inv-qty">{{ t('Quantity') }}</Label>
                            <Input
                                id="inv-qty"
                                v-model="form.quantity"
                                type="number"
                                min="0.01"
                                step="any"
                                required
                            />
                            <InputError :message="form.errors.quantity" />
                        </div>
                        <div class="space-y-1">
                            <Label for="inv-price">{{ t('AED amount') }}</Label>
                            <Input
                                id="inv-price"
                                v-model="form.unit_price"
                                type="number"
                                min="0"
                                step="any"
                                required
                            />
                            <InputError :message="form.errors.unit_price" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="inv-due">{{ t('Due') }}</Label>
                        <Input
                            id="inv-due"
                            v-model="form.due_on"
                            type="date"
                            required
                        />
                        <InputError :message="form.errors.due_on" />
                    </div>
                    <div class="space-y-1">
                        <Label for="inv-acc">{{
                            t('Accounting treatment')
                        }}</Label>
                        <select
                            id="inv-acc"
                            v-model="form.accounting_treatment"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option value="revenue">{{ t('Revenue') }}</option>
                            <option value="refundable_deposit">
                                {{ t('Refundable deposit') }}
                            </option>
                        </select>
                        <InputError
                            :message="form.errors.accounting_treatment"
                        />
                    </div>
                    <div v-if="vatEnabled" class="space-y-1">
                        <Label for="inv-vat">{{ t('VAT treatment') }}</Label>
                        <select
                            id="inv-vat"
                            v-model="form.vat_treatment"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option value="standard">
                                {{ t('Standard 5%') }}
                            </option>
                            <option value="zero_rated">
                                {{ t('Zero-rated 0%') }}
                            </option>
                            <option value="exempt">{{ t('Exempt') }}</option>
                            <option value="out_of_scope">
                                {{ t('Out of scope') }}
                            </option>
                        </select>
                        <InputError :message="form.errors.vat_treatment" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="invoice-form"
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

        <Sheet v-model:open="detailOpen">
            <SheetContent class="w-full gap-0 sm:max-w-xl" side="right">
                <template v-if="detail">
                    <SheetHeader class="border-b">
                        <SheetTitle class="font-display text-2xl font-medium">{{
                            detail.reference
                        }}</SheetTitle>
                        <SheetDescription>
                            {{ detail.status }} · {{ detail.currency }}
                            {{ detail.total
                            }}<template v-if="detail.vat_amount !== null">
                                · {{ t('VAT') }}
                                {{ detail.vat_amount }}</template
                            >
                            · {{ t('credited') }} {{ detail.credited_amount }}
                        </SheetDescription>
                    </SheetHeader>
                    <div class="flex-1 space-y-4 overflow-y-auto p-4">
                        <div
                            v-if="canManageFinance && detail.status === 'draft'"
                            class="grid grid-cols-2 gap-3"
                        >
                            <div class="space-y-1">
                                <Label for="dt-acc">{{
                                    t('Accounting treatment')
                                }}</Label>
                                <select
                                    id="dt-acc"
                                    :value="detail.accounting_treatment ?? ''"
                                    :class="selectClass"
                                    @change="updateTreatment(detail, $event)"
                                >
                                    <option disabled value="">—</option>
                                    <option value="revenue">
                                        {{ t('Revenue') }}
                                    </option>
                                    <option value="refundable_deposit">
                                        {{ t('Refundable deposit') }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="vatEnabled" class="space-y-1">
                                <Label for="dt-vat">{{
                                    t('VAT treatment')
                                }}</Label>
                                <select
                                    id="dt-vat"
                                    :value="detail.vat_treatment ?? ''"
                                    :class="selectClass"
                                    @change="updateVatTreatment(detail, $event)"
                                >
                                    <option disabled value="">—</option>
                                    <option value="standard">5%</option>
                                    <option value="zero_rated">0%</option>
                                    <option value="exempt">
                                        {{ t('Exempt') }}
                                    </option>
                                    <option value="out_of_scope">
                                        {{ t('Out of scope') }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <CustomerCreditNotes
                            :invoice-id="detail.id"
                            :max-credit="Number(detail.creditable_amount)"
                            :eligible="
                                detail.currency === 'AED' &&
                                detail.accounting_treatment === 'revenue' &&
                                ['posted', 'partial', 'paid'].includes(
                                    detail.status,
                                ) &&
                                Number(detail.creditable_amount) > 0
                            "
                            :can-manage="canManageFinance"
                            :notes="detail.credit_notes"
                        />
                        <CustomerRefunds
                            :invoice-id="detail.id"
                            :notes="detail.credit_notes"
                            :refunds="detail.refunds"
                            :refundable-cash-amount="
                                detail.refundable_cash_amount
                            "
                            :can-request="canManageFinance"
                            :can-approve="canApproveCustomerRefunds"
                        />
                    </div>
                </template>
            </SheetContent>
        </Sheet>
    </div>
</template>
