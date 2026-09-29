<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { useLocale } from '@/composables/useLocale';
import { clearBillIdIfEstimate } from '@/lib/costing';
import type { SpendSource } from '@/lib/costing';
import type { DataTableColumn } from '@/lib/data-table';

type PortalCost = {
    portal: 'bayut' | 'property_finder' | 'dubizzle';
    published_listings: number;
    local_validated_listings: number;
    total_leads: number;
    actual_spend_aed: number;
    estimated_spend_aed: number;
    cost_per_listing: number | null;
    cost_per_lead: number | null;
    cost_per_deal: null;
    roi: null;
};
type ListingSpend = {
    id: number;
    listing_id: number;
    channel: 'bayut' | 'property_finder' | 'dubizzle' | 'other';
    source: SpendSource;
    amount_aed: string;
    incurred_on: string;
    reason: string;
};

defineProps<{
    portals: PortalCost[];
    spend: {
        data: ListingSpend[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Listing Costing', href: '/marketing/costing' }],
    },
});

const { t } = useLocale();
const portalLabel = (portal: string) =>
    ({
        bayut: 'Bayut',
        property_finder: 'Property Finder',
        dubizzle: 'Dubizzle',
    })[portal] ?? portal;

const spendColumns: DataTableColumn<ListingSpend>[] = [
    { key: 'listing_id', label: 'Listing' },
    { key: 'channel', label: 'Channel' },
    { key: 'source', label: 'Source' },
    { key: 'amount_aed', label: 'Amount' },
    { key: 'incurred_on', label: 'Date' },
];

const dialogOpen = ref(false);
const form = useForm({
    listing_id: '',
    publication_id: '',
    campaign_id: '',
    vendor_bill_id: '',
    channel: 'bayut' as ListingSpend['channel'],
    source: 'estimate' as SpendSource,
    amount_aed: '',
    incurred_on: '',
    reason: '',
});
watch(
    () => form.source,
    (source) => {
        form.vendor_bill_id = clearBillIdIfEstimate(
            source,
            form.vendor_bill_id,
        );
    },
);
function openCreate(): void {
    form.reset();
    dialogOpen.value = true;
}
function submit(): void {
    form.transform((data) => ({
        ...data,
        listing_id: Number(data.listing_id),
        publication_id:
            data.publication_id === '' ? null : Number(data.publication_id),
        campaign_id: data.campaign_id === '' ? null : Number(data.campaign_id),
        vendor_bill_id:
            data.vendor_bill_id === '' ? null : Number(data.vendor_bill_id),
    })).post('/marketing/costing', {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}
</script>

<template>
    <Head :title="t('Listing Costing')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Marketing" title="Listing Costing" />

        <div class="grid gap-4 md:grid-cols-3">
            <div
                v-for="portal in portals"
                :key="portal.portal"
                class="bg-card shadow-panel rounded-lg border p-5"
            >
                <p class="font-display text-lg font-medium">
                    {{ portalLabel(portal.portal) }}
                </p>
                <dl class="mt-3 flex flex-col gap-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">
                            {{ t('Local-validated listings') }}
                        </dt>
                        <dd>{{ portal.local_validated_listings }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('Leads') }}</dt>
                        <dd>{{ portal.total_leads }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">
                            {{ t('Actual spend') }}
                        </dt>
                        <dd><Money :value="portal.actual_spend_aed" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">
                            {{ t('Estimated spend') }}
                        </dt>
                        <dd><Money :value="portal.estimated_spend_aed" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">
                            {{ t('Cost per listing') }}
                        </dt>
                        <dd>
                            <Money
                                v-if="portal.cost_per_listing !== null"
                                :value="portal.cost_per_listing"
                            />
                            <span v-else class="text-faint">—</span>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">
                            {{ t('Cost per lead') }}
                        </dt>
                        <dd>
                            <Money
                                v-if="portal.cost_per_lead !== null"
                                :value="portal.cost_per_lead"
                            />
                            <span v-else class="text-faint">—</span>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">
                            {{ t('Cost per deal') }}
                        </dt>
                        <dd class="text-faint">—</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ t('ROI') }}</dt>
                        <dd class="text-faint">—</dd>
                    </div>
                </dl>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Spend log') }}
                </h2>
                <Button v-if="canManage" size="sm" @click="openCreate">{{
                    t('Record spend')
                }}</Button>
            </div>
            <DataTable
                :columns="spendColumns"
                :rows="spend.data"
                :row-key="(row) => row.id"
                empty-title="No spend recorded yet."
            >
                <template #cell-listing_id="{ row }"
                    >#{{ row.listing_id }}</template
                >
                <template #cell-channel="{ row }"
                    ><Badge variant="outline">{{
                        portalLabel(row.channel)
                    }}</Badge></template
                >
                <template #cell-source="{ row }">{{
                    t(row.source === 'estimate' ? 'Estimate' : 'Bill-linked')
                }}</template>
                <template #cell-amount_aed="{ row }"
                    ><Money :value="row.amount_aed"
                /></template>
            </DataTable>
            <Pagination :links="spend.links" />
        </section>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Record spend')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label for="spend-listing">{{ t('Listing ID') }}</Label>
                        <Input
                            id="spend-listing"
                            v-model="form.listing_id"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.listing_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="spend-publication"
                                >{{ t('Publication ID') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="spend-publication"
                                v-model="form.publication_id"
                                type="number"
                                min="1"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="spend-campaign"
                                >{{ t('Campaign ID') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="spend-campaign"
                                v-model="form.campaign_id"
                                type="number"
                                min="1"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label>{{ t('Channel') }}</Label>
                            <Select v-model="form.channel">
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
                                    <SelectItem value="other">{{
                                        t('Other')
                                    }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label>{{ t('Source') }}</Label>
                            <Select v-model="form.source">
                                <SelectTrigger class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="estimate">{{
                                        t('Estimate')
                                    }}</SelectItem>
                                    <SelectItem value="bill_linked">{{
                                        t('Bill-linked')
                                    }}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div
                        v-if="form.source === 'bill_linked'"
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="spend-bill">{{
                            t('Vendor bill ID')
                        }}</Label>
                        <Input
                            id="spend-bill"
                            v-model="form.vendor_bill_id"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.vendor_bill_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="spend-amount">{{
                                t('Amount (AED)')
                            }}</Label>
                            <Input
                                id="spend-amount"
                                v-model="form.amount_aed"
                                type="number"
                                min="0.01"
                                step="0.01"
                            />
                            <InputError :message="form.errors.amount_aed" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="spend-date">{{
                                t('Incurred on')
                            }}</Label>
                            <Input
                                id="spend-date"
                                v-model="form.incurred_on"
                                type="date"
                            />
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="spend-reason">{{ t('Reason') }}</Label>
                        <Textarea id="spend-reason" v-model="form.reason" />
                        <InputError :message="form.errors.reason" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            t('Record')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
