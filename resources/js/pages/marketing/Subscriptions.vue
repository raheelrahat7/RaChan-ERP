<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type Subscription = {
    id: number;
    portal: 'bayut' | 'property_finder' | 'dubizzle';
    package: string;
    contract_value_aed: string;
    billing_cycle: 'monthly' | 'quarterly' | 'annual';
    credits_total: number;
    credits_used: number;
    starts_on: string;
    renews_on: string | null;
    status: string;
};

defineProps<{
    subscriptions: {
        data: Subscription[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    bills: {
        id: number;
        subscription_id: number;
        vendor_bill_id: number;
        period_from: string;
        period_to: string;
    }[];
    canManage: boolean;
    canLinkBill: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portal Subscriptions', href: '/marketing/subscriptions' },
        ],
    },
});

const { t } = useLocale();
const portalLabel = (portal: string) =>
    ({
        bayut: 'Bayut',
        property_finder: 'Property Finder',
        dubizzle: 'Dubizzle',
    })[portal] ?? portal;

const columns: DataTableColumn<Subscription>[] = [
    { key: 'portal', label: 'Portal' },
    { key: 'package', label: 'Package' },
    { key: 'credits_used', label: 'Credits' },
    { key: 'billing_cycle', label: 'Billing' },
    { key: 'renews_on', label: 'Renews' },
    { key: 'status', label: 'Status' },
    { key: 'id', label: '', align: 'end' },
];

const dialogOpen = ref(false);
const form = useForm({
    portal: 'bayut' as Subscription['portal'],
    package: '',
    company_id: '',
    branch_id: '',
    cost_centre_id: '',
    contract_value_aed: '',
    billing_cycle: 'monthly' as Subscription['billing_cycle'],
    credits_total: '',
    starts_on: '',
    renews_on: '',
    // Not a real input: declared so `form.errors.lines` is typed. The backend's
    // ManageAccountingDimensions::validateLine() reports company/branch/cost-centre
    // combination errors under this synthetic key, not under any one field.
    lines: '',
});
function openCreate(): void {
    form.reset();
    dialogOpen.value = true;
}
function submit(): void {
    form.transform((data) => ({
        ...data,
        company_id: data.company_id === '' ? null : Number(data.company_id),
        branch_id: data.branch_id === '' ? null : Number(data.branch_id),
        cost_centre_id:
            data.cost_centre_id === '' ? null : Number(data.cost_centre_id),
        credits_total:
            data.credits_total === '' ? null : Number(data.credits_total),
    })).post('/marketing/subscriptions', {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

const linkingSubscription = ref<Subscription | null>(null);
const linkForm = useForm({
    vendor_bill_id: '',
    period_from: '',
    period_to: '',
});
function openLink(subscription: Subscription): void {
    linkForm.reset();
    linkingSubscription.value = subscription;
}
function submitLink(): void {
    if (!linkingSubscription.value) {
        return;
    }
    linkForm
        .transform((data) => ({
            ...data,
            vendor_bill_id: Number(data.vendor_bill_id),
        }))
        .post(
            `/marketing/subscriptions/${linkingSubscription.value.id}/bills`,
            {
                preserveScroll: true,
                onSuccess: () => (linkingSubscription.value = null),
            },
        );
}
</script>

<template>
    <Head :title="t('Portal Subscriptions')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Marketing" title="Portal Subscriptions">
            <template #actions>
                <Button v-if="canManage" @click="openCreate">{{
                    t('New subscription')
                }}</Button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="subscriptions.data"
            :row-key="(row) => row.id"
            :row-label="(row) => row.package"
            empty-title="No subscriptions yet."
        >
            <template #cell-portal="{ row }"
                ><Badge variant="outline">{{
                    portalLabel(row.portal)
                }}</Badge></template
            >
            <template #cell-credits_used="{ row }"
                >{{ row.credits_used }} / {{ row.credits_total }}</template
            >
            <template #cell-billing_cycle="{ row }">{{
                t(row.billing_cycle)
            }}</template>
            <template #cell-renews_on="{ row }">{{
                row.renews_on ?? '—'
            }}</template>
            <template #cell-status="{ row }"
                ><StatusDot :status="row.status"
            /></template>
            <template #cell-id="{ row }">
                <Button
                    v-if="canLinkBill"
                    size="sm"
                    variant="outline"
                    @click="openLink(row)"
                    >{{ t('Link bill') }}</Button
                >
            </template>
        </DataTable>
        <Pagination :links="subscriptions.links" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('New subscription')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label>{{ t('Portal') }}</Label>
                            <Select v-model="form.portal">
                                <SelectTrigger class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="bayut">{{
                                        t('Bayut')
                                    }}</SelectItem>
                                    <SelectItem value="property_finder">{{
                                        t('Property Finder')
                                    }}</SelectItem>
                                    <SelectItem value="dubizzle">{{
                                        t('Dubizzle')
                                    }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-package">{{ t('Package') }}</Label>
                            <Input id="sub-package" v-model="form.package" />
                            <InputError :message="form.errors.package" />
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-company"
                                >{{ t('Company ID') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="sub-company"
                                v-model="form.company_id"
                                type="number"
                                min="1"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-branch"
                                >{{ t('Branch ID') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="sub-branch"
                                v-model="form.branch_id"
                                type="number"
                                min="1"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-cost-centre"
                                >{{ t('Cost centre ID') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="sub-cost-centre"
                                v-model="form.cost_centre_id"
                                type="number"
                                min="1"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-value">{{
                                t('Contract value (AED)')
                            }}</Label>
                            <Input
                                id="sub-value"
                                v-model="form.contract_value_aed"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                            <InputError
                                :message="form.errors.contract_value_aed"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label>{{ t('Billing cycle') }}</Label>
                            <Select v-model="form.billing_cycle">
                                <SelectTrigger class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="monthly">{{
                                        t('monthly')
                                    }}</SelectItem>
                                    <SelectItem value="quarterly">{{
                                        t('quarterly')
                                    }}</SelectItem>
                                    <SelectItem value="annual">{{
                                        t('annual')
                                    }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <InputError :message="form.errors.lines" />
                    <div class="flex flex-col gap-1.5">
                        <Label for="sub-credits"
                            >{{ t('Credits total') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="sub-credits"
                            v-model="form.credits_total"
                            type="number"
                            min="0"
                        />
                        <InputError :message="form.errors.credits_total" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-starts">{{ t('Starts on') }}</Label>
                            <Input
                                id="sub-starts"
                                v-model="form.starts_on"
                                type="date"
                            />
                            <InputError :message="form.errors.starts_on" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sub-renews"
                                >{{ t('Renews on') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="sub-renews"
                                v-model="form.renews_on"
                                type="date"
                            />
                            <InputError :message="form.errors.renews_on" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            t('Create')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="linkingSubscription !== null"
            @update:open="(value) => !value && (linkingSubscription = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Link vendor bill')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submitLink">
                    <div class="flex flex-col gap-1.5">
                        <Label for="link-bill">{{ t('Vendor bill ID') }}</Label>
                        <Input
                            id="link-bill"
                            v-model="linkForm.vendor_bill_id"
                            type="number"
                            min="1"
                        />
                        <InputError :message="linkForm.errors.vendor_bill_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="link-from">{{
                                t('Period from')
                            }}</Label>
                            <Input
                                id="link-from"
                                v-model="linkForm.period_from"
                                type="date"
                            />
                            <InputError
                                :message="linkForm.errors.period_from"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="link-to">{{ t('Period to') }}</Label>
                            <Input
                                id="link-to"
                                v-model="linkForm.period_to"
                                type="date"
                            />
                            <InputError :message="linkForm.errors.period_to" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="linkingSubscription = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="linkForm.processing">{{
                            t('Link')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
