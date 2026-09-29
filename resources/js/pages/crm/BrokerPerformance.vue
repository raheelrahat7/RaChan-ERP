<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useLocale } from '@/composables/useLocale';
import { conversionRate } from '@/lib/sales-crm-tools';
import type { DataTableColumn, SortState } from '@/lib/data-table';

type BrokerRow = {
    broker_id: number;
    name: string;
    team: string | null;
    leads: number;
    converted: number;
    deals: number | null;
    commission_aed: number | null;
};

const props = defineProps<{ brokers: BrokerRow[] }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Broker Performance', href: '/crm/broker-performance' },
        ],
    },
});

const { t } = useLocale();
const columns: DataTableColumn<BrokerRow>[] = [
    { key: 'name', label: 'Broker', sortable: true },
    { key: 'leads', label: 'Leads', align: 'end', sortable: true },
    { key: 'converted', label: 'Converted', align: 'end', sortable: true },
    { key: 'deals', label: 'Deals', align: 'end', sortable: true },
    {
        key: 'commission_aed',
        label: 'Commission',
        align: 'end',
        sortable: true,
    },
];
const sort = ref<SortState>({ key: 'commission_aed', direction: 'desc' });
const hasRestrictedRows = computed(() =>
    props.brokers.some(
        (row) => row.deals === null || row.commission_aed === null,
    ),
);

const rows = computed(() => {
    if (!sort.value) {
        return props.brokers;
    }
    const { key, direction } = sort.value;

    return [...props.brokers].sort((a, b) => {
        const left = a[key as keyof BrokerRow] ?? -1;
        const right = b[key as keyof BrokerRow] ?? -1;
        const order = left < right ? -1 : left > right ? 1 : 0;

        return direction === 'asc' ? order : -order;
    });
});
</script>

<template>
    <Head :title="t('Broker Performance')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Sales & CRM" title="Broker Performance" />
        <DataTable
            v-model:sort="sort"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.broker_id"
            empty-title="No brokers yet"
        >
            <template #cell-name="{ row }">
                <span class="font-medium">{{ row.name }}</span>
                <span v-if="row.team" class="text-muted-foreground">
                    · {{ row.team }}</span
                >
            </template>
            <template #cell-converted="{ row }">
                {{ row.converted }}
                <span
                    v-if="conversionRate(row.converted, row.leads) !== null"
                    class="text-muted-foreground"
                >
                    ({{ conversionRate(row.converted, row.leads) }}%)
                </span>
            </template>
            <template #cell-deals="{ row }">
                <span v-if="row.deals === null" class="text-faint">—</span>
                <span v-else>{{ row.deals }}</span>
            </template>
            <template #cell-commission_aed="{ row }">
                <span v-if="row.commission_aed === null" class="text-faint"
                    >—</span
                >
                <Money v-else :value="row.commission_aed" />
            </template>
        </DataTable>
        <p v-if="hasRestrictedRows" class="text-muted-foreground text-xs">
            {{
                t(
                    'Deals and commission show only to owners without a restricted view.',
                )
            }}
        </p>
    </div>
</template>
