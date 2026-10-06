<script setup lang="ts">
import { uuid } from '@/lib/uuid';
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
type Item = {
    id: number;
    reference: string;
    description: string;
    unit: string;
    quantity: string;
    unit_rate: string;
    amount: string;
    completed: string;
    reserved: string;
};
const SECTIONS = [
    { key: 'overview', label: 'Overview and BOQ' },
    { key: 'progress', label: 'Progress' },
    { key: 'claims', label: 'Claims' },
    { key: 'lifecycle', label: 'Project lifecycle' },
] as const;
const tab = ref<(typeof SECTIONS)[number]['key']>('overview');
const props = defineProps<{
    project: {
        id: number;
        reference: string;
        title: string;
        status: string;
        budget: string;
        planned: string;
        reserved_claims: string;
        over_budget: boolean;
    };
    vatEnabled: boolean;
    canApprove: boolean;
    canFinance: boolean;
    actorId: number;
    vendors: { id: number; name: string }[];
    items: Item[];
    progress: {
        id: number;
        construction_boq_item_id: number;
        note: string;
        quantity: string;
        voided_at: string | null;
        void_reason: string | null;
    }[];
    claims: {
        data: {
            id: number;
            reference: string;
            status: string;
            reason: string;
            requested_by: number;
            approved_by: number | null;
            rejection_reason: string | null;
            vendor_bill_id: number | null;
            bill_status: string | null;
            amount: string;
            claimed_on: string;
            lines: { item_id: number; quantity: string; amount: string }[];
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
}>();
const base = `/operations/projects/${props.project.id}`;
const itemForm = useForm({
    reference: '',
    description: '',
    unit: 'pcs',
    quantity: '',
    unit_rate: '',
});
const progressForm = useForm({
    item_id: '',
    quantity: '',
    note: '',
    operation_key: uuid(),
});
const voidForm = useForm({ progress_id: '', reason: '' });
const claimForm = useForm({
    vendor_id: '',
    claimed_on: '',
    reason: '',
    operation_key: uuid(),
    lines: [] as { item_id: number; quantity: string }[],
});
const claimQuantities = ref<Record<number, string>>({});
const approval = useForm({});
const rejection = useForm({ claim_id: '', reason: '' });
const billForm = useForm({
    claim_id: '',
    bill_date: '',
    due_on: '',
    accounting_treatment: 'operating_expense',
    vat_treatment: '',
    input_vat_recoverable: false,
});
const statusForm = useForm({ status: props.project.status, reason: '' });
function addItem(): void {
    itemForm.post(`${base}/boq`, {
        preserveScroll: true,
        onSuccess: () => itemForm.reset(),
    });
}
function progress(): void {
    progressForm.post(`/operations/boq/${progressForm.item_id}/progress`, {
        preserveScroll: true,
        onSuccess: () => {
            progressForm.reset('quantity', 'note');
            progressForm.operation_key = uuid();
        },
    });
}
function voidProgress(): void {
    voidForm.post(`/operations/boq-progress/${voidForm.progress_id}/void`, {
        preserveScroll: true,
        onSuccess: () => voidForm.reset(),
    });
}
function claim(): void {
    claimForm.lines = Object.entries(claimQuantities.value)
        .filter(([, quantity]) => quantity.trim() !== '')
        .map(([id, quantity]) => ({ item_id: Number(id), quantity }));
    claimForm.post(`${base}/claims`, {
        preserveScroll: true,
        onSuccess: () => {
            claimForm.reset('reason', 'lines');
            claimForm.operation_key = uuid();
            claimQuantities.value = {};
        },
    });
}
function approve(id: number): void {
    approval.post(`/operations/contractor-claims/${id}/approve`, {
        preserveScroll: true,
    });
}
function reject(): void {
    rejection.post(
        `/operations/contractor-claims/${rejection.claim_id}/reject`,
        { preserveScroll: true, onSuccess: () => rejection.reset() },
    );
}
function bill(): void {
    billForm.post(`/operations/contractor-claims/${billForm.claim_id}/bill`, {
        preserveScroll: true,
        onSuccess: () => billForm.reset(),
    });
}
function status(): void {
    statusForm.post(`${base}/status`, {
        preserveScroll: true,
        onSuccess: () => statusForm.reset('reason'),
    });
}
</script>
<template>
    <Head :title="project.reference" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <PageHeader
            :translate="false"
            :title="`${project.reference} · ${project.title}`"
            :description="`${t('Project status')}: ${project.status}`"
        >
            <template #actions>
                <Link href="/operations/projects" class="text-sm underline">{{
                    t('All projects')
                }}</Link>
            </template>
        </PageHeader>
        <nav :aria-label="t('Sections')" class="flex flex-wrap gap-2">
            <button
                v-for="item in SECTIONS"
                :key="item.key"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="
                    item.key === tab
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted border'
                "
                :aria-current="item.key === tab ? 'page' : undefined"
                @click="tab = item.key"
            >
                {{ t(item.label) }}
            </button>
        </nav>
        <Card v-show="tab === 'overview'"
            ><CardHeader
                ><CardTitle>Gross estimates · AED</CardTitle></CardHeader
            ><CardContent
                ><p>
                    Budget {{ project.budget }} · BOQ estimate
                    {{ project.planned }} · Submitted/approved/billed claims
                    {{ project.reserved_claims }}
                </p>
                <p
                    v-if="project.over_budget"
                    class="text-destructive text-sm"
                    role="status"
                >
                    BOQ estimate exceeds the advisory project budget.
                </p>
                <p class="text-muted-foreground text-sm">
                    Budgets are advisory. Rates include VAT where applicable.
                    Claim amounts are fixed at submission; finance selects
                    treatment after owner approval.
                </p></CardContent
            ></Card
        >
        <Card v-show="tab === 'overview'"
            ><CardHeader><CardTitle>BOQ items</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!items.length">No BOQ items.</p>
                <article
                    v-for="item in items"
                    :key="item.id"
                    class="border-b pb-3 text-sm"
                >
                    <p>
                        #{{ item.id }} · {{ item.reference }} ·
                        {{ item.description }}
                    </p>
                    <p>
                        {{ item.quantity }} {{ item.unit }} × AED
                        {{ item.unit_rate }} = AED {{ item.amount }} · Completed
                        {{ item.completed }} · Reserved for claims
                        {{ item.reserved }}
                    </p>
                </article>
                <form
                    v-if="project.status === 'active'"
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="addItem"
                >
                    <label class="text-sm"
                        >{{ t('Reference')
                        }}<Input
                            v-model="itemForm.reference"
                            required
                            maxlength="100" /></label
                    ><label class="text-sm"
                        >{{ t('Description')
                        }}<Input
                            v-model="itemForm.description"
                            required
                            maxlength="255" /></label
                    ><label class="text-sm"
                        >{{ t('Unit')
                        }}<Input
                            v-model="itemForm.unit"
                            required
                            maxlength="30" /></label
                    ><label class="text-sm"
                        >Planned quantity<Input
                            v-model="itemForm.quantity"
                            required
                            inputmode="decimal" /></label
                    ><label class="text-sm"
                        >Gross unit rate AED<Input
                            v-model="itemForm.unit_rate"
                            required
                            inputmode="decimal"
                    /></label>
                    <p
                        v-for="(message, field) in itemForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="itemForm.processing"
                        >Add BOQ item</Button
                    >
                </form></CardContent
            ></Card
        >
        <Card v-show="tab === 'progress'" v-if="project.status === 'active'"
            ><CardHeader
                ><CardTitle>Record completed work</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="progress"
                >
                    <label class="text-sm"
                        >BOQ item<select
                            v-model="progressForm.item_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select item</option>
                            <option
                                v-for="item in items"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.reference }} · {{ item.description }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >Additional completed quantity<Input
                            v-model="progressForm.quantity"
                            required
                            inputmode="decimal" /></label
                    ><label class="text-sm md:col-span-2"
                        >Work note<Input
                            v-model="progressForm.note"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in progressForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="progressForm.processing"
                        >Record progress</Button
                    >
                </form></CardContent
            ></Card
        >
        <Card v-show="tab === 'progress'"
            ><CardHeader
                ><CardTitle>Latest 50 progress entries</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><article
                    v-for="entry in props.progress"
                    :key="entry.id"
                    class="border-b pb-3 text-sm"
                >
                    #{{ entry.id }} · BOQ #{{
                        entry.construction_boq_item_id
                    }}
                    · {{ entry.quantity }} · {{ entry.note }}
                    <p v-if="entry.voided_at">
                        Voided: {{ entry.void_reason }}
                    </p>
                </article>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="voidProgress"
                >
                    <label class="text-sm"
                        >Progress ID to correct<Input
                            v-model="voidForm.progress_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="voidForm.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in voidForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        variant="outline"
                        :disabled="voidForm.processing"
                        >Void progress entry</Button
                    >
                </form></CardContent
            ></Card
        >
        <Card v-show="tab === 'claims'" v-if="project.status === 'active'"
            ><CardHeader
                ><CardTitle>Submit contractor claim</CardTitle></CardHeader
            ><CardContent
                ><form class="space-y-3" @submit.prevent="claim">
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="text-sm"
                            >Contractor<select
                                v-model="claimForm.vendor_id"
                                required
                                class="border-input block h-9 w-full rounded-md border px-3"
                            >
                                <option value="">Select vendor</option>
                                <option
                                    v-for="vendor in vendors"
                                    :key="vendor.id"
                                    :value="String(vendor.id)"
                                >
                                    {{ vendor.name }}
                                </option>
                            </select></label
                        ><label class="text-sm"
                            >Claim date<Input
                                v-model="claimForm.claimed_on"
                                required
                                type="date"
                        /></label>
                    </div>
                    <label
                        v-for="item in items"
                        :key="item.id"
                        class="block text-sm"
                        >{{ item.reference }} · Completed {{ item.completed }},
                        reserved {{ item.reserved
                        }}<Input
                            v-model="claimQuantities[item.id]"
                            inputmode="decimal"
                            placeholder="Quantity to claim; leave blank to exclude" /></label
                    ><label class="block text-sm"
                        >Claim reference and reason<Input
                            v-model="claimForm.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in claimForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button :disabled="claimForm.processing"
                        >Submit for owner approval</Button
                    >
                </form></CardContent
            ></Card
        >
        <Card v-show="tab === 'claims'"
            ><CardHeader
                ><CardTitle>{{ t('Claims') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!claims.data.length">No claims submitted.</p>
                <article
                    v-for="item in claims.data"
                    :key="item.id"
                    class="space-y-1 border-b pb-3 text-sm"
                >
                    <p>
                        #{{ item.id }} · {{ item.reference }} ·
                        {{ item.status }} · AED {{ item.amount }} ·
                        {{ item.claimed_on }}
                    </p>
                    <p>
                        {{ item.reason }} · Requester #{{ item.requested_by }}
                    </p>
                    <p v-for="line in item.lines" :key="line.item_id">
                        BOQ #{{ line.item_id }} · {{ line.quantity }} · AED
                        {{ line.amount }}
                    </p>
                    <p v-if="item.rejection_reason">
                        Rejected: {{ item.rejection_reason }}
                    </p>
                    <Link
                        v-if="item.vendor_bill_id"
                        href="/vendor-bills"
                        class="underline"
                        >Bill #{{ item.vendor_bill_id }} ·
                        {{ item.bill_status }}</Link
                    ><Button
                        v-if="
                            canApprove &&
                            item.status === 'submitted' &&
                            item.requested_by !== actorId
                        "
                        :disabled="approval.processing"
                        @click="approve(item.id)"
                        >Approve claim</Button
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
                <Pagination :links="claims.links" /></CardContent
        ></Card>
        <Card v-show="tab === 'claims'" v-if="canApprove"
            ><CardHeader
                ><CardTitle>Reject a submitted claim</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="reject"
                >
                    <label class="text-sm"
                        >Claim ID<Input
                            v-model="rejection.claim_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="rejection.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in rejection.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        variant="outline"
                        :disabled="rejection.processing"
                        >Reject claim</Button
                    >
                </form></CardContent
            ></Card
        >
        <Card v-show="tab === 'claims'" v-if="canFinance"
            ><CardHeader
                ><CardTitle
                    >Create a draft vendor bill from an approved
                    claim</CardTitle
                ></CardHeader
            ><CardContent
                ><p class="text-muted-foreground mb-3 text-sm">
                    The approved amount is preserved. Post the draft through
                    Vendor bills after reviewing its treatment.
                </p>
                <form class="grid gap-3 md:grid-cols-2" @submit.prevent="bill">
                    <label class="text-sm"
                        >Approved claim ID<Input
                            v-model="billForm.claim_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >Bill date<Input
                            v-model="billForm.bill_date"
                            required
                            type="date" /></label
                    ><label class="text-sm"
                        >Due on<Input
                            v-model="billForm.due_on"
                            type="date" /></label
                    ><label class="text-sm"
                        >{{ t('Accounting treatment')
                        }}<select
                            v-model="billForm.accounting_treatment"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="operating_expense">
                                Operating expense
                            </option>
                            <option value="capital_asset">Capital asset</option>
                        </select></label
                    ><label v-if="vatEnabled" class="text-sm"
                        >{{ t('VAT treatment')
                        }}<select
                            v-model="billForm.vat_treatment"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select treatment</option>
                            <option value="standard">Standard</option>
                            <option value="zero_rated">Zero rated</option>
                            <option value="exempt">Exempt</option>
                            <option value="out_of_scope">Out of scope</option>
                        </select></label
                    ><label
                        v-if="
                            vatEnabled && billForm.vat_treatment === 'standard'
                        "
                        class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="billForm.input_vat_recoverable"
                            type="checkbox"
                        />Input VAT recoverable</label
                    >
                    <p
                        v-for="(message, field) in billForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="billForm.processing"
                        >Create finance draft</Button
                    >
                </form></CardContent
            ></Card
        >
        <Card v-show="tab === 'lifecycle'"
            ><CardHeader><CardTitle>Project lifecycle</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="status"
                >
                    <label class="text-sm"
                        >{{ t('Status')
                        }}<select
                            v-model="statusForm.status"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="active">{{ t('Active') }}</option>
                            <option value="completed">
                                {{ t('Completed') }}
                            </option>
                            <option value="cancelled">
                                {{ t('Cancelled') }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="statusForm.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in statusForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        variant="outline"
                        :disabled="statusForm.processing"
                        >Update project status</Button
                    >
                </form></CardContent
            ></Card
        >
    </div>
</template>
