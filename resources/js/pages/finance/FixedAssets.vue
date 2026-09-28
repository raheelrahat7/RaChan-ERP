<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Asset = {
    id: number;
    reference: string;
    name: string;
    asset_class: string;
    classification: string;
    cost: string;
    residual_value: string;
    useful_life_months: number;
    available_for_use_on: string;
    disposed_on: string | null;
    property_id: number | null;
    property: { name: string } | null;
    location: string | null;
    custodian: string | null;
    opening_source_reference: string | null;
    vendor_bill: { reference: string } | null;
    creator: { name: string };
};
const props = defineProps<{
    assets: Asset[];
    properties: { id: number; name: string }[];
    vendorBills: { id: number; reference: string; total: string }[];
    openingSources: string[];
    canManage: boolean;
    depreciationPreview: {
        month: string;
        total: string;
        status: string;
        rows: {
            asset_id: number;
            reference: string;
            name: string;
            asset_class: string;
            depreciable_amount: string;
            useful_life_months: number;
            active_days: number;
            days_in_month: number;
            proposed_charge: string;
            estimate_effective_month: string | null;
        }[];
    };
    depreciations: {
        id: number;
        month: string;
        amount: string;
        reversal_journal_entry_id: number | null;
        created_at: string;
        asset: { reference: string; name: string };
        approver: { name: string };
    }[];
    reviews: {
        id: number;
        review_year: number;
        reviewed_on: string;
        outcome: string;
        residual_value_snapshot: string;
        useful_life_months_snapshot: number;
        depreciation_method: string;
        impairment_assessment_required: boolean;
        notes: string | null;
        asset: { reference: string; name: string };
        reviewer: { name: string };
        estimate_change: {
            effective_month: string;
            residual_value: string;
            remaining_life_months: number;
        } | null;
        impairments: { id: number; reversal_journal_entry_id: number | null }[];
    }[];
    reviewReadiness: {
        year: number;
        eligible_count: number;
        missing: { id: number; reference: string; name: string }[];
        attention: {
            id: number;
            outcome: string;
            impairment_assessment_required: boolean;
            notes: string | null;
            asset: { reference: string; name: string };
        }[];
    };
    proceedsAccounts: { id: number; code: string; name: string }[];
    disposals: {
        id: number;
        proceeds: string;
        carrying_amount: string;
        gain_loss: string;
        reversal_journal_entry_id: number | null;
        asset: { reference: string; name: string };
        proceeds_account: { code: string; name: string };
        approver: { name: string };
    }[];
    impairments: {
        id: number;
        posted_on: string;
        effective_month: string;
        amount: string;
        reversal_journal_entry_id: number | null;
        asset: { reference: string; name: string };
        approver: { name: string };
    }[];
    transfers: {
        id: number;
        transferred_on: string;
        from_location: string | null;
        to_location: string | null;
        from_custodian: string | null;
        to_custodian: string | null;
        from_asset_class: string;
        to_asset_class: string;
        reason: string;
        asset: { reference: string; name: string };
        from_property: { name: string } | null;
        to_property: { name: string } | null;
        approver: { name: string };
    }[];
}>();
const previewMonth = ref(props.depreciationPreview.month);
const reviewYear = ref(String(props.reviewReadiness.year));
const form = useForm({
    source_type: 'vendor_bill',
    vendor_bill_id: '',
    opening_source_reference: '',
    reference: '',
    name: '',
    asset_class: '',
    cost: '',
    residual_value: '0.00',
    useful_life_months: '',
    available_for_use_on: '',
});
const reviewForm = useForm({
    asset_id: '',
    review_year: String(new Date().getFullYear()),
    reviewed_on: new Date().toISOString().slice(0, 10),
    outcome: 'unchanged',
    impairment_assessment_required: false,
    notes: '',
});
const disposalForm = useForm({
    asset_id: '',
    proceeds_account_id: '',
    proceeds: '0.00',
});
const transferForm = useForm({
    asset_id: '',
    transferred_on: new Date().toISOString().slice(0, 10),
    property_id: '',
    location: '',
    custodian: '',
    asset_class: '',
    reason: '',
});
function selectTransferAsset(): void {
    const asset = props.assets.find(
        (item) => String(item.id) === transferForm.asset_id,
    );
    transferForm.property_id = asset?.property_id
        ? String(asset.property_id)
        : '';
    transferForm.location = asset?.location ?? '';
    transferForm.custodian = asset?.custodian ?? '';
    transferForm.asset_class = asset?.asset_class ?? '';
}
function submitTransfer(): void {
    transferForm.post(
        `/accounting/fixed-assets/${transferForm.asset_id}/transfer`,
        {
            preserveScroll: true,
            onSuccess: () => transferForm.reset('asset_id', 'reason'),
        },
    );
}
function submit(): void {
    form.post('/accounting/fixed-assets', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function dispose(asset: Asset): void {
    const disposed_on = prompt('Disposal date (YYYY-MM-DD)');
    if (disposed_on)
        router.post(
            `/accounting/fixed-assets/${asset.id}/dispose`,
            { disposed_on },
            { preserveScroll: true },
        );
}
function preview(): void {
    router.get('/accounting/fixed-assets', { month: previewMonth.value });
}
function postDepreciation(): void {
    if (confirm(`Post depreciation for ${previewMonth.value}?`))
        router.post('/accounting/fixed-assets/depreciation', {
            month: previewMonth.value,
        });
}
function reverseDepreciation(id: number): void {
    const posted_on = prompt(
        'Reversal date (YYYY-MM-DD)',
        new Date().toISOString().slice(0, 10),
    );
    if (posted_on)
        router.post(`/accounting/fixed-assets/depreciation/${id}/reverse`, {
            posted_on,
        });
}
function submitReview(): void {
    reviewForm.post(`/accounting/fixed-assets/${reviewForm.asset_id}/reviews`, {
        preserveScroll: true,
        onSuccess: () => reviewForm.reset('asset_id', 'notes'),
    });
}
function loadReviewReadiness(): void {
    router.get('/accounting/fixed-assets', {
        month: previewMonth.value,
        review_year: reviewYear.value,
    });
}
function approveEstimateChange(reviewId: number): void {
    const residual_value = prompt('Revised residual value AED');
    if (residual_value === null) return;
    const remaining_life_months = prompt('Revised remaining life in months');
    if (remaining_life_months)
        router.post(
            `/accounting/fixed-assets/reviews/${reviewId}/estimate-change`,
            { residual_value, remaining_life_months },
            { preserveScroll: true },
        );
}
function postDisposal(): void {
    disposalForm.post(
        `/accounting/fixed-assets/${disposalForm.asset_id}/disposal/post`,
        { preserveScroll: true, onSuccess: () => disposalForm.reset() },
    );
}
function reverseDisposal(id: number): void {
    const posted_on = prompt(
        'Reversal date (YYYY-MM-DD)',
        new Date().toISOString().slice(0, 10),
    );
    if (posted_on)
        router.post(
            `/accounting/fixed-assets/disposals/${id}/reverse`,
            { posted_on },
            { preserveScroll: true },
        );
}
function postImpairment(reviewId: number): void {
    const posted_on = prompt(
        'Impairment date (YYYY-MM-DD)',
        new Date().toISOString().slice(0, 10),
    );
    if (!posted_on) return;
    const amount = prompt('Impairment amount AED');
    if (amount)
        router.post(
            `/accounting/fixed-assets/reviews/${reviewId}/impairment`,
            { posted_on, amount },
            { preserveScroll: true },
        );
}
function reverseImpairment(id: number): void {
    const posted_on = prompt(
        'Reversal date (YYYY-MM-DD)',
        new Date().toISOString().slice(0, 10),
    );
    if (posted_on)
        router.post(
            `/accounting/fixed-assets/impairments/${id}/reverse`,
            { posted_on },
            { preserveScroll: true },
        );
}
</script>

<template>
    <Head title="Fixed assets" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="IAS 16 fixed assets"
            description="Individually approved cost-model assets. Registration does not post or alter the source journal."
        />
        <Link href="/accounting" class="text-sm underline"
            >Back to accounting</Link
        >
        <Card v-if="canManage"
            ><CardHeader><CardTitle>Register asset</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="submit"
                >
                    <label class="text-sm"
                        >Source type<select
                            v-model="form.source_type"
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="vendor_bill">
                                Capital-asset vendor bill
                            </option>
                            <option value="opening_balance">
                                Opening balance
                            </option>
                        </select></label
                    ><label
                        v-if="form.source_type === 'vendor_bill'"
                        class="text-sm"
                        >Vendor bill<select
                            v-model="form.vendor_bill_id"
                            required
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="" disabled>Select source</option>
                            <option
                                v-for="bill in vendorBills"
                                :key="bill.id"
                                :value="String(bill.id)"
                            >
                                {{ bill.reference }} · AED {{ bill.total }}
                            </option>
                        </select></label
                    ><label v-else class="text-sm"
                        >Opening source<select
                            v-model="form.opening_source_reference"
                            required
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="" disabled>Select source</option>
                            <option
                                v-for="source in openingSources"
                                :key="source"
                                :value="source"
                            >
                                {{ source }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >Asset reference<Input
                            v-model="form.reference"
                            required /></label
                    ><label class="text-sm"
                        >Asset name<Input v-model="form.name" required /></label
                    ><label class="text-sm"
                        >Approved asset class<Input
                            v-model="form.asset_class"
                            required /></label
                    ><label class="text-sm"
                        >Cost AED<Input
                            v-model="form.cost"
                            type="number"
                            min="0.01"
                            step="0.01"
                            required /></label
                    ><label class="text-sm"
                        >Residual value AED<Input
                            v-model="form.residual_value"
                            type="number"
                            min="0"
                            step="0.01"
                            required /></label
                    ><label class="text-sm"
                        >Useful life in months<Input
                            v-model="form.useful_life_months"
                            type="number"
                            min="1"
                            max="1200"
                            required /></label
                    ><label class="text-sm"
                        >Available for use<Input
                            v-model="form.available_for_use_on"
                            type="date"
                            required
                    /></label>
                    <div class="md:col-span-2">
                        <InputError
                            :message="Object.values(form.errors)[0]"
                        /><Button :disabled="form.processing"
                            >Register asset</Button
                        >
                    </div>
                </form></CardContent
            ></Card
        >
        <Card v-if="canManage"
            ><CardHeader><CardTitle>Transfer asset</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-3"
                    @submit.prevent="submitTransfer"
                >
                    <label class="text-sm"
                        >Active asset<select
                            v-model="transferForm.asset_id"
                            required
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                            @change="selectTransferAsset"
                        >
                            <option value="" disabled>Select asset</option>
                            <option
                                v-for="asset in assets.filter(
                                    (item) => !item.disposed_on,
                                )"
                                :key="asset.id"
                                :value="String(asset.id)"
                            >
                                {{ asset.reference }} · {{ asset.name }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >Transfer date<Input
                            v-model="transferForm.transferred_on"
                            type="date"
                            required /></label
                    ><label class="text-sm"
                        >{{ t('Property')
                        }}<select
                            v-model="transferForm.property_id"
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Unassigned</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >Location<Input
                            v-model="transferForm.location" /></label
                    ><label class="text-sm"
                        >Custodian<Input
                            v-model="transferForm.custodian" /></label
                    ><label class="text-sm"
                        >Asset class<Input
                            v-model="transferForm.asset_class"
                            required /></label
                    ><label class="text-sm md:col-span-3"
                        >{{ t('Reason')
                        }}<Input v-model="transferForm.reason" required
                    /></label>
                    <div class="md:col-span-3">
                        <InputError
                            :message="Object.values(transferForm.errors)[0]"
                        /><Button :disabled="transferForm.processing"
                            >Record transfer</Button
                        >
                    </div>
                </form>
                <p class="text-muted-foreground mt-3 text-sm">
                    Transfers update the asset register and audit trail without
                    a journal entry.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Transfer history</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full min-w-[900px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2">{{ t('Date') }}</th>
                            <th>Asset</th>
                            <th>{{ t('Property') }}</th>
                            <th>Location</th>
                            <th>Custodian</th>
                            <th>Class</th>
                            <th>Approved by</th>
                            <th>{{ t('Reason') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in transfers"
                            :key="item.id"
                            class="border-b"
                        >
                            <td class="py-2">{{ item.transferred_on }}</td>
                            <td>
                                {{ item.asset.reference }} ·
                                {{ item.asset.name }}
                            </td>
                            <td>
                                {{ item.from_property?.name ?? 'Unassigned' }} →
                                {{ item.to_property?.name ?? 'Unassigned' }}
                            </td>
                            <td>
                                {{ item.from_location ?? '—' }} →
                                {{ item.to_location ?? '—' }}
                            </td>
                            <td>
                                {{ item.from_custodian ?? '—' }} →
                                {{ item.to_custodian ?? '—' }}
                            </td>
                            <td>
                                {{ item.from_asset_class }} →
                                {{ item.to_asset_class }}
                            </td>
                            <td>{{ item.approver.name }}</td>
                            <td>{{ item.reason }}</td>
                        </tr>
                        <tr v-if="!transfers.length">
                            <td
                                colspan="8"
                                class="text-muted-foreground py-6 text-center"
                            >
                                No asset transfers.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
        <Card v-if="canManage"
            ><CardHeader
                ><CardTitle>Approve asset disposal</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-3"
                    @submit.prevent="postDisposal"
                >
                    <label class="text-sm"
                        >Disposed asset<select
                            v-model="disposalForm.asset_id"
                            required
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="" disabled>Select asset</option>
                            <option
                                v-for="asset in assets.filter(
                                    (item) => item.disposed_on,
                                )"
                                :key="asset.id"
                                :value="String(asset.id)"
                            >
                                {{ asset.reference }} · {{ asset.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >Proceeds account<select
                            v-model="disposalForm.proceeds_account_id"
                            required
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="" disabled>
                                Select bank or receivable
                            </option>
                            <option
                                v-for="account in proceedsAccounts"
                                :key="account.id"
                                :value="String(account.id)"
                            >
                                {{ account.code }} · {{ account.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >Sale proceeds AED<Input
                            v-model="disposalForm.proceeds"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                    /></label>
                    <div class="md:col-span-3">
                        <InputError
                            :message="Object.values(disposalForm.errors)[0]"
                        /><Button :disabled="disposalForm.processing"
                            >Approve and post disposal</Button
                        >
                    </div>
                </form>
                <p class="text-muted-foreground mt-3 text-sm">
                    Post final depreciation through the disposal date before
                    approval.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Disposal history</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full min-w-[800px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2">Asset</th>
                            <th>Approved by</th>
                            <th>Proceeds account</th>
                            <th class="text-right">Proceeds AED</th>
                            <th class="text-right">Carrying AED</th>
                            <th class="text-right">Gain/(loss) AED</th>
                            <th>{{ t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in disposals"
                            :key="item.id"
                            class="border-b"
                        >
                            <td class="py-2">
                                {{ item.asset.reference }} ·
                                {{ item.asset.name }}
                            </td>
                            <td>{{ item.approver.name }}</td>
                            <td>
                                {{ item.proceeds_account.code }} ·
                                {{ item.proceeds_account.name }}
                            </td>
                            <td class="text-right">{{ item.proceeds }}</td>
                            <td class="text-right">
                                {{ item.carrying_amount }}
                            </td>
                            <td class="text-right">{{ item.gain_loss }}</td>
                            <td>
                                <span v-if="item.reversal_journal_entry_id"
                                    >Reversed</span
                                ><Button
                                    v-else-if="canManage"
                                    size="sm"
                                    variant="outline"
                                    @click="reverseDisposal(item.id)"
                                    >Reverse</Button
                                ><span v-else>{{ t('Posted') }}</span>
                            </td>
                        </tr>
                        <tr v-if="!disposals.length">
                            <td
                                colspan="7"
                                class="text-muted-foreground py-6 text-center"
                            >
                                No disposal postings.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Impairment history</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full min-w-[650px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2">{{ t('Date') }}</th>
                            <th>Asset</th>
                            <th>Approved by</th>
                            <th>Depreciation effect</th>
                            <th class="text-right">Amount AED</th>
                            <th>{{ t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in impairments"
                            :key="item.id"
                            class="border-b"
                        >
                            <td class="py-2">{{ item.posted_on }}</td>
                            <td>
                                {{ item.asset.reference }} ·
                                {{ item.asset.name }}
                            </td>
                            <td>{{ item.approver.name }}</td>
                            <td>From {{ item.effective_month }}</td>
                            <td class="text-right">{{ item.amount }}</td>
                            <td>
                                <span v-if="item.reversal_journal_entry_id"
                                    >Reversed</span
                                ><Button
                                    v-else-if="canManage"
                                    size="sm"
                                    variant="outline"
                                    @click="reverseImpairment(item.id)"
                                    >Reverse</Button
                                ><span v-else>{{ t('Posted') }}</span>
                            </td>
                        </tr>
                        <tr v-if="!impairments.length">
                            <td
                                colspan="6"
                                class="text-muted-foreground py-6 text-center"
                            >
                                No impairment postings.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle
                    >Annual review readiness ·
                    {{ reviewReadiness.year }}</CardTitle
                ></CardHeader
            ><CardContent class="space-y-4"
                ><form
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="loadReviewReadiness"
                >
                    <label class="text-sm"
                        >Review year<Input
                            v-model="reviewYear"
                            type="number"
                            min="2000"
                            max="2100"
                            required /></label
                    ><Button variant="outline">Load readiness</Button>
                    <Button variant="outline" as-child
                        ><a
                            :href="`/accounting/fixed-assets/review-readiness.csv?review_year=${reviewYear}`"
                            >Export CSV</a
                        ></Button
                    >
                </form>
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-md border p-3">
                        <div class="text-muted-foreground text-sm">
                            Eligible assets
                        </div>
                        <div class="text-2xl font-semibold">
                            {{ reviewReadiness.eligible_count }}
                        </div>
                    </div>
                    <div class="rounded-md border p-3">
                        <div class="text-muted-foreground text-sm">
                            Missing reviews
                        </div>
                        <div class="text-2xl font-semibold">
                            {{ reviewReadiness.missing.length }}
                        </div>
                    </div>
                    <div class="rounded-md border p-3">
                        <div class="text-muted-foreground text-sm">
                            Follow-up required
                        </div>
                        <div class="text-2xl font-semibold">
                            {{ reviewReadiness.attention.length }}
                        </div>
                    </div>
                </div>
                <div v-if="reviewReadiness.missing.length">
                    <p class="mb-1 text-sm font-medium">Missing reviews</p>
                    <p class="text-muted-foreground text-sm">
                        <span
                            v-for="(asset, index) in reviewReadiness.missing"
                            :key="asset.id"
                            >{{ index ? ', ' : '' }}{{ asset.reference }} ·
                            {{ asset.name }}</span
                        >
                    </p>
                </div>
                <div v-if="reviewReadiness.attention.length">
                    <p class="mb-1 text-sm font-medium">Documented follow-up</p>
                    <ul class="text-muted-foreground space-y-1 text-sm">
                        <li
                            v-for="review in reviewReadiness.attention"
                            :key="review.id"
                        >
                            {{ review.asset.reference }} ·
                            {{ review.asset.name }} —
                            <span v-if="review.outcome === 'change_required'"
                                >estimate change</span
                            ><span
                                v-if="
                                    review.outcome === 'change_required' &&
                                    review.impairment_assessment_required
                                "
                            >
                                and </span
                            ><span v-if="review.impairment_assessment_required"
                                >impairment assessment</span
                            >{{ review.notes ? ` · ${review.notes}` : '' }}
                        </li>
                    </ul>
                </div>
                <p
                    v-if="
                        !reviewReadiness.missing.length &&
                        !reviewReadiness.attention.length
                    "
                    class="text-muted-foreground text-sm"
                >
                    All eligible assets are reviewed with no documented
                    follow-up.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle
                    >Monthly depreciation preview · AED
                    {{ depreciationPreview.total }}</CardTitle
                ></CardHeader
            ><CardContent class="space-y-4"
                ><form
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="preview"
                >
                    <label class="text-sm"
                        >Month<Input
                            v-model="previewMonth"
                            type="month"
                            required /></label
                    ><Button variant="outline">{{ t('Preview') }}</Button
                    ><Button
                        v-if="canManage && depreciationPreview.rows.length"
                        type="button"
                        @click="postDepreciation"
                        >Approve and post</Button
                    >
                </form>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[700px] text-sm">
                        <thead>
                            <tr class="border-b text-left">
                                <th class="py-2">Asset</th>
                                <th>Class</th>
                                <th>Active days</th>
                                <th>Life</th>
                                <th class="text-right">Depreciable AED</th>
                                <th class="text-right">Proposed AED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in depreciationPreview.rows"
                                :key="row.asset_id"
                                class="border-b"
                            >
                                <td class="py-2">
                                    {{ row.reference }} · {{ row.name }}
                                </td>
                                <td>{{ row.asset_class }}</td>
                                <td>
                                    {{ row.active_days }}/{{
                                        row.days_in_month
                                    }}
                                </td>
                                <td>{{ row.useful_life_months }} months</td>
                                <td class="text-right">
                                    {{ row.depreciable_amount }}
                                </td>
                                <td class="text-right font-medium">
                                    {{ row.proposed_charge }}
                                    <span
                                        v-if="row.estimate_effective_month"
                                        class="text-muted-foreground block text-xs"
                                        >Estimate from
                                        {{ row.estimate_effective_month }}</span
                                    >
                                </td>
                            </tr>
                            <tr v-if="!depreciationPreview.rows.length">
                                <td
                                    colspan="6"
                                    class="text-muted-foreground py-6 text-center"
                                >
                                    No eligible depreciation for this month.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted-foreground text-sm">
                    Preview only. No depreciation journal has been posted.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Asset register</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full min-w-[900px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2">{{ t('Reference') }}</th>
                            <th>Name / class</th>
                            <th>Source</th>
                            <th>{{ t('Available') }}</th>
                            <th>Life</th>
                            <th class="text-right">Cost AED</th>
                            <th class="text-right">Residual AED</th>
                            <th>{{ t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="asset in assets"
                            :key="asset.id"
                            class="border-b"
                        >
                            <td class="py-2 font-medium">
                                {{ asset.reference }}
                            </td>
                            <td>{{ asset.name }} · {{ asset.asset_class }}</td>
                            <td>
                                {{
                                    asset.vendor_bill?.reference ??
                                    asset.opening_source_reference
                                }}
                            </td>
                            <td>{{ asset.available_for_use_on }}</td>
                            <td>{{ asset.useful_life_months }} months</td>
                            <td class="text-right">{{ asset.cost }}</td>
                            <td class="text-right">
                                {{ asset.residual_value }}
                            </td>
                            <td>
                                <span v-if="asset.disposed_on"
                                    >Disposed {{ asset.disposed_on }}</span
                                ><Button
                                    v-else-if="canManage"
                                    size="sm"
                                    variant="outline"
                                    @click="dispose(asset)"
                                    >Record disposal</Button
                                ><span v-else>{{ t('Active') }}</span>
                            </td>
                        </tr>
                        <tr v-if="!assets.length">
                            <td
                                colspan="8"
                                class="text-muted-foreground py-6 text-center"
                            >
                                No registered IAS 16 assets.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
        <Card v-if="canManage"
            ><CardHeader
                ><CardTitle
                    >Document annual estimate review</CardTitle
                ></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="submitReview"
                >
                    <label class="text-sm"
                        >Asset<select
                            v-model="reviewForm.asset_id"
                            required
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="" disabled>Select asset</option>
                            <option
                                v-for="asset in assets"
                                :key="asset.id"
                                :value="String(asset.id)"
                            >
                                {{ asset.reference }} · {{ asset.name }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >Review year<Input
                            v-model="reviewForm.review_year"
                            type="number"
                            min="2000"
                            max="2100"
                            required /></label
                    ><label class="text-sm"
                        >Review date<Input
                            v-model="reviewForm.reviewed_on"
                            type="date"
                            required /></label
                    ><label class="text-sm"
                        >Outcome<select
                            v-model="reviewForm.outcome"
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="unchanged">
                                Estimates unchanged
                            </option>
                            <option value="change_required">
                                Estimate change required
                            </option>
                        </select></label
                    ><label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="reviewForm.impairment_assessment_required"
                            type="checkbox"
                        />Further impairment assessment required</label
                    ><label class="text-sm md:col-span-2"
                        >Review notes<textarea
                            v-model="reviewForm.notes"
                            rows="3"
                            :required="reviewForm.outcome === 'change_required'"
                            class="border-input bg-background block w-full rounded-md border px-3 py-2"
                        />
                    </label>
                    <div class="md:col-span-2">
                        <InputError
                            :message="Object.values(reviewForm.errors)[0]"
                        /><Button :disabled="reviewForm.processing"
                            >Record review</Button
                        >
                    </div>
                </form></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Estimate review history</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full min-w-[850px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2">Year / date</th>
                            <th>Asset</th>
                            <th>Reviewer</th>
                            <th>Snapshot</th>
                            <th>Outcome</th>
                            <th>Impairment</th>
                            <th>{{ t('Notes') }}</th>
                            <th>Approved estimate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="review in reviews"
                            :key="review.id"
                            class="border-b"
                        >
                            <td class="py-2">
                                {{ review.review_year }} ·
                                {{ review.reviewed_on }}
                            </td>
                            <td>
                                {{ review.asset.reference }} ·
                                {{ review.asset.name }}
                            </td>
                            <td>{{ review.reviewer.name }}</td>
                            <td>
                                {{ review.useful_life_months_snapshot }} months
                                · AED {{ review.residual_value_snapshot }} ·
                                straight-line
                            </td>
                            <td>
                                {{
                                    review.outcome === 'unchanged'
                                        ? 'Unchanged'
                                        : 'Change required'
                                }}
                            </td>
                            <td>
                                {{
                                    review.impairment_assessment_required
                                        ? 'Required'
                                        : 'Not indicated'
                                }}
                            </td>
                            <td>{{ review.notes ?? '—' }}</td>
                            <td>
                                <span v-if="review.estimate_change"
                                    >Effective
                                    {{ review.estimate_change.effective_month }}
                                    · AED
                                    {{ review.estimate_change.residual_value }}
                                    ·
                                    {{
                                        review.estimate_change
                                            .remaining_life_months
                                    }}
                                    months</span
                                ><Button
                                    v-else-if="
                                        canManage &&
                                        review.outcome === 'change_required'
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="approveEstimateChange(review.id)"
                                    >Approve change</Button
                                ><span v-else>—</span>
                                <Button
                                    v-if="
                                        canManage &&
                                        review.impairment_assessment_required &&
                                        !review.impairments.some(
                                            (item) =>
                                                !item.reversal_journal_entry_id,
                                        )
                                    "
                                    size="sm"
                                    variant="outline"
                                    class="ml-2"
                                    @click="postImpairment(review.id)"
                                    >Post impairment</Button
                                >
                            </td>
                        </tr>
                        <tr v-if="!reviews.length">
                            <td
                                colspan="8"
                                class="text-muted-foreground py-6 text-center"
                            >
                                No annual estimate reviews recorded.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Depreciation history</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full min-w-[650px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2">Month</th>
                            <th>Asset</th>
                            <th>Approved by</th>
                            <th class="text-right">Amount AED</th>
                            <th>{{ t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in depreciations"
                            :key="item.id"
                            class="border-b"
                        >
                            <td class="py-2">{{ item.month }}</td>
                            <td>
                                {{ item.asset.reference }} ·
                                {{ item.asset.name }}
                            </td>
                            <td>{{ item.approver.name }}</td>
                            <td class="text-right">{{ item.amount }}</td>
                            <td>
                                <span v-if="item.reversal_journal_entry_id"
                                    >Reversed</span
                                ><Button
                                    v-else-if="canManage"
                                    size="sm"
                                    variant="outline"
                                    @click="reverseDepreciation(item.id)"
                                    >Reverse</Button
                                ><span v-else>{{ t('Posted') }}</span>
                            </td>
                        </tr>
                        <tr v-if="!depreciations.length">
                            <td
                                colspan="5"
                                class="text-muted-foreground py-6 text-center"
                            >
                                No depreciation history.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
    </div>
</template>
