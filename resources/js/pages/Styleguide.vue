<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, FileText, Inbox, Plus, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import AreaChart from '@/components/AreaChart.vue';
import CrmDealsView from '@/components/CrmDealsView.vue';
import CrmPermissionsMatrix from '@/components/CrmPermissionsMatrix.vue';
import CrmSettingsHub from '@/components/CrmSettingsHub.vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import DataTable from '@/components/DataTable.vue';
import DateText from '@/components/DateText.vue';
import DetailLayout from '@/components/DetailLayout.vue';
import EmptyState from '@/components/EmptyState.vue';
import FilterBar from '@/components/FilterBar.vue';
import FilterChip from '@/components/FilterChip.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatGrid from '@/components/StatGrid.vue';
import StatTile from '@/components/StatTile.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import type { DataTableColumn, RowKey, SortState } from '@/lib/data-table';
import { STATUS_TONES } from '@/lib/status-tones';
import type {
    PermissionGroup,
    PermissionRole,
    PermissionValues,
} from '@/lib/crm-permissions';
import type { Deal, DealPipeline } from '@/types/crm-deals';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Styleguide', href: '/styleguide' }],
    },
});

type Invoice = {
    id: number;
    reference: string;
    tenant: string;
    unit: string;
    due: string;
    amount: number;
    status: string;
};

const dealPipelines: DealPipeline[] = [
    {
        id: 1,
        name: 'Off plan (Team C)',
        active: true,
        stages: [
            {
                id: 11,
                name: 'Viewing booked',
                type: 'normal',
                color: '#4ba3ff',
                position: 1,
                active: true,
            },
            {
                id: 12,
                name: 'Follow up',
                type: 'normal',
                color: '#ffe680',
                position: 2,
                active: true,
            },
            {
                id: 13,
                name: 'Contract sent',
                type: 'normal',
                color: '#5b7f2b',
                position: 3,
                active: true,
            },
            {
                id: 14,
                name: 'Deal won',
                type: 'won',
                color: '#8bd13f',
                position: 4,
                active: true,
            },
            {
                id: 15,
                name: 'Deal lost',
                type: 'lost',
                color: '#ee6a5b',
                position: 5,
                active: true,
            },
        ],
    },
    {
        id: 2,
        name: 'Resale (Team X)',
        active: true,
        stages: [
            {
                id: 21,
                name: 'New',
                type: 'normal',
                color: '#4ba3ff',
                position: 1,
                active: true,
            },
            {
                id: 22,
                name: 'Deal won',
                type: 'won',
                color: '#8bd13f',
                position: 2,
                active: true,
            },
        ],
    },
];
const dealCategories = [
    { code: 'offplan', name: 'Off plan', active: true },
    { code: 'resale', name: 'Resale', active: true },
];
const sampleDeals: Deal[] = [
    {
        id: 1,
        title: 'Marina Heights 1204 · Al Noor',
        amount: '1850000.00',
        currency: 'AED',
        category: 'offplan',
        pipeline_id: 1,
        current_stage_id: 11,
        first_name: 'Hassan',
        last_name: 'Al Noor',
        assignee: { id: 1, name: 'Sana Nadeem' },
        lead_id: 31,
        version: 1,
        created_at: '2026-09-14',
    },
    {
        id: 2,
        title: 'Palm Vista 08 · Khan',
        amount: '3200000.00',
        currency: 'AED',
        category: 'offplan',
        pipeline_id: 1,
        current_stage_id: 11,
        first_name: 'Amir',
        last_name: 'Khan',
        assignee: { id: 2, name: 'Hamza Arif' },
        lead_id: null,
        version: 1,
        created_at: '2026-09-21',
    },
    {
        id: 3,
        title: 'Creek Residences 3B',
        amount: '2400000.00',
        currency: 'AED',
        category: 'offplan',
        pipeline_id: 1,
        current_stage_id: 12,
        first_name: 'Nadia',
        last_name: 'Raza',
        assignee: { id: 1, name: 'Sana Nadeem' },
        lead_id: 44,
        version: 1,
        created_at: '2026-08-30',
    },
    {
        id: 4,
        title: 'Dubai Hills Villa 14',
        amount: '7900000.00',
        currency: 'AED',
        category: 'offplan',
        pipeline_id: 1,
        current_stage_id: 13,
        first_name: 'Pratik',
        last_name: 'Hemdev',
        assignee: { id: 3, name: 'Aqsa Zafar' },
        lead_id: 12,
        version: 1,
        created_at: '2026-07-13',
    },
    {
        id: 5,
        title: 'Business Bay 2201',
        amount: '1340000.00',
        currency: 'AED',
        category: 'offplan',
        pipeline_id: 1,
        current_stage_id: 14,
        first_name: 'Mandy',
        last_name: 'Thoe',
        assignee: { id: 2, name: 'Hamza Arif' },
        lead_id: 8,
        version: 1,
        created_at: '2026-06-02',
    },
    {
        id: 6,
        title: 'JVC Studio 410',
        amount: null,
        currency: 'AED',
        category: 'resale',
        pipeline_id: 2,
        current_stage_id: 21,
        assignee: null,
        lead_id: null,
        version: 1,
        created_at: '2026-10-01',
    },
];
const dealPipelineId = ref(1);
const permissionGroups: PermissionGroup[] = [
    {
        key: 'lead',
        label: 'Lead',
        rows: [
            { key: 'read', label: 'Read', kind: 'level' },
            { key: 'add', label: 'Add', kind: 'level' },
            { key: 'edit', label: 'Edit', kind: 'level' },
            { key: 'export', label: 'Export', kind: 'level' },
            { key: 'form', label: 'Allow custom view form', kind: 'toggle' },
        ],
    },
    {
        key: 'deal-offplan',
        label: 'Deal pipeline: Off plan (Team C)',
        rows: [
            { key: 'read', label: 'Read', kind: 'level' },
            { key: 'move', label: 'Move to stage', kind: 'level' },
            { key: 'amount', label: 'Amount on kanban stages', kind: 'toggle' },
        ],
    },
    {
        key: 'deal-resale',
        label: 'Deal pipeline: Resale (Team X)',
        rows: [
            { key: 'read', label: 'Read', kind: 'level' },
            { key: 'move', label: 'Move to stage', kind: 'level' },
        ],
    },
];
const permissionRoles: PermissionRole[] = [
    {
        id: 1,
        name: 'Manager',
        members: [
            { id: 1, name: 'Sana Nadeem' },
            { id: 2, name: 'Hamza Arif' },
        ],
    },
    {
        id: 2,
        name: 'Agent',
        members: [
            { id: 3, name: 'Aqsa Zafar' },
            { id: 4, name: 'Raza Khan' },
            { id: 5, name: 'Faraz Azam' },
            { id: 6, name: 'Zeeshan Idrees' },
            { id: 7, name: 'Naina Shoaib' },
        ],
    },
    { id: 3, name: 'Role name', members: [] },
];
const permissionValues: PermissionValues = {
    1: {
        'lead.read': 'all',
        'lead.add': 'all',
        'lead.edit': 'department',
        'lead.form': true,
        'deal-offplan.read': 'all',
        'deal-offplan.move': 'all',
    },
    2: {
        'lead.read': 'own',
        'lead.add': 'own',
        'lead.edit': 'own',
        'deal-offplan.read': 'own',
    },
};
const currencyRows = [
    {
        id: 'AED',
        name: 'UAE Dirham',
        sort: 100,
        rate: 1,
        base: 'Yes',
        reporting: 'Yes',
    },
    {
        id: 'EUR',
        name: 'Euro',
        sort: 100,
        rate: 0.2506,
        base: 'No',
        reporting: 'No',
    },
    {
        id: 'GBP',
        name: 'Pound Sterling',
        sort: 100,
        rate: 0.2186,
        base: 'No',
        reporting: 'No',
    },
    {
        id: 'INR',
        name: 'Indian Rupee',
        sort: 100,
        rate: 20.57,
        base: 'No',
        reporting: 'No',
    },
    {
        id: 'PKR',
        name: 'Pakistani Rupee',
        sort: 100,
        rate: 0.023,
        base: 'No',
        reporting: 'No',
    },
    {
        id: 'USD',
        name: 'US Dollar',
        sort: 100,
        rate: 0.2722,
        base: 'No',
        reporting: 'No',
    },
];
const currencyColumns = [
    { key: 'id', label: 'ID', sortable: true },
    { key: 'name', label: 'Name', sortable: true },
    { key: 'sort', label: 'Sort', sortable: true },
    { key: 'rate', label: 'Exchange rate', sortable: true },
    { key: 'base', label: 'Base' },
    { key: 'reporting', label: 'Reporting currency' },
] as DataTableColumn<(typeof currencyRows)[number]>[];

const invoices: Invoice[] = [
    {
        id: 1,
        reference: 'INV-2294',
        tenant: 'Al Noor Trading LLC',
        unit: 'A-1204',
        due: '2026-09-15',
        amount: 36250,
        status: 'paid',
    },
    {
        id: 2,
        reference: 'INV-2293',
        tenant: 'Sara Al Mansoori',
        unit: 'B-0310',
        due: '2026-09-30',
        amount: 24125,
        status: 'pending',
    },
    {
        id: 3,
        reference: 'INV-2291',
        tenant: 'Gulf Line Logistics',
        unit: 'C-0701',
        due: '2026-09-14',
        amount: 212000,
        status: 'overdue',
    },
    {
        id: 4,
        reference: 'INV-2288',
        tenant: 'Hamdan Family Office',
        unit: 'PH-02',
        due: '2026-09-28',
        amount: 1140000,
        status: 'partially_paid',
    },
    {
        id: 5,
        reference: 'INV-2287',
        tenant: 'Rania Khoury',
        unit: 'B-1102',
        due: '',
        amount: 8400,
        status: 'draft',
    },
    {
        id: 6,
        reference: 'INV-2280',
        tenant: 'Omar Tahir',
        unit: 'P-114',
        due: '2026-08-20',
        amount: 1500,
        status: 'void',
    },
];

const columns: DataTableColumn<Invoice>[] = [
    { key: 'reference', label: 'Invoice', sortable: true },
    { key: 'tenant', label: 'Tenant', sortable: true },
    { key: 'unit', label: 'Unit' },
    { key: 'due', label: 'Due', sortable: true },
    { key: 'amount', label: 'Amount', align: 'end', sortable: true },
    { key: 'status', label: 'Status' },
];

const tableStates = ['data', 'loading', 'empty', 'error'] as const;
const tableState = ref<(typeof tableStates)[number]>('data');
const search = ref('');
const sort = ref<SortState>({ key: 'reference', direction: 'desc' });
const selected = ref<RowKey[]>([2, 3]);
const welcomePack = ref(true);

const rows = computed(() => {
    const term = search.value.trim().toLowerCase();
    const filtered = invoices.filter(
        (invoice) =>
            term === '' ||
            `${invoice.reference} ${invoice.tenant} ${invoice.unit}`
                .toLowerCase()
                .includes(term),
    );
    const current = sort.value;
    if (!current) {
        return filtered;
    }

    return [...filtered].sort((a, b) => {
        const key = current.key as keyof Invoice;
        const order = String(a[key]).localeCompare(String(b[key]), undefined, {
            numeric: true,
        });

        return current.direction === 'asc' ? order : -order;
    });
});

const { money, date } = useFormat();
const facts = computed(() => [
    { label: 'Tenant', value: 'Gulf Line Logistics' },
    { label: 'Unit', value: 'C-0701 · Marina Heights' },
    { label: 'Issued', value: date('2026-09-01') },
    { label: 'Due', value: date('2026-09-14') },
    { label: 'Amount', value: money(212000) },
    { label: 'Balance', value: money(212000) },
]);

const collection = [
    1.21, 1.34, 1.3, 1.42, 1.39, 1.55, 1.61, 1.58, 1.72, 1.79, 1.86, 2.02,
];
const months = [
    'Oct',
    'Nov',
    'Dec',
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
];
</script>

<template>
    <Head title="Styleguide" />

    <div class="flex flex-1 flex-col gap-14 p-4 md:p-8">
        <PageHeader
            eyebrow="Internal · Local only"
            title="Bordeaux styleguide"
            description="Every building block in one place. Switch appearance and language to check light, dark and Arabic."
        >
            <template #actions>
                <Button variant="outline"><Download />Export</Button>
                <Button><Plus />New invoice</Button>
            </template>
        </PageHeader>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">
                Deals board (layout preview, sample data)
            </h2>
            <CrmDealsView
                v-model:pipeline-id="dealPipelineId"
                :pipelines="dealPipelines"
                :deals="sampleDeals"
                :categories="dealCategories"
                can-create
            />
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Settings hub (layout preview)</h2>
            <CrmSettingsHub />
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">
                Settings table: Currencies (layout preview, sample data)
            </h2>
            <CrmSettingsTable
                title="Currencies"
                :columns="currencyColumns"
                :rows="currencyRows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                add-label="Add"
            />
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">
                Access permissions (layout preview, sample data)
            </h2>
            <CrmPermissionsMatrix
                :groups="permissionGroups"
                :roles="permissionRoles"
                :values="permissionValues"
                can-edit
            />
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Stat tiles</h2>
            <StatGrid>
                <StatTile
                    label="Occupancy"
                    :value="94.2"
                    unit="%"
                    :compact="false"
                    :decimals="1"
                    trend="▲ 1.8 pts"
                    trend-tone="positive"
                    :series="[3, 4, 3.6, 4.4, 4.2, 5, 5.4, 6]"
                />
                <StatTile
                    label="Collected"
                    :value="2418500"
                    currency="AED"
                    trend="▲ 6.4%"
                    trend-tone="positive"
                    :series="[2, 2.6, 2.4, 3.2, 3.4, 4.1, 4.3, 5]"
                />
                <StatTile
                    label="Overdue"
                    :value="186200"
                    currency="AED"
                    trend="12 invoices"
                    trend-tone="negative"
                    :series="[3, 3.4, 3.1, 4, 4.6, 4.4, 5.2, 5.6]"
                />
                <StatTile
                    label="Open work orders"
                    :value="37"
                    trend="5 past SLA"
                    trend-tone="negative"
                />
            </StatGrid>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Data table</h2>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="state in tableStates"
                    :key="state"
                    size="sm"
                    :variant="tableState === state ? 'default' : 'outline'"
                    @click="tableState = state"
                    >{{ state }}</Button
                >
            </div>
            <DataTable
                v-model:sort="sort"
                v-model:selected="selected"
                :columns="columns"
                :rows="tableState === 'empty' ? [] : rows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.reference"
                :loading="tableState === 'loading'"
                :error="
                    tableState === 'error'
                        ? 'The bank feed did not respond.'
                        : null
                "
                selectable
                empty-title="No invoices yet"
                empty-description="Invoices appear here when a lease or sale generates them."
                caption="Sample invoices"
            >
                <template #toolbar>
                    <FilterBar
                        v-model="search"
                        placeholder="Search invoice, tenant or unit"
                        :result-label="`${rows.length} invoices`"
                        clearable
                        @clear="search = ''"
                    >
                        <FilterChip label="Status" value="All" />
                        <FilterChip
                            label="Property"
                            value="Marina Heights"
                            removable
                        />
                    </FilterBar>
                </template>
                <template #bulk>
                    <Button size="sm" variant="outline"
                        ><Send />Send reminders</Button
                    >
                </template>
                <template #cell-reference="{ row }">
                    <span class="font-medium tracking-[0.02em]">{{
                        row.reference
                    }}</span>
                </template>
                <template #cell-due="{ row }">
                    <DateText :value="row.due" />
                </template>
                <template #cell-amount="{ row }">
                    <Money :value="row.amount" />
                </template>
                <template #cell-status="{ row }">
                    <StatusDot :status="row.status" />
                </template>
                <template #empty-action>
                    <Button><Plus />New invoice</Button>
                </template>
            </DataTable>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Status language</h2>
            <div
                class="bg-card shadow-panel flex flex-wrap gap-x-7 gap-y-3 rounded-lg border p-5"
            >
                <StatusDot
                    v-for="status in Object.keys(STATUS_TONES)"
                    :key="status"
                    :status="status"
                />
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Record page</h2>
            <DetailLayout
                eyebrow="Invoice · Commercial lease"
                title="INV-2291"
                status="overdue"
                :facts="facts"
            >
                <template #actions>
                    <Button variant="outline"><Send />Send reminder</Button>
                    <Button><FileText />Record payment</Button>
                </template>
                <Tabs default-value="overview">
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="payments">Payments</TabsTrigger>
                        <TabsTrigger value="activity">Activity</TabsTrigger>
                    </TabsList>
                    <TabsContent value="overview" class="pt-6">
                        <div class="bg-card shadow-panel rounded-lg border p-6">
                            <h3
                                class="font-display mb-2 text-[22px] font-medium"
                            >
                                Rent collection
                            </h3>
                            <AreaChart
                                :values="collection"
                                :labels="months"
                                label="Rent collection over twelve months"
                            />
                        </div>
                    </TabsContent>
                    <TabsContent value="payments" class="pt-6">
                        <div class="bg-card shadow-panel rounded-lg border">
                            <EmptyState
                                title="No payments yet"
                                description="Payments recorded against this invoice appear here."
                            >
                                <template #icon><Inbox /></template>
                                <Button><Plus />Record payment</Button>
                            </EmptyState>
                        </div>
                    </TabsContent>
                    <TabsContent value="activity" class="pt-6">
                        <p class="text-muted-foreground text-sm">
                            The activity timeline arrives with the finance
                            phase.
                        </p>
                    </TabsContent>
                </Tabs>
            </DetailLayout>
        </section>

        <section class="flex max-w-4xl flex-col">
            <h2 class="text-eyebrow">Form</h2>
            <FormSection
                title="Tenant & unit"
                description="Who is leasing, and which unit."
            >
                <FormField id="sg-tenant" label="Tenant" full>
                    <Input id="sg-tenant" model-value="Al Noor Trading LLC" />
                </FormField>
                <FormField
                    id="sg-unit"
                    label="Unit"
                    help="Only vacant or reserved units are listed."
                    full
                    v-slot="{ describedBy }"
                >
                    <Input
                        id="sg-unit"
                        model-value="Marina Heights · A-1204"
                        :aria-describedby="describedBy"
                    />
                </FormField>
            </FormSection>
            <FormSection
                title="Term & rent"
                description="Dates, rent and payment schedule."
            >
                <FormField id="sg-start" label="Start date">
                    <Input id="sg-start" type="date" model-value="2026-10-01" />
                </FormField>
                <FormField id="sg-end" label="End date">
                    <Input id="sg-end" type="date" model-value="2029-09-30" />
                </FormField>
                <FormField
                    id="sg-rent"
                    label="Annual rent"
                    error="Annual rent must be greater than zero."
                    full
                    v-slot="{ describedBy, invalid }"
                >
                    <Input
                        id="sg-rent"
                        model-value="0"
                        :aria-invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </FormField>
                <FormField id="sg-notes" label="Notes" optional full>
                    <Textarea
                        id="sg-notes"
                        placeholder="Anything the leasing team should know…"
                    />
                </FormField>
            </FormSection>
            <FormSection
                title="Notifications"
                description="What happens when the lease is created."
            >
                <FormField
                    id="sg-welcome"
                    label="Email the tenant a welcome pack"
                    help="Includes the signed agreement, payment schedule and portal invitation."
                    full
                >
                    <Switch id="sg-welcome" v-model="welcomePack" />
                </FormField>
            </FormSection>
            <div class="flex justify-end gap-2 pt-6">
                <Button variant="ghost">Cancel</Button>
                <Button variant="outline">Save as draft</Button>
                <Button>Create lease</Button>
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Command palette</h2>
            <Command class="shadow-overlay max-w-xl rounded-xl border">
                <CommandInput placeholder="Search or jump to…" />
                <CommandList>
                    <CommandEmpty>No results.</CommandEmpty>
                    <CommandGroup heading="Pages">
                        <CommandItem value="invoices"
                            ><FileText />Invoices</CommandItem
                        >
                        <CommandItem value="bank"
                            >Bank reconciliation</CommandItem
                        >
                        <CommandItem value="vat">VAT return</CommandItem>
                    </CommandGroup>
                    <CommandGroup heading="Actions">
                        <CommandItem value="lease"
                            ><Plus />New lease</CommandItem
                        >
                    </CommandGroup>
                </CommandList>
            </Command>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Buttons</h2>
            <div class="flex flex-wrap items-center gap-3">
                <Button size="lg"><Plus />Primary large</Button>
                <Button>Primary</Button>
                <Button size="sm">Small</Button>
                <Button variant="outline">Outline</Button>
                <Button variant="secondary">Secondary</Button>
                <Button variant="ghost">Ghost</Button>
                <Button variant="destructive-outline">Void</Button>
                <Button variant="destructive">Delete</Button>
                <Button variant="link">Link</Button>
                <Button disabled>Disabled</Button>
            </div>
        </section>
    </div>
</template>
