<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import VendorCreditNotes from '@/components/VendorCreditNotes.vue';
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

type Bill = {
    id: number;
    reference: string;
    description: string;
    status: string;
    accounting_treatment: string | null;
    vat_treatment: string | null;
    vat_amount: string | null;
    input_vat_recoverable: boolean | null;
    total: string;
    paid_amount: string;
    credited_amount: string;
    supplier_credit_balance: number;
    vendor_cash_returned: string;
    creditable_amount: string;
    credit_notes: {
        id: number;
        reference: string;
        status: string;
        amount: string;
        vat_amount: string;
        reason: string;
        posted_on: string | null;
        rejection_reason: string | null;
        reversal_reason: string | null;
        requester?: { name: string } | null;
    }[];
    balance: number;
    due_on: string | null;
    vendor: { name: string } | null;
    property: { name: string } | null;
};
const props = defineProps<{
    bills: Bill[];
    vendors: { id: number; name: string }[];
    properties: { id: number; name: string }[];
    canManage: boolean;
    canApproveSupplierCredits: boolean;
    vatEnabled: boolean;
}>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const createOpen = ref(false);
const detailId = ref<number | null>(null);
const detail = computed(() =>
    props.bills.find((bill) => bill.id === detailId.value),
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
    vendor: string;
    property: string;
    due_on: string;
    total: string;
    balance: string;
    status: string;
    actions: string;
};
const rows = computed<Row[]>(() =>
    props.bills.map((bill) => ({
        id: bill.id,
        reference: bill.reference,
        vendor: bill.vendor?.name ?? '',
        property: bill.property?.name ?? t('Unallocated'),
        due_on: bill.due_on ?? '',
        total: `AED ${bill.total}`,
        balance: `AED ${bill.balance.toFixed(2)}`,
        status: bill.status,
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'vendor', label: t('Vendor'), sortable: true },
    { key: 'property', label: t('Property'), sortable: true },
    { key: 'due_on', label: t('Due'), sortable: true },
    { key: 'total', label: t('Total'), align: 'end' },
    { key: 'balance', label: t('Balance'), align: 'end' },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);
const billOf = (id: number): Bill | undefined =>
    props.bills.find((bill) => bill.id === id);

const form = useForm({
    vendor_id: '',
    property_id: '',
    description: '',
    bill_date: '',
    due_on: '',
    total: '',
    accounting_treatment: '',
    vat_treatment: '',
    input_vat_recoverable: false,
});
function create(): void {
    form.post('/vendor-bills', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
}
function post(bill: Bill): void {
    router.post(`/vendor-bills/${bill.id}/post`, {}, { preserveScroll: true });
}
function updateTreatment(bill: Bill, event: Event): void {
    const accounting_treatment = (event.target as HTMLSelectElement).value;
    if (accounting_treatment)
        router.put(
            `/vendor-bills/${bill.id}/accounting-treatment`,
            {
                accounting_treatment,
                vat_treatment: bill.vat_treatment,
                input_vat_recoverable: bill.input_vat_recoverable,
            },
            { preserveScroll: true },
        );
}
function updateVatTreatment(bill: Bill, event: Event): void {
    const vat_treatment = (event.target as HTMLSelectElement).value;
    const input_vat_recoverable =
        vat_treatment === 'standard'
            ? confirm('Is the input VAT recoverable?')
            : false;
    if (vat_treatment && bill.accounting_treatment)
        router.put(
            `/vendor-bills/${bill.id}/accounting-treatment`,
            {
                accounting_treatment: bill.accounting_treatment,
                vat_treatment,
                input_vat_recoverable,
            },
            { preserveScroll: true },
        );
}
function pay(bill: Bill): void {
    const amount = prompt('Payment amount (AED)');
    if (amount)
        router.post(
            `/vendor-bills/${bill.id}/payments`,
            { amount },
            { preserveScroll: true },
        );
}
</script>

<template>
    <Head :title="t('Vendor bills')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Vendor bills"
            description="Property-allocated payables and vendor payments in AED."
        >
            <template #actions>
                <Link
                    href="/finance/vendor-cash-refunds"
                    class="text-sm underline"
                    >{{ t('Cash received back from vendors') }}</Link
                >
            </template>
        </PageHeader>

        <CrmSettingsTable
            :show-title="false"
            title="Vendor bills"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            add-label="New vendor bill"
            :selectable="false"
            searchable
            :can-edit="canManage"
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
            </template>
            <template #cell-status="{ row }">
                <Badge variant="secondary">{{ row.status }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <div
                    v-if="canManage && billOf(row.id)"
                    class="flex justify-end gap-2"
                >
                    <Button
                        v-if="row.status === 'draft'"
                        size="sm"
                        @click="post(billOf(row.id)!)"
                        >{{ t('Post') }}</Button
                    >
                    <Button
                        v-if="['posted', 'partial'].includes(row.status)"
                        size="sm"
                        variant="outline"
                        @click="pay(billOf(row.id)!)"
                        >{{ t('Record payment') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="createOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('New vendor bill')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Property-allocated payables and vendor payments in AED.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="bill-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="vb-vendor">{{ t('Vendor') }}</Label>
                        <select
                            id="vb-vendor"
                            v-model="form.vendor_id"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="vendor in vendors"
                                :key="vendor.id"
                                :value="String(vendor.id)"
                            >
                                {{ vendor.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.vendor_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="vb-property">{{ t('Property') }}</Label>
                        <select
                            id="vb-property"
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
                        <InputError :message="form.errors.property_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="vb-desc">{{ t('Bill description') }}</Label>
                        <Input
                            id="vb-desc"
                            v-model="form.description"
                            required
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="space-y-1">
                        <Label for="vb-total">{{ t('AED total') }}</Label>
                        <Input
                            id="vb-total"
                            v-model="form.total"
                            type="number"
                            min="0.01"
                            step="any"
                            required
                        />
                        <InputError :message="form.errors.total" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="vb-date">{{ t('Bill date') }}</Label
                            ><Input
                                id="vb-date"
                                v-model="form.bill_date"
                                type="date"
                                required
                            /><InputError :message="form.errors.bill_date" />
                        </div>
                        <div class="space-y-1">
                            <Label for="vb-due">{{ t('Due') }}</Label
                            ><Input
                                id="vb-due"
                                v-model="form.due_on"
                                type="date"
                            /><InputError :message="form.errors.due_on" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="vb-acc">{{
                            t('Accounting treatment')
                        }}</Label>
                        <select
                            id="vb-acc"
                            v-model="form.accounting_treatment"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option value="operating_expense">
                                {{ t('Operating expense') }}
                            </option>
                            <option value="capital_asset">
                                {{ t('Capital asset') }}
                            </option>
                        </select>
                        <InputError
                            :message="form.errors.accounting_treatment"
                        />
                    </div>
                    <div v-if="vatEnabled" class="space-y-1">
                        <Label for="vb-vat">{{ t('VAT treatment') }}</Label>
                        <select
                            id="vb-vat"
                            v-model="form.vat_treatment"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option value="standard">
                                {{ t('Standard 5% (gross total)') }}
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
                    <label
                        v-if="vatEnabled && form.vat_treatment === 'standard'"
                        class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.input_vat_recoverable"
                            type="checkbox"
                        />{{ t('Recoverable input VAT') }}</label
                    >
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="bill-form"
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
                            {{ detail.vendor?.name }} · {{ detail.status }} ·
                            AED {{ detail.total
                            }}<template v-if="detail.vat_amount !== null">
                                · {{ t('VAT') }} {{ detail.vat_amount
                                }}<template v-if="detail.input_vat_recoverable">
                                    {{ t('recoverable') }}</template
                                ></template
                            >
                        </SheetDescription>
                    </SheetHeader>
                    <div class="flex-1 space-y-4 overflow-y-auto p-4">
                        <p class="text-muted-foreground text-sm">
                            {{ t('paid') }} AED {{ detail.paid_amount }} ·
                            {{ t('credited') }} AED
                            {{ detail.credited_amount }} ·
                            {{ t('balance') }} AED {{ detail.balance.toFixed(2)
                            }}<template
                                v-if="Number(detail.vendor_cash_returned) > 0"
                            >
                                · {{ t('Cash returned') }} AED
                                {{ detail.vendor_cash_returned }}</template
                            ><template
                                v-if="detail.supplier_credit_balance > 0"
                            >
                                · {{ t('supplier credit') }} AED
                                {{
                                    detail.supplier_credit_balance.toFixed(2)
                                }}</template
                            >
                        </p>
                        <div
                            v-if="canManage && detail.status === 'draft'"
                            class="grid grid-cols-2 gap-3"
                        >
                            <div class="space-y-1">
                                <Label for="vd-acc">{{
                                    t('Accounting treatment')
                                }}</Label>
                                <select
                                    id="vd-acc"
                                    :value="detail.accounting_treatment ?? ''"
                                    :class="selectClass"
                                    @change="updateTreatment(detail, $event)"
                                >
                                    <option disabled value="">—</option>
                                    <option value="operating_expense">
                                        {{ t('Operating expense') }}
                                    </option>
                                    <option value="capital_asset">
                                        {{ t('Capital asset') }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="vatEnabled" class="space-y-1">
                                <Label for="vd-vat">{{
                                    t('VAT treatment')
                                }}</Label>
                                <select
                                    id="vd-vat"
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
                        <VendorCreditNotes
                            :bill-id="detail.id"
                            :creditable-amount="detail.creditable_amount"
                            :notes="detail.credit_notes"
                            :can-request="
                                canManage &&
                                ['posted', 'partial', 'paid'].includes(
                                    detail.status,
                                )
                            "
                            :can-approve="canApproveSupplierCredits"
                        />
                    </div>
                </template>
            </SheetContent>
        </Sheet>
    </div>
</template>
