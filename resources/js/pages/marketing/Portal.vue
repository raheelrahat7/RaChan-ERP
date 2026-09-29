<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPicker from '@/components/RecordPicker.vue';
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
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type Campaign = {
    id: number;
    name: string;
    type: string;
    budget_aed: string;
    starts_on: string | null;
    ends_on: string | null;
    status: string;
};
type Publication = {
    id: number;
    listing_id: number;
    campaign_id: number | null;
    portal: 'bayut' | 'property_finder' | 'dubizzle';
    status: string;
    validated_at: string;
};

defineProps<{
    campaigns: {
        data: Campaign[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    publications: Publication[];
    canManage: boolean;
    providerSelected: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Marketing & Portals', href: '/marketing/portals' },
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

const campaignColumns: DataTableColumn<Campaign>[] = [
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Type' },
    { key: 'budget_aed', label: 'Budget' },
    { key: 'status', label: 'Status' },
];
const campaignDialogOpen = ref(false);
const campaignForm = useForm({
    name: '',
    type: '',
    vendor_id: '',
    cost_centre_id: '',
    budget_aed: '',
    starts_on: '',
    ends_on: '',
});
function openCampaign(): void {
    campaignForm.reset();
    campaignDialogOpen.value = true;
}
function submitCampaign(): void {
    campaignForm
        .transform((data) => ({
            ...data,
            vendor_id: data.vendor_id === '' ? null : Number(data.vendor_id),
            cost_centre_id:
                data.cost_centre_id === '' ? null : Number(data.cost_centre_id),
        }))
        .post('/marketing/campaigns', {
            preserveScroll: true,
            onSuccess: () => (campaignDialogOpen.value = false),
        });
}

const publicationColumns: DataTableColumn<Publication>[] = [
    { key: 'listing_id', label: 'Listing' },
    { key: 'portal', label: 'Portal' },
    { key: 'status', label: 'Status' },
    { key: 'validated_at', label: 'Validated' },
    { key: 'id', label: '', align: 'end' },
];
const publishDialogOpen = ref(false);
const publishForm = useForm({
    listing_id: '',
    campaign_id: '',
    portal: 'bayut' as Publication['portal'],
});
function openPublish(): void {
    publishForm.reset();
    publishDialogOpen.value = true;
}
function submitPublish(): void {
    publishForm
        .transform((data) => ({
            ...data,
            listing_id: Number(data.listing_id),
            campaign_id:
                data.campaign_id === '' ? null : Number(data.campaign_id),
        }))
        .post('/marketing/publications', {
            preserveScroll: true,
            onSuccess: () => (publishDialogOpen.value = false),
        });
}

const enquiryPublication = ref<Publication | null>(null);
const enquiryLeadId = ref<number | null>(null);
const enquiryForm = useForm({ source_reference: '', received_at: '' });
function openEnquiry(publication: Publication): void {
    enquiryForm.reset();
    enquiryLeadId.value = null;
    enquiryPublication.value = publication;
}
function submitEnquiry(): void {
    if (!enquiryPublication.value) {
        return;
    }
    enquiryForm
        .transform((data) => ({ ...data, lead_id: enquiryLeadId.value }))
        .post(
            `/marketing/publications/${enquiryPublication.value.id}/enquiries`,
            {
                preserveScroll: true,
                onSuccess: () => (enquiryPublication.value = null),
            },
        );
}
</script>

<template>
    <Head :title="t('Marketing & Portals')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Marketing" title="Marketing & Portals" />

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Campaigns') }}
                </h2>
                <Button v-if="canManage" size="sm" @click="openCampaign">{{
                    t('New campaign')
                }}</Button>
            </div>
            <DataTable
                :columns="campaignColumns"
                :rows="campaigns.data"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                empty-title="No campaigns yet."
            >
                <template #cell-budget_aed="{ row }"
                    ><Money :value="row.budget_aed"
                /></template>
                <template #cell-status="{ row }"
                    ><StatusDot :status="row.status"
                /></template>
            </DataTable>
            <Pagination :links="campaigns.links" />
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Publications') }}
                </h2>
                <Button v-if="canManage" size="sm" @click="openPublish">{{
                    t('Publish listing')
                }}</Button>
            </div>
            <DataTable
                :columns="publicationColumns"
                :rows="publications"
                :row-key="(row) => row.id"
                empty-title="No publications yet."
            >
                <template #cell-listing_id="{ row }"
                    >#{{ row.listing_id }}</template
                >
                <template #cell-portal="{ row }"
                    ><Badge variant="outline">{{
                        portalLabel(row.portal)
                    }}</Badge></template
                >
                <template #cell-status="{ row }"
                    ><StatusDot :status="row.status"
                /></template>
                <template #cell-validated_at="{ row }"
                    ><DateText :value="row.validated_at"
                /></template>
                <template #cell-id="{ row }">
                    <Button
                        v-if="canManage"
                        size="sm"
                        variant="outline"
                        @click="openEnquiry(row)"
                        >{{ t('Record enquiry') }}</Button
                    >
                </template>
            </DataTable>
        </section>

        <Dialog v-model:open="campaignDialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('New campaign')
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitCampaign"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="campaign-name">{{ t('Name') }}</Label>
                        <Input id="campaign-name" v-model="campaignForm.name" />
                        <InputError :message="campaignForm.errors.name" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="campaign-type">{{ t('Type') }}</Label>
                        <Input id="campaign-type" v-model="campaignForm.type" />
                        <InputError :message="campaignForm.errors.type" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="campaign-vendor"
                                >{{ t('Vendor ID') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="campaign-vendor"
                                v-model="campaignForm.vendor_id"
                                type="number"
                                min="1"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="campaign-cost-centre"
                                >{{ t('Cost centre ID') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="campaign-cost-centre"
                                v-model="campaignForm.cost_centre_id"
                                type="number"
                                min="1"
                            />
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="campaign-budget"
                            >{{ t('Budget (AED)') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="campaign-budget"
                            v-model="campaignForm.budget_aed"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="campaign-starts"
                                >{{ t('Starts') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="campaign-starts"
                                v-model="campaignForm.starts_on"
                                type="date"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="campaign-ends"
                                >{{ t('Ends') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="campaign-ends"
                                v-model="campaignForm.ends_on"
                                type="date"
                            />
                        </div>
                    </div>
                    <InputError :message="campaignForm.errors.ends_on" />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="campaignDialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="campaignForm.processing"
                            >{{ t('Create') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="publishDialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Publish listing')
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitPublish"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="publish-listing">{{
                            t('Listing ID')
                        }}</Label>
                        <Input
                            id="publish-listing"
                            v-model="publishForm.listing_id"
                            type="number"
                            min="1"
                        />
                        <InputError :message="publishForm.errors.listing_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Portal') }}</Label>
                        <Select v-model="publishForm.portal">
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
                        <InputError :message="publishForm.errors.portal" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label
                            >{{ t('Campaign') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Select
                            :model-value="publishForm.campaign_id"
                            @update:model-value="
                                (value) =>
                                    (publishForm.campaign_id =
                                        (value as string) ?? '')
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue :placeholder="t('None')"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">{{
                                    t('None')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="campaign in campaigns.data"
                                    :key="campaign.id"
                                    :value="String(campaign.id)"
                                    >{{ campaign.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="publishDialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="publishForm.processing"
                            >{{ t('Publish') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="enquiryPublication !== null"
            @update:open="(value) => !value && (enquiryPublication = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Record enquiry')
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitEnquiry"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Lead') }}</Label>
                        <RecordPicker
                            type="lead"
                            v-model="enquiryLeadId"
                            label="Search leads…"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="enquiry-reference">{{
                            t('Source reference')
                        }}</Label>
                        <Input
                            id="enquiry-reference"
                            v-model="enquiryForm.source_reference"
                        />
                        <InputError
                            :message="enquiryForm.errors.source_reference"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="enquiry-received">{{
                            t('Received at')
                        }}</Label>
                        <Input
                            id="enquiry-received"
                            v-model="enquiryForm.received_at"
                            type="datetime-local"
                        />
                        <InputError :message="enquiryForm.errors.received_at" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="enquiryPublication = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="enquiryForm.processing"
                            >{{ t('Save') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
