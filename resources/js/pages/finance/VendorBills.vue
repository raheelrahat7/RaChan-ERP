<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import VendorCreditNotes from '@/components/VendorCreditNotes.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

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
defineProps<{
    bills: Bill[];
    vendors: { id: number; name: string }[];
    properties: { id: number; name: string }[];
    canManage: boolean;
    canApproveSupplierCredits: boolean;
    vatEnabled: boolean;
}>();

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
        onSuccess: () => form.reset(),
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
    <Head title="Vendor bills" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Vendor bills"
            description="Property-allocated payables and vendor payments in AED."
        />
        <Link href="/finance/vendor-cash-refunds" class="text-sm underline"
            >Cash received back from vendors</Link
        >
        <Card v-if="canManage">
            <CardHeader><CardTitle>New vendor bill</CardTitle></CardHeader>
            <CardContent>
                <form class="flex flex-wrap gap-3" @submit.prevent="create">
                    <select
                        v-model="form.vendor_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Vendor') }}</option>
                        <option
                            v-for="vendor in vendors"
                            :key="vendor.id"
                            :value="String(vendor.id)"
                        >
                            {{ vendor.name }}
                        </option>
                    </select>
                    <select
                        v-model="form.property_id"
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
                    <Input
                        v-model="form.description"
                        placeholder="Bill description"
                        required
                    />
                    <Input
                        v-model="form.total"
                        type="number"
                        min="0.01"
                        placeholder="AED total"
                        required
                    />
                    <Input v-model="form.bill_date" type="date" required />
                    <Input v-model="form.due_on" type="date" />
                    <select
                        v-model="form.accounting_treatment"
                        aria-label="Accounting treatment"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">
                            {{ t('Accounting treatment') }}
                        </option>
                        <option value="operating_expense">
                            Operating expense
                        </option>
                        <option value="capital_asset">Capital asset</option>
                    </select>
                    <select
                        v-if="vatEnabled"
                        v-model="form.vat_treatment"
                        aria-label="VAT treatment"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">
                            {{ t('VAT treatment') }}
                        </option>
                        <option value="standard">
                            Standard 5% (gross total)
                        </option>
                        <option value="zero_rated">Zero-rated 0%</option>
                        <option value="exempt">Exempt</option>
                        <option value="out_of_scope">Out of scope</option>
                    </select>
                    <label
                        v-if="vatEnabled && form.vat_treatment === 'standard'"
                        class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.input_vat_recoverable"
                            type="checkbox"
                        />Recoverable input VAT</label
                    >
                    <Button :disabled="form.processing">Create draft</Button>
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>{{ t('Vendor bills') }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p v-if="!bills.length" class="text-muted-foreground text-sm">
                    No vendor bills yet.
                </p>
                <div
                    v-for="bill in bills"
                    :key="bill.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 last:border-0"
                >
                    <span
                        >{{ bill.reference }} · {{ bill.vendor?.name }} ·
                        {{ bill.property?.name || 'Unallocated' }} · AED
                        {{ bill.total }}</span
                    >
                    <div class="flex items-center gap-2">
                        <select
                            v-if="canManage && bill.status === 'draft'"
                            :value="bill.accounting_treatment ?? ''"
                            :aria-label="`Accounting treatment for ${bill.reference}`"
                            class="border-input h-9 rounded-md border px-2 text-sm"
                            @change="updateTreatment(bill, $event)"
                        >
                            <option disabled value="">Select treatment</option>
                            <option value="operating_expense">
                                Operating expense
                            </option>
                            <option value="capital_asset">Capital asset</option>
                        </select>
                        <select
                            v-if="
                                vatEnabled &&
                                canManage &&
                                bill.status === 'draft'
                            "
                            :value="bill.vat_treatment ?? ''"
                            :aria-label="`VAT treatment for ${bill.reference}`"
                            class="border-input h-9 rounded-md border px-2 text-sm"
                            @change="updateVatTreatment(bill, $event)"
                        >
                            <option disabled value="">
                                {{ t('VAT treatment') }}
                            </option>
                            <option value="standard">5%</option>
                            <option value="zero_rated">0%</option>
                            <option value="exempt">Exempt</option>
                            <option value="out_of_scope">Out of scope</option>
                        </select>
                        <span class="text-muted-foreground"
                            >{{ bill.status
                            }}<template v-if="bill.vat_amount !== null">
                                · VAT AED {{ bill.vat_amount
                                }}<template v-if="bill.input_vat_recoverable">
                                    recoverable</template
                                ></template
                            >
                            · paid AED {{ bill.paid_amount }} · credited AED
                            {{ bill.credited_amount }} · balance AED
                            {{ bill.balance.toFixed(2)
                            }}<span
                                v-if="Number(bill.vendor_cash_returned) > 0"
                            >
                                · Cash returned AED
                                {{ bill.vendor_cash_returned }}</span
                            ><template v-if="bill.supplier_credit_balance > 0">
                                · supplier credit AED
                                {{ bill.supplier_credit_balance.toFixed(2) }}
                            </template></span
                        ><Button
                            v-if="canManage && bill.status === 'draft'"
                            size="sm"
                            @click="post(bill)"
                            >Post</Button
                        ><Button
                            v-if="
                                canManage &&
                                ['posted', 'partial'].includes(bill.status)
                            "
                            size="sm"
                            variant="outline"
                            @click="pay(bill)"
                            >Record payment</Button
                        >
                        <VendorCreditNotes
                            :bill-id="bill.id"
                            :creditable-amount="bill.creditable_amount"
                            :notes="bill.credit_notes"
                            :can-request="
                                canManage &&
                                ['posted', 'partial', 'paid'].includes(
                                    bill.status,
                                )
                            "
                            :can-approve="canApproveSupplierCredits"
                        />
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
