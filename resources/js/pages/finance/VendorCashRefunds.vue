<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    operation_key: crypto.randomUUID(),
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
            request.operation_key = crypto.randomUUID();
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
        { preserveScroll: true, onSuccess: () => decision.reset() },
    );
}
</script>
<template>
    <Head title="Vendor cash refunds" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <Heading
            title="Cash received back from vendors"
            description="Owner-approved receipts against supplier credits and actual overpayments."
        /><Link href="/vendor-bills" class="text-sm underline">{{
            t('Vendor bills')
        }}</Link
        ><Card v-if="canManage"
            ><CardHeader
                ><CardTitle
                    >Submit a receipt request · AED</CardTitle
                ></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="submit"
                >
                    <label class="text-sm"
                        >Vendor bill<select
                            v-model="request.bill_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                            @change="request.credit_note_id = ''"
                        >
                            <option value="">Select bill</option>
                            <option
                                v-for="bill in bills"
                                :key="bill.id"
                                :value="String(bill.id)"
                            >
                                {{ bill.reference }} · Available AED
                                {{ bill.available }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >Supplier credit<select
                            v-model="request.credit_note_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select posted credit</option>
                            <option
                                v-for="credit in selectedBill?.credits"
                                :key="credit.id"
                                :value="String(credit.id)"
                            >
                                {{ credit.reference }} · AED {{ credit.amount }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >{{ t('Amount')
                        }}<Input
                            v-model="request.amount"
                            required
                            inputmode="decimal" /></label
                    ><label class="text-sm"
                        >Cash received on<Input
                            v-model="request.posted_on"
                            required
                            type="date" /></label
                    ><label class="text-sm md:col-span-2"
                        >Receipt reference and reason<Input
                            v-model="request.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in request.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="request.processing"
                        >Submit for owner approval</Button
                    >
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader
                ><CardTitle>Requests and receipts</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!refunds.data.length">No requests recorded.</p>
                <article
                    v-for="refund in refunds.data"
                    :key="refund.id"
                    class="space-y-1 border-b pb-3 text-sm"
                >
                    <p>
                        #{{ refund.id }} · {{ refund.reference }} ·
                        {{ refund.status }} · AED {{ refund.amount }}
                    </p>
                    <p>
                        Bill #{{ refund.vendor_bill_id }} · Credit #{{
                            refund.vendor_credit_note_id
                        }}
                        · Receipt {{ refund.posted_on }} · Requester #{{
                            refund.requested_by
                        }}
                    </p>
                    <p>{{ refund.reason }}</p>
                    <p v-if="refund.rejection_reason">
                        Rejected: {{ refund.rejection_reason }}
                    </p>
                    <p v-if="refund.reversal_reason">
                        Reversed: {{ refund.reversal_reason }}
                    </p>
                    <Button
                        v-if="
                            canApprove &&
                            refund.status === 'submitted' &&
                            refund.requested_by !== actorId
                        "
                        :disabled="approval.processing"
                        @click="approve(refund.id)"
                        >Approve receipt</Button
                    >
                </article>
                <p
                    v-for="(message, field) in approval.errors"
                    :key="field"
                    class="text-destructive text-sm"
                    role="alert"
                >
                    {{ message }}
                </p>
                <Pagination :links="refunds.links" /></CardContent></Card
        ><Card v-if="canApprove"
            ><CardHeader
                ><CardTitle
                    >Reject or reverse with a reason</CardTitle
                ></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="act(false)"
                >
                    <label class="text-sm"
                        >Request ID<Input
                            v-model="decision.refund_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="decision.reason"
                            required
                            maxlength="2000" /></label
                    ><label class="text-sm"
                        >Reversal date (for posted receipts)<Input
                            v-model="decision.posted_on"
                            type="date"
                    /></label>
                    <p
                        v-for="(message, field) in decision.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <div class="flex gap-3">
                        <Button
                            variant="outline"
                            :disabled="decision.processing"
                            >Reject request</Button
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="
                                decision.processing ||
                                !decision.posted_on ||
                                !decision.reason ||
                                !decision.refund_id
                            "
                            @click="act(true)"
                            >Reverse posted receipt</Button
                        >
                    </div>
                </form></CardContent
            ></Card
        >
    </div>
</template>
