<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type CreditNote = {
    id: number;
    reference: string;
    status: string;
    amount: string;
    posted_on: string | null;
    refund_available: string;
};
type Refund = {
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
};
const props = defineProps<{
    invoiceId: number;
    notes: CreditNote[];
    refunds: Refund[];
    refundableCashAmount: string;
    canRequest: boolean;
    canApprove: boolean;
}>();
const requestForm = useForm({
    credit_note_id: '',
    amount: '',
    posted_on: new Date().toISOString().slice(0, 10),
    reason: '',
});
const decisionForm = useForm({ reason: '' });
const reversalForm = useForm({
    posted_on: new Date().toISOString().slice(0, 10),
    reason: '',
});
function requestRefund(): void {
    requestForm.post(`/invoices/${props.invoiceId}/refunds`, {
        preserveScroll: true,
        onSuccess: () => requestForm.reset('amount', 'reason'),
    });
}
function approve(refund: Refund): void {
    if (
        !window.confirm(
            `Owner approve AED ${refund.amount} refund ${refund.reference}?`,
        )
    )
        return;
    decisionForm.post(`/customer-refunds/${refund.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => decisionForm.reset(),
    });
}
function reject(refund: Refund): void {
    const reason = window.prompt('Reason for rejecting this refund request');
    if (!reason?.trim()) return;
    decisionForm.reason = reason.trim();
    decisionForm.post(`/customer-refunds/${refund.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => decisionForm.reset(),
    });
}
function reverse(refund: Refund): void {
    const reason = window.prompt('Reason for reversing this refund');
    if (!reason?.trim()) return;
    reversalForm.reason = reason.trim();
    reversalForm.post(`/customer-refunds/${refund.id}/reverse`, {
        preserveScroll: true,
        onSuccess: () => reversalForm.reset(),
    });
}
</script>

<template>
    <section
        v-if="
            refunds.length || (canRequest && Number(refundableCashAmount) > 0)
        "
        class="w-full space-y-3 rounded-md border p-3"
    >
        <h3 class="text-sm font-medium">Customer refunds</h3>
        <p v-if="canRequest" class="text-muted-foreground text-sm">
            Owner approval required. Refunds are limited to cash receipts and
            posted credit notes. Refund postings go to Payment Clearing for bank
            reconciliation.
        </p>
        <form
            v-if="canRequest && Number(refundableCashAmount) > 0"
            class="grid gap-3 md:grid-cols-2"
            @submit.prevent="requestRefund"
        >
            <label class="text-sm">
                Credit note
                <select
                    v-model="requestForm.credit_note_id"
                    required
                    class="border-input bg-background mt-1 block h-9 w-full rounded-md border px-3"
                >
                    <option disabled value="">Choose a posted credit</option>
                    <option
                        v-for="note in notes.filter(
                            (item) =>
                                item.status === 'posted' &&
                                Number(item.refund_available) > 0,
                        )"
                        :key="note.id"
                        :value="String(note.id)"
                    >
                        {{ note.reference }} · refund available AED
                        {{ note.refund_available }}
                    </option>
                </select>
            </label>
            <label class="text-sm">
                Amount (AED)
                <Input
                    v-model="requestForm.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    :max="refundableCashAmount"
                    required
                />
            </label>
            <label class="text-sm">
                Payment date
                <Input v-model="requestForm.posted_on" type="date" required />
            </label>
            <label class="text-sm"
                >{{ t('Reason')
                }}<Input
                    v-model="requestForm.reason"
                    maxlength="2000"
                    required
                />
            </label>
            <p class="text-muted-foreground text-sm md:col-span-2">
                Cash eligible for refund: AED {{ refundableCashAmount }}.
                Deposit and rent-offset payments are excluded.
            </p>
            <p
                v-for="(error, key) in requestForm.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm md:col-span-2"
            >
                {{ error }}
            </p>
            <div class="md:col-span-2">
                <Button :disabled="requestForm.processing"
                    >Request owner-approved refund</Button
                >
            </div>
        </form>
        <div
            v-for="refund in refunds"
            :key="refund.id"
            class="space-y-2 border-t pt-3 text-sm"
        >
            <p>
                {{ refund.reference }} · {{ refund.status }} · AED
                {{ refund.amount
                }}<span v-if="refund.credit_note">
                    · {{ refund.credit_note.reference }}</span
                ><span v-if="refund.posted_on">
                    · {{ refund.posted_on.slice(0, 10) }}</span
                ><span v-if="refund.requester">
                    · requested by {{ refund.requester.name }}</span
                >
            </p>
            <p>{{ refund.reason }}</p>
            <p v-if="refund.rejection_reason" class="text-destructive">
                Rejected: {{ refund.rejection_reason }}
            </p>
            <p v-if="refund.reversal_reason" class="text-muted-foreground">
                Reversed: {{ refund.reversal_reason }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="canApprove && refund.status === 'submitted'"
                    size="sm"
                    :disabled="decisionForm.processing"
                    @click="approve(refund)"
                    >Owner approve and post</Button
                >
                <Button
                    v-if="canApprove && refund.status === 'submitted'"
                    size="sm"
                    variant="outline"
                    :disabled="decisionForm.processing"
                    @click="reject(refund)"
                    >{{ t('Reject') }}</Button
                >
                <Button
                    v-if="canApprove && refund.status === 'posted'"
                    size="sm"
                    variant="outline"
                    :disabled="reversalForm.processing"
                    @click="reverse(refund)"
                    >Reverse refund</Button
                >
            </div>
        </div>
    </section>
</template>
