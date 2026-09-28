<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, router, useForm } from '@inertiajs/vue3';
import CustomerCreditNotes from '@/components/CustomerCreditNotes.vue';
import CustomerRefunds from '@/components/CustomerRefunds.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
defineProps<{
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
        onSuccess: () => form.reset(),
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
</script>
<template>
    <Head title="Invoices" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Invoices"
            description="AED receivables and payment allocation."
        /><Card v-if="canManageFinance"
            ><CardHeader><CardTitle>Create invoice</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap gap-3"
                    @submit.prevent="createInvoice"
                >
                    <Input
                        v-model="form.description"
                        placeholder="Description"
                        required
                    /><Input
                        v-model="form.quantity"
                        type="number"
                        min="0.01"
                        required
                    /><Input
                        v-model="form.unit_price"
                        type="number"
                        min="0"
                        placeholder="AED amount"
                        required
                    /><Input
                        v-model="form.due_on"
                        type="date"
                        required
                    /><select
                        v-model="form.accounting_treatment"
                        aria-label="Accounting treatment"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">
                            {{ t('Accounting treatment') }}
                        </option>
                        <option value="revenue">Revenue</option>
                        <option value="refundable_deposit">
                            Refundable deposit
                        </option></select
                    ><select
                        v-if="vatEnabled"
                        v-model="form.vat_treatment"
                        aria-label="VAT treatment"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">
                            {{ t('VAT treatment') }}
                        </option>
                        <option value="standard">Standard 5%</option>
                        <option value="zero_rated">Zero-rated 0%</option>
                        <option value="exempt">Exempt</option>
                        <option value="out_of_scope">
                            Out of scope
                        </option></select
                    ><Button :disabled="form.processing">Create draft</Button>
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader
                ><CardTitle>{{ t('Invoices') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!invoices.length"
                    class="text-muted-foreground text-sm"
                >
                    No invoices yet.
                </p>
                <div
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 last:border-0"
                >
                    <span
                        >{{ invoice.reference }} · AED {{ invoice.total }}</span
                    >
                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            v-if="
                                canManageFinance && invoice.status === 'draft'
                            "
                            :value="invoice.accounting_treatment ?? ''"
                            :aria-label="`Accounting treatment for ${invoice.reference}`"
                            class="border-input h-9 rounded-md border px-2 text-sm"
                            @change="updateTreatment(invoice, $event)"
                        >
                            <option disabled value="">Select treatment</option>
                            <option value="revenue">Revenue</option>
                            <option value="refundable_deposit">
                                Refundable deposit
                            </option>
                        </select>
                        <select
                            v-if="
                                vatEnabled &&
                                canManageFinance &&
                                invoice.status === 'draft'
                            "
                            :value="invoice.vat_treatment ?? ''"
                            :aria-label="`VAT treatment for ${invoice.reference}`"
                            class="border-input h-9 rounded-md border px-2 text-sm"
                            @change="updateVatTreatment(invoice, $event)"
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
                            >{{ invoice.status
                            }}<template v-if="invoice.vat_amount !== null">
                                · VAT AED {{ invoice.vat_amount }}</template
                            >
                            · paid AED {{ invoice.paid_amount }} · credited AED
                            {{ invoice.credited_amount }} · balance AED
                            {{ invoice.balance.toFixed(2) }}</span
                        ><span
                            v-if="invoice.is_overdue"
                            class="text-destructive text-sm"
                            >Overdue</span
                        ><Button
                            v-if="
                                canManageFinance && invoice.status === 'draft'
                            "
                            size="sm"
                            @click="postInvoice(invoice)"
                            >Post</Button
                        ><Button
                            v-if="
                                canManageFinance &&
                                ['posted', 'partial'].includes(invoice.status)
                            "
                            size="sm"
                            variant="outline"
                            @click="payInvoice(invoice)"
                            >Record payment</Button
                        >
                    </div>
                    <CustomerCreditNotes
                        :invoice-id="invoice.id"
                        :max-credit="Number(invoice.creditable_amount)"
                        :eligible="
                            invoice.currency === 'AED' &&
                            invoice.accounting_treatment === 'revenue' &&
                            ['posted', 'partial', 'paid'].includes(
                                invoice.status,
                            ) &&
                            Number(invoice.creditable_amount) > 0
                        "
                        :can-manage="canManageFinance"
                        :notes="invoice.credit_notes"
                    />
                    <CustomerRefunds
                        :invoice-id="invoice.id"
                        :notes="invoice.credit_notes"
                        :refunds="invoice.refunds"
                        :refundable-cash-amount="invoice.refundable_cash_amount"
                        :can-request="canManageFinance"
                        :can-approve="canApproveCustomerRefunds"
                    /></div></CardContent
        ></Card>
    </div>
</template>
