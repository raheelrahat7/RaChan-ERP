<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { ref } from 'vue';
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
    reversed_on: string | null;
    reversal_reason: string | null;
};
const props = defineProps<{
    invoiceId: number;
    maxCredit: number;
    eligible: boolean;
    canManage: boolean;
    notes: CreditNote[];
}>();
const creating = ref(false);
const selected = ref<CreditNote | null>(null);
const operation = ref<'post' | 'reverse'>('post');
const draft = useForm({ amount: '', reason: '' });
const action = useForm({
    posted_on: new Date().toISOString().slice(0, 10),
    reason: '',
});
function create(): void {
    draft.post(`/invoices/${props.invoiceId}/credit-notes`, {
        preserveScroll: true,
        onSuccess: () => {
            draft.reset();
            creating.value = false;
        },
    });
}
function select(note: CreditNote, mode: 'post' | 'reverse'): void {
    selected.value = note;
    operation.value = mode;
    action.reset();
    action.clearErrors();
}
function submit(): void {
    if (!selected.value) return;
    action.post(`/credit-notes/${selected.value.id}/${operation.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = null;
        },
    });
}
</script>

<template>
    <div
        v-if="notes.length || (canManage && eligible)"
        class="w-full space-y-3 rounded-md border p-3"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-medium">Credit notes</h3>
            <Button
                v-if="canManage && eligible && !creating"
                size="sm"
                variant="outline"
                @click="creating = true"
                >Create credit note</Button
            >
        </div>
        <form v-if="creating" class="space-y-3" @submit.prevent="create">
            <p class="text-muted-foreground text-sm">
                Create a draft for review, then confirm posting. Maximum credit:
                AED {{ maxCredit.toFixed(2) }} including VAT. Credits above the
                open balance can only be refunded up to cash received and
                require owner approval.
            </p>
            <label class="block text-sm"
                >Credit amount (AED)
                <Input
                    v-model="draft.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    :max="maxCredit"
                    required
                />
            </label>
            <label class="block text-sm"
                >{{ t('Reason')
                }}<Input v-model="draft.reason" maxlength="2000" required />
            </label>
            <p
                v-for="(error, key) in draft.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
            <div class="flex gap-2">
                <Button size="sm" :disabled="draft.processing"
                    >Create draft</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    :disabled="draft.processing"
                    @click="creating = false"
                    >{{ t('Cancel') }}</Button
                >
            </div>
        </form>
        <div
            v-for="note in notes"
            :key="note.id"
            class="space-y-1 border-t pt-2 text-sm"
        >
            <p>
                {{ note.reference }} · {{ note.status }} · AED {{ note.amount
                }}<span v-if="note.posted_on">
                    · VAT AED {{ note.vat_amount }} · Posted
                    {{ note.posted_on.slice(0, 10) }}</span
                >
            </p>
            <p class="break-words">{{ note.reason }}</p>
            <p
                v-if="note.reversed_on"
                class="text-muted-foreground break-words"
            >
                Reversed {{ note.reversed_on.slice(0, 10) }}:
                {{ note.reversal_reason }}
            </p>
            <Button
                v-if="canManage && note.status === 'draft'"
                size="sm"
                variant="outline"
                @click="select(note, 'post')"
                >Review posting</Button
            >
            <Button
                v-if="canManage && note.status === 'posted'"
                size="sm"
                variant="outline"
                @click="select(note, 'reverse')"
                >Reverse</Button
            >
        </div>
        <form
            v-if="selected"
            class="space-y-3 border-t pt-3"
            @submit.prevent="submit"
        >
            <p class="text-sm">
                {{ operation === 'post' ? 'Post' : 'Reverse' }}
                {{ selected.reference }} for AED {{ selected.amount }}? This
                {{ operation === 'post' ? 'reduces' : 'restores' }} the invoice
                balance and records a journal in an open accounting period.
            </p>
            <label class="block text-sm"
                >Posting date
                <Input v-model="action.posted_on" type="date" required />
            </label>
            <label v-if="operation === 'reverse'" class="block text-sm"
                >Reversal reason
                <Input v-model="action.reason" maxlength="2000" required />
            </label>
            <p
                v-for="(error, key) in action.errors"
                :key="key"
                role="alert"
                class="text-destructive text-sm"
            >
                {{ error }}
            </p>
            <div class="flex gap-2">
                <Button size="sm" :disabled="action.processing"
                    >Confirm
                    {{ operation === 'post' ? 'posting' : 'reversal' }}</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    :disabled="action.processing"
                    @click="selected = null"
                    >{{ t('Cancel') }}</Button
                >
            </div>
        </form>
    </div>
</template>
