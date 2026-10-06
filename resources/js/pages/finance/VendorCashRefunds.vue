<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { operationKey } from '@/lib/workflows';

const { t } = useLocale();
const props = defineProps<{
    canManage: boolean;
    canApprove: boolean;
    actorId: number;
    bills: {
        id: number;
        reference: string;
        description: string;
        available: string;
        credits: { id: number; reference: string; amount: string }[];
    }[];
    refunds: {
        data: {
            id: number;
            reference: string;
            vendor_bill_id: number;
            vendor_credit_note_id: number;
            amount: string;
            reason: string;
            status: string;
            requested_by: number;
            approved_by: number | null;
            posted_on: string;
            reversed_on: string | null;
            rejection_reason: string | null;
            reversal_reason: string | null;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
}>();
const request = useForm({
    bill_id: '',
    credit_note_id: '',
    amount: '',
    posted_on: '',
    reason: '',
    operation_key: operationKey(),
});
const decision = useForm({ refund_id: '', reason: '', posted_on: '' });
const approval = useForm({});
const selectedBill = computed(() =>
    props.bills.find((b) => String(b.id) === request.bill_id),
);
function submit(): void {
    request.post(`/finance/vendor-cash-refunds/bills/${request.bill_id}`, {
        preserveScroll: true,
        onSuccess: () => {
            request.reset('amount', 'reason');
            request.operation_key = operationKey();
            requestOpen.value = false;
        },
    });
}
function approve(id: number): void {
    approval.post(`/finance/vendor-cash-refunds/${id}/approve`, {
        preserveScroll: true,
    });
}
function act(reverse: boolean): void {
    decision.post(
        `/finance/vendor-cash-refunds/${decision.refund_id}/${reverse ? 'reverse' : 'reject'}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                decision.reset();
                decisionOpen.value = false;
            },
        },
    );
}
const requestOpen = ref(false);
const decisionOpen = ref(false);
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
type Refund = (typeof props.refunds.data)[number];
type Row = {
    id: number;
    reference: string;
    amount: string;
    links: string;
    receipt: string;
    status: string;
    note: string;
    actions: string;
};
const rows = computed<Row[]>(() =>
    props.refunds.data.map((refund) => ({
        id: refund.id,
        reference: refund.reference,
        amount: `AED ${refund.amount}`,
        links: `${t('Bill')} #${refund.vendor_bill_id} · ${t('Credit')} #${refund.vendor_credit_note_id}`,
        receipt: refund.posted_on,
        status: refund.status,
        note: [
            refund.reason,
            refund.rejection_reason
                ? `${t('Rejected')}: ${refund.rejection_reason}`
                : '',
            refund.reversal_reason
                ? `${t('Reversed')}: ${refund.reversal_reason}`
                : '',
        ]
            .filter(Boolean)
            .join(' · '),
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference'), sortable: true },
    { key: 'amount', label: t('Amount'), align: 'end' },
    { key: 'links', label: t('Bill and credit') },
    { key: 'receipt', label: t('Receipt date'), sortable: true },
    { key: 'status', label: t('Status') },
    { key: 'note', label: t('Reason') },
    { key: 'actions', label: '' },
]);
const refundOf = (id: number): Refund | undefined =>
    props.refunds.data.find((item) => item.id === id);
function openDecision(id: number): void {
    decision.reset();
    decision.refund_id = String(id);
    decisionOpen.value = true;
}
</script>

<template>
    <Head :title="t('Vendor cash refunds')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Cash received back from vendors"
            description="Owner-approved receipts against supplier credits and actual overpayments."
        >
            <template #actions>
                <Link href="/vendor-bills" class="text-sm underline">{{
                    t('Vendor bills')
                }}</Link>
            </template>
        </PageHeader>

        <CrmSettingsTable
            :show-title="false"
            title="Requests and receipts"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            add-label="Submit a receipt request"
            :selectable="false"
            searchable
            :can-edit="canManage"
            @add="requestOpen = true"
        >
            <template #cell-status="{ row }"
                ><Badge variant="secondary">{{ row.status }}</Badge></template
            >
            <template #cell-actions="{ row }">
                <div
                    v-if="canApprove && refundOf(row.id)"
                    class="flex justify-end gap-2"
                >
                    <Button
                        v-if="
                            row.status === 'submitted' &&
                            refundOf(row.id)!.requested_by !== actorId
                        "
                        size="sm"
                        :disabled="approval.processing"
                        @click="approve(row.id)"
                        >{{ t('Approve receipt') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="openDecision(row.id)"
                        >{{ t('Reject or reverse') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>
        <InputError
            v-for="(message, field) in approval.errors"
            :key="field"
            :message="message"
        />
        <Pagination :links="refunds.links" />

        <Sheet v-model:open="requestOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium"
                        >{{ t('Submit a receipt request') }} · AED</SheetTitle
                    >
                    <SheetDescription>{{
                        t(
                            'Owner-approved receipts against supplier credits and actual overpayments.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="refund-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="submit"
                >
                    <div class="space-y-1">
                        <Label for="cr-bill">{{ t('Vendor bill') }}</Label>
                        <select
                            id="cr-bill"
                            v-model="request.bill_id"
                            required
                            :class="selectClass"
                            @change="request.credit_note_id = ''"
                        >
                            <option value="">{{ t('Select bill') }}</option>
                            <option
                                v-for="bill in bills"
                                :key="bill.id"
                                :value="String(bill.id)"
                            >
                                {{ bill.reference }} · {{ t('Available') }} AED
                                {{ bill.available }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="cr-credit">{{
                            t('Supplier credit')
                        }}</Label>
                        <select
                            id="cr-credit"
                            v-model="request.credit_note_id"
                            required
                            :class="selectClass"
                        >
                            <option value="">
                                {{ t('Select posted credit') }}
                            </option>
                            <option
                                v-for="credit in selectedBill?.credits"
                                :key="credit.id"
                                :value="String(credit.id)"
                            >
                                {{ credit.reference }} · AED {{ credit.amount }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="cr-amount">{{ t('Amount') }}</Label
                        ><Input
                            id="cr-amount"
                            v-model="request.amount"
                            required
                            inputmode="decimal"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="cr-date">{{ t('Cash received on') }}</Label
                        ><Input
                            id="cr-date"
                            v-model="request.posted_on"
                            required
                            type="date"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="cr-reason">{{
                            t('Receipt reference and reason')
                        }}</Label
                        ><Input
                            id="cr-reason"
                            v-model="request.reason"
                            required
                            maxlength="2000"
                        />
                    </div>
                    <InputError
                        v-for="(message, field) in request.errors"
                        :key="field"
                        :message="message"
                    />
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="refund-form"
                        :disabled="request.processing"
                        >{{ t('Submit for owner approval') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="requestOpen = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <Dialog v-model:open="decisionOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        t('Reject or reverse with a reason')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        refundOf(Number(decision.refund_id))?.reference
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="act(false)">
                    <div class="space-y-1">
                        <Label for="cd-reason">{{ t('Reason') }}</Label
                        ><Input
                            id="cd-reason"
                            v-model="decision.reason"
                            required
                            maxlength="2000"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="cd-date">{{
                            t('Reversal date (for posted receipts)')
                        }}</Label
                        ><Input
                            id="cd-date"
                            v-model="decision.posted_on"
                            type="date"
                        />
                    </div>
                    <InputError
                        v-for="(message, field) in decision.errors"
                        :key="field"
                        :message="message"
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            :disabled="decision.processing"
                            >{{ t('Reject request') }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="
                                decision.processing ||
                                !decision.posted_on ||
                                !decision.reason ||
                                !decision.refund_id
                            "
                            @click="act(true)"
                            >{{ t('Reverse posted receipt') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
