<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import ListingCreateSheet from '@/components/ListingCreateSheet.vue';
import ListingEditSheet from '@/components/ListingEditSheet.vue';
import InputError from '@/components/InputError.vue';
import WorkflowStatusesSheet from '@/components/WorkflowStatusesSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
import {
    activeFilterCount,
    countFor,
    emptyFilters,
    filtersQuery,
    moneyText,
    personName,
    segmentLabel,
    statusName,
    stripStatuses,
} from '@/lib/listings';
import type { DataTableColumn } from '@/lib/data-table';
import type { Filters, ListingRow, WorkflowStatus } from '@/lib/listings';

type Person = { id: number; name: string };
type Paged = {
    data: ListingRow[];
    current_page: number;
    last_page: number;
    total: number;
};
type Row = ListingRow & {
    where: string;
    segment: string;
    priceText: string;
    sqft: string;
    seller_name: string;
    buyer_name: string;
    actions: string;
};

const props = defineProps<{
    units: { id: number; number: string }[];
    properties: {
        id: number;
        name: string;
        type: string;
        city: string | null;
    }[];
    buildings: {
        id: number;
        property_id: number;
        name: string;
        floors: number | null;
    }[];
    brokers: Person[];
    owners?: Person[];
    costCentres?: Person[];
    buyerContacts?: { id: number; first_name: string; last_name: string }[];
    workflowStatuses?: WorkflowStatus[];
    canManage: boolean;
    canManageTransactions: boolean;
    canManageInventory: boolean;
    marketSegment: 'primary' | 'secondary' | null;
}>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 rounded-md border px-3 text-sm';

const secondaryPage = computed(() => props.marketSegment === 'secondary');
const listings = ref<Paged>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const statuses = ref<WorkflowStatus[]>(props.workflowStatuses ?? []);
const emirates = ref<{ emirate: string | null; total: number }[]>([]);
const summary = ref<{ code: string; total: number }[] | null>(null);
const filters = ref<Filters>(emptyFilters());
const loading = ref(true);
const loadError = ref('');
const notice = ref('');
let timer: number | null = null;

const createOpen = ref(false);
const statusesOpen = ref(false);
const editing = ref<ListingRow | null>(null);
const editOpen = ref(false);
const inquiryFor = ref<ListingRow | null>(null);
const inquiryOpen = ref(false);
const inquiryForm = useForm({
    listing_id: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    notes: '',
});

const strip = computed(() =>
    stripStatuses(statuses.value, filters.value.workflow_status),
);
const canCreate = computed(
    () =>
        props.canManage && (props.canManageInventory || props.units.length > 0),
);
const rows = computed<Row[]>(() =>
    listings.value.data.map((listing) => ({
        ...listing,
        where: [listing.property?.name, listing.unit?.number]
            .filter(Boolean)
            .join(' · '),
        segment: t(segmentLabel(listing)),
        priceText: moneyText(listing.price, listing.currency),
        sqft: listing.price_per_sqft ?? '—',
        seller_name: listing.seller?.name ?? '—',
        buyer_name: personName(listing.buyer),
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference') },
    { key: 'where', label: t('Property and unit') },
    { key: 'segment', label: t('Type') },
    { key: 'priceText', label: t('Price'), align: 'end' },
    { key: 'sqft', label: t('Per sq ft'), align: 'end' },
    { key: 'workflow_status', label: t('Workflow') },
    { key: 'status', label: t('Status') },
    ...(secondaryPage.value
        ? ([
              { key: 'valuation_price', label: t('Valuation'), align: 'end' },
              { key: 'mortgage_status', label: t('Mortgage') },
              { key: 'noc_status', label: t('NOC') },
              { key: 'transfer_status', label: t('Transfer') },
              { key: 'seller_name', label: t('Seller') },
              { key: 'buyer_name', label: t('Buyer') },
          ] as DataTableColumn<Row>[])
        : []),
    { key: 'actions', label: '' },
]);
const filterCount = computed(() => activeFilterCount(filters.value));

async function load(page = 1): Promise<void> {
    loading.value = true;
    try {
        const data = await apiJson<{
            listings: Paged;
            workflowStatuses: WorkflowStatus[];
            emirateSummary: { emirate: string | null; total: number }[];
            secondaryStatusSummary: { code: string; total: number }[] | null;
        }>(
            `/real-estate/listings/data?${filtersQuery(filters.value, props.marketSegment, page)}`,
        );
        listings.value = data.listings;
        statuses.value = data.workflowStatuses;
        emirates.value = data.emirateSummary;
        summary.value = data.secondaryStatusSummary;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load listings.');
    } finally {
        loading.value = false;
    }
}
function refreshAll(): void {
    router.reload({
        only: ['units', 'properties', 'buildings', 'owners', 'buyerContacts'],
    });
    void load(listings.value.current_page);
}

watch(
    () => ({ ...filters.value, q: undefined }),
    () => void load(1),
);
watch(
    () => filters.value.q,
    () => {
        if (timer) {
            clearTimeout(timer);
        }
        timer = window.setTimeout(() => void load(1), 300);
    },
);
onMounted(() => void load());
onBeforeUnmount(() => timer && clearTimeout(timer));

function pickStatus(code: string): void {
    filters.value.workflow_status = code;
}
function clearFilters(): void {
    filters.value = emptyFilters();
}
function startEdit(listing: ListingRow): void {
    editing.value = listing;
    editOpen.value = true;
}
function startInquiry(listing: ListingRow): void {
    inquiryFor.value = listing;
    inquiryForm.reset();
    inquiryForm.clearErrors();
    inquiryOpen.value = true;
}
function recordInquiry(): void {
    if (!inquiryFor.value) {
        return;
    }
    inquiryForm.listing_id = String(inquiryFor.value.id);
    inquiryForm.post('/real-estate/listings/inquiries', {
        preserveScroll: true,
        onSuccess: () => {
            inquiryOpen.value = false;
            notice.value = t('Inquiry saved.');
        },
    });
}
function legacyPut(
    listing: ListingRow,
    path: 'status' | 'market-segment',
    data: Record<string, string>,
): void {
    router.put(
        `/real-estate/listings/${listing.id}/${path}`,
        { ...data, expected_version: listing.version },
        {
            preserveScroll: true,
            onSuccess: () => void load(listings.value.current_page),
            onError: (errors) => {
                loadError.value = Object.values(errors)[0] ?? '';
            },
        },
    );
}
const valueOf = (event: Event): string =>
    (event.target as HTMLSelectElement).value;
const toneOf = (status: string): string =>
    ({
        active: 'bg-emerald-100 text-emerald-900',
        paused: 'bg-amber-100 text-amber-900',
        closed: 'bg-muted text-muted-foreground',
        draft: 'bg-muted text-muted-foreground',
    })[status] ?? 'bg-muted text-muted-foreground';
</script>

<template>
    <Head :title="secondaryPage ? t('Secondary Market') : t('Listings')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="secondaryPage ? 'Secondary Market' : 'Listings'"
            :description="
                secondaryPage
                    ? 'Resale and rental listings linked to the existing property inventory and CRM.'
                    : 'Market available units for rent or sale.'
            "
        >
            <template #actions>
                <Button
                    v-if="canManage"
                    type="button"
                    variant="outline"
                    @click="statusesOpen = true"
                    >{{ t('Workflow statuses') }}</Button
                >
                <Button
                    v-if="canCreate"
                    type="button"
                    @click="createOpen = true"
                    >{{ t('New listing') }}</Button
                >
            </template>
        </PageHeader>

        <nav :aria-label="t('Listing views')" class="flex flex-wrap gap-2">
            <Link
                href="/real-estate/listings"
                class="hover:bg-muted rounded-md border px-3 py-2 text-sm"
                :class="marketSegment === null && 'bg-muted font-medium'"
                >{{ t('All listings') }}</Link
            >
            <Link
                href="/real-estate/secondary-market"
                class="hover:bg-muted rounded-md border px-3 py-2 text-sm"
                :class="secondaryPage && 'bg-muted font-medium'"
                >{{ t('Secondary market') }}</Link
            >
            <Link
                href="/real-estate/listings?market_segment=primary"
                class="hover:bg-muted rounded-md border px-3 py-2 text-sm"
                :class="marketSegment === 'primary' && 'bg-muted font-medium'"
                >{{ t('Primary sales') }}</Link
            >
        </nav>

        <Card v-if="canManage && !canManageInventory && !units.length"
            ><CardHeader
                ><CardTitle>{{
                    t('No available units to list')
                }}</CardTitle></CardHeader
            ><CardContent class="space-y-2 text-sm"
                ><p>
                    {{
                        t(
                            'Create a property and an available unit in Property inventory first. Then return here to create a resale or rental listing.',
                        )
                    }}
                </p>
                <p>
                    {{
                        t('Ask an inventory manager to add an available unit.')
                    }}
                </p>
            </CardContent></Card
        >

        <nav :aria-label="t('Listing workflow')" class="space-y-1">
            <ol class="flex flex-wrap gap-1.5">
                <li>
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="
                            filters.workflow_status === ''
                                ? 'bg-primary text-primary-foreground border-primary'
                                : 'bg-background text-muted-foreground hover:bg-muted'
                        "
                        @click="pickStatus('')"
                    >
                        {{ t('All') }}
                    </button>
                </li>
                <li v-for="status in strip" :key="status.code">
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="
                            filters.workflow_status === status.code
                                ? 'bg-primary text-primary-foreground border-primary'
                                : 'bg-background text-muted-foreground hover:bg-muted'
                        "
                        @click="pickStatus(status.code)"
                    >
                        {{ status.name
                        }}<template
                            v-if="countFor(summary, status.code) !== null"
                        >
                            ({{ countFor(summary, status.code) }})</template
                        >
                    </button>
                </li>
            </ol>
        </nav>

        <Card>
            <CardContent class="space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        v-model="filters.q"
                        type="search"
                        class="w-full sm:w-64"
                        :placeholder="t('Reference or community')"
                        :aria-label="t('Search listings')"
                    />
                    <select
                        v-model="filters.status"
                        :class="selectClass"
                        :aria-label="t('Filter by listing status')"
                    >
                        <option value="">{{ t('Any status') }}</option>
                        <option value="draft">{{ t('Draft') }}</option>
                        <option value="active">{{ t('Active') }}</option>
                        <option value="paused">{{ t('Paused') }}</option>
                        <option value="closed">{{ t('Closed') }}</option>
                    </select>
                    <select
                        v-model="filters.broker_id"
                        :class="selectClass"
                        :aria-label="t('Broker')"
                    >
                        <option value="">{{ t('Any broker') }}</option>
                        <option
                            v-for="broker in brokers"
                            :key="broker.id"
                            :value="String(broker.id)"
                        >
                            {{ broker.name }}
                        </option>
                    </select>
                    <select
                        v-if="costCentres?.length"
                        v-model="filters.cost_centre_id"
                        :class="selectClass"
                        :aria-label="t('Cost centre')"
                    >
                        <option value="">{{ t('Any cost centre') }}</option>
                        <option
                            v-for="centre in costCentres"
                            :key="centre.id"
                            :value="String(centre.id)"
                        >
                            {{ centre.name }}
                        </option>
                    </select>
                    <Input
                        v-model="filters.listing_category"
                        class="w-40"
                        :placeholder="t('Category')"
                        :aria-label="t('Listing category')"
                    />
                    <Input
                        v-model="filters.community"
                        class="w-40"
                        :placeholder="t('Community')"
                        :aria-label="t('Community')"
                    />
                    <select
                        v-model="filters.sort"
                        :class="selectClass"
                        :aria-label="t('Sort')"
                    >
                        <option value="latest">{{ t('Latest') }}</option>
                        <option value="price_asc">
                            {{ t('Price: low to high') }}
                        </option>
                        <option value="price_desc">
                            {{ t('Price: high to low') }}
                        </option>
                    </select>
                    <Button
                        v-if="filterCount"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="clearFilters"
                        >{{ t('Clear filters') }}</Button
                    >
                </div>
                <div
                    v-if="emirates.some((item) => item.emirate)"
                    class="flex flex-wrap items-center gap-1.5 text-xs"
                >
                    <span class="text-muted-foreground"
                        >{{ t('Emirate') }}:</span
                    >
                    <button
                        v-for="item in emirates.filter((row) => row.emirate)"
                        :key="item.emirate ?? ''"
                        type="button"
                        class="rounded-full border px-2.5 py-0.5"
                        :class="
                            filters.emirate === item.emirate
                                ? 'bg-primary text-primary-foreground border-primary'
                                : 'hover:bg-muted'
                        "
                        @click="
                            filters.emirate =
                                filters.emirate === item.emirate
                                    ? ''
                                    : (item.emirate ?? '')
                        "
                    >
                        {{ item.emirate }} ({{ item.total }})
                    </button>
                </div>
                <p v-if="notice" role="status" class="text-sm">{{ notice }}</p>
                <DataTable
                    :columns="columns"
                    :rows="rows"
                    :row-key="(row) => row.id"
                    :row-label="(row) => row.reference"
                    :loading="loading"
                    :error="loadError || null"
                    :empty-title="t('No listings yet.')"
                    max-height=""
                    @retry="load(listings.current_page)"
                >
                    <template #cell-reference="{ row }">
                        <span class="font-medium">{{ row.reference }}</span>
                    </template>
                    <template #cell-segment="{ row }">
                        <select
                            v-if="
                                canManage &&
                                row.purpose === 'sale' &&
                                !row.market_segment
                            "
                            class="border-input h-8 rounded-md border px-2 text-sm"
                            :aria-label="t('Sale market segment')"
                            @change="
                                legacyPut(row, 'market-segment', {
                                    market_segment: valueOf($event),
                                })
                            "
                        >
                            <option selected disabled value="">
                                {{ t('Classify sale') }}
                            </option>
                            <option value="primary">{{ t('Primary') }}</option>
                            <option value="secondary">{{ t('Resale') }}</option>
                        </select>
                        <template v-else>{{ row.segment }}</template>
                    </template>
                    <template #cell-workflow_status="{ row }">
                        <Badge variant="secondary">{{
                            statusName(statuses, row.workflow_status)
                        }}</Badge>
                    </template>
                    <template #cell-status="{ row }">
                        <select
                            v-if="canManage"
                            :value="row.status"
                            class="border-input h-8 rounded-md border px-2 text-sm"
                            :aria-label="t('Listing status')"
                            @change="
                                legacyPut(row, 'status', {
                                    status: valueOf($event),
                                })
                            "
                        >
                            <option value="draft">{{ t('Draft') }}</option>
                            <option value="active">{{ t('Active') }}</option>
                            <option value="paused">{{ t('Paused') }}</option>
                            <option value="closed">{{ t('Closed') }}</option>
                        </select>
                        <span
                            v-else
                            class="rounded-full px-2 py-0.5 text-xs"
                            :class="toneOf(row.status)"
                            >{{ t(row.status) }}</span
                        >
                    </template>
                    <template #cell-valuation_price="{ row }">{{
                        moneyText(row.valuation_price, row.currency)
                    }}</template>
                    <template #cell-actions="{ row }">
                        <div class="flex flex-wrap justify-end gap-1.5">
                            <Button
                                v-if="row.permissions.edit"
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="startEdit(row)"
                                >{{ t('Edit') }}</Button
                            >
                            <Button
                                v-if="canManage"
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="startInquiry(row)"
                                >{{ t('Record inquiry') }}</Button
                            >
                            <Button
                                v-if="
                                    canManageTransactions &&
                                    row.status === 'active' &&
                                    (row.purpose === 'rent' ||
                                        row.market_segment === 'secondary')
                                "
                                as-child
                                size="sm"
                                variant="outline"
                                ><Link
                                    :href="`/reservations?listing_id=${row.id}`"
                                    >{{ t('Reserve') }}</Link
                                ></Button
                            >
                            <Button
                                v-if="row.status === 'active' && row.public_url"
                                as-child
                                size="sm"
                                variant="outline"
                                ><a
                                    :href="row.public_url"
                                    target="_blank"
                                    rel="noopener"
                                    >{{ t('Public page') }}</a
                                ></Button
                            >
                        </div>
                    </template>
                </DataTable>
                <div
                    v-if="listings.last_page > 1"
                    class="flex items-center justify-center gap-3 text-sm"
                >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="listings.current_page <= 1"
                        @click="load(listings.current_page - 1)"
                        >{{ t('Previous') }}</Button
                    >
                    <span
                        >{{ listings.current_page }} /
                        {{ listings.last_page }}</span
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="listings.current_page >= listings.last_page"
                        @click="load(listings.current_page + 1)"
                        >{{ t('Next') }}</Button
                    >
                </div>
            </CardContent>
        </Card>

        <ListingCreateSheet
            v-model:open="createOpen"
            :secondary-page="secondaryPage"
            :can-manage-inventory="canManageInventory"
            :units="units"
            :properties="properties"
            :buildings="buildings"
            :brokers="brokers"
            :owners="owners ?? []"
            :cost-centres="costCentres ?? []"
            :buyer-contacts="buyerContacts ?? []"
            :workflow-statuses="statuses"
            @created="refreshAll"
        />
        <ListingEditSheet
            v-model:open="editOpen"
            :listing="editing"
            :secondary="secondaryPage"
            :workflow-statuses="statuses"
            :owners="owners ?? []"
            :brokers="brokers"
            :cost-centres="costCentres ?? []"
            :buyer-contacts="buyerContacts ?? []"
            @saved="refreshAll"
        />
        <WorkflowStatusesSheet
            v-model:open="statusesOpen"
            :statuses="statuses"
            @changed="load(listings.current_page)"
        />

        <Dialog v-model:open="inquiryOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >{{ t('Record inquiry for') }}
                        {{ inquiryFor?.reference }}</DialogTitle
                    >
                </DialogHeader>
                <form
                    class="grid gap-3 sm:grid-cols-2"
                    @submit.prevent="recordInquiry"
                >
                    <label class="space-y-1 text-sm"
                        >{{ t('First name')
                        }}<Input v-model="inquiryForm.first_name" required
                    /></label>
                    <label class="space-y-1 text-sm"
                        >{{ t('Last name')
                        }}<Input v-model="inquiryForm.last_name" required
                    /></label>
                    <label class="space-y-1 text-sm"
                        >{{ t('Email')
                        }}<Input v-model="inquiryForm.email" type="email"
                    /></label>
                    <label class="space-y-1 text-sm"
                        >{{ t('Phone') }}<Input v-model="inquiryForm.phone"
                    /></label>
                    <label class="space-y-1 text-sm sm:col-span-2"
                        >{{ t('Notes') }}<Input v-model="inquiryForm.notes"
                    /></label>
                    <InputError
                        class="sm:col-span-2"
                        :message="Object.values(inquiryForm.errors).join(' ')"
                    />
                    <DialogFooter class="sm:col-span-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="inquiryOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="inquiryForm.processing"
                            >{{ t('Save inquiry') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
