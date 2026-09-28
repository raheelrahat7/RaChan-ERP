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
    vat_amount: string;
    reason: string;
    posted_on: string | null;
    rejection_reason: string | null;
    reversal_reason: string | null;
    requester?: { name: string } | null;
};
const props = defineProps<{
    billId: number;
    creditableAmount: string;
    notes: CreditNote[];
    canRequest: boolean;
    canApprove: boolean;
}>();
const requestForm = useForm({
    amount: '',
    posted_on: new Date().toISOString().slice(0, 10),
    reason: '',
});
const decisionForm = useForm({ reason: '' });
const reversalForm = useForm({
    posted_on: new Date().toISOString().slice(0, 10),
    reason: '',
});
function requestCredit(): void {
    requestForm.post(`/vendor-bills/${props.billId}/credit-notes`, {
        preserveScroll: true,
        onSuccess: () => requestForm.reset('amount', 'reason'),
    });
}
function approve(note: CreditNote): void {
    if (
        !window.confirm(
            `Owner approve AED ${note.amount} supplier credit ${note.reference}?`,
        )
    )
        return;
    decisionForm.post(`/vendor-credit-notes/${note.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => decisionForm.reset(),
    });
}
function reject(note: CreditNote): void {
    const reason = window.prompt('Reason for rejecting this supplier credit');
    if (!reason?.trim()) return;
    decisionForm.reason = reason.trim();
    decisionForm.post(`/vendor-credit-notes/${note.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => decisionForm.reset(),
    });
}
function reverse(note: CreditNote): void {
    const reason = window.prompt('Reason for reversing this supplier credit');
    if (!reason?.trim()) return;
    reversalForm.reason = reason.trim();
    reversalForm.post(`/vendor-credit-notes/${note.id}/reverse`, {
        preserveScroll: true,
        onSuccess: () => reversalForm.reset(),
    });
}
</script>

<template>
    <section
        v-if="notes.length || (canRequest && Number(creditableAmount) > 0)"
        class="w-full space-y-3 rounded-md border p-3"
    >
        <h3 class="text-sm font-medium">Supplier credit notes</h3>
        <p v-if="canRequest" class="text-muted-foreground text-sm">
            Owner approval required. Credits use this bill's original expense or
            asset and recoverable VAT accounts. Any excess over the payable
            balance stays unapplied.
        </p>
        <form
            v-if="canRequest && Number(creditableAmount) > 0"
            class="grid gap-3 md:grid-cols-3"
            @submit.prevent="requestCredit"
        >
            <label class="text-sm">
                Credit amount (AED)
                <Input
                    v-model="requestForm.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    :max="creditableAmount"
                    required
                />
            </label>
            <label class="text-sm">
                Credit date
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
            <p
                v-for="(error, key) in requestForm.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm md:col-span-3"
            >
                {{ error }}
            </p>
            <div class="md:col-span-3">
                <Button :disabled="requestForm.processing"
                    >Request owner-approved credit</Button
                >
            </div>
        </form>
        <div
            v-for="note in notes"
            :key="note.id"
            class="space-y-2 border-t pt-3 text-sm"
        >
            <p>
                {{ note.reference }} · {{ note.status }} · AED
                {{ note.amount }} · input VAT AED {{ note.vat_amount
                }}<span v-if="note.posted_on">
                    · {{ note.posted_on.slice(0, 10) }}</span
                ><span v-if="note.requester">
                    · requested by {{ note.requester.name }}</span
                >
            </p>
            <p>{{ note.reason }}</p>
            <p v-if="note.rejection_reason" class="text-destructive">
                Rejected: {{ note.rejection_reason }}
            </p>
            <p v-if="note.reversal_reason" class="text-muted-foreground">
                Reversed: {{ note.reversal_reason }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="canApprove && note.status === 'submitted'"
                    size="sm"
                    :disabled="decisionForm.processing"
                    @click="approve(note)"
                    >Owner approve and post</Button
                >
                <Button
                    v-if="canApprove && note.status === 'submitted'"
                    size="sm"
                    variant="outline"
                    :disabled="decisionForm.processing"
                    @click="reject(note)"
                    >{{ t('Reject') }}</Button
                >
                <Button
                    v-if="canApprove && note.status === 'posted'"
                    size="sm"
                    variant="outline"
                    :disabled="reversalForm.processing"
                    @click="reverse(note)"
                    >Reverse</Button
                >
            </div>
        </div>
    </section>
</template>
