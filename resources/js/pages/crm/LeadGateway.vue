<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DataTable from '@/components/DataTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import DateText from '@/components/DateText.vue';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type Source = { source_name: string; total: number };
type MetaPage = {
    id: number;
    page_id: string;
    active: boolean;
    subscribed_at: string | null;
};

defineProps<{
    sources: Source[];
    metaPages: MetaPage[];
    canConfigure: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lead Gateway', href: '/crm/lead-gateway' }],
    },
});

const { t } = useLocale();

const sourceColumns: DataTableColumn<Source>[] = [
    { key: 'source_name', label: 'Source' },
    { key: 'total', label: 'Leads', align: 'end' },
];
const metaPageColumns: DataTableColumn<MetaPage>[] = [
    { key: 'page_id', label: 'Meta page ID' },
    { key: 'active', label: 'Status' },
    { key: 'subscribed_at', label: 'Subscribed' },
];
</script>

<template>
    <Head :title="t('Lead Gateway')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            eyebrow="CRM"
            title="Lead Gateway"
            description="Where your leads are coming from, and which channels are connected."
        />

        <section class="flex flex-col gap-3">
            <h2 class="font-display text-2xl font-medium">
                {{ t('Lead sources') }}
            </h2>
            <DataTable
                :columns="sourceColumns"
                :rows="sources"
                :row-key="(row) => row.source_name"
                :row-label="(row) => row.source_name"
                empty-title="No leads recorded yet."
            />
        </section>

        <section v-if="canConfigure" class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Connected Meta pages') }}
                </h2>
                <Button as-child size="sm" variant="outline">
                    <Link href="/crm/assignment">{{
                        t('Manage in Broker Allocation')
                    }}</Link>
                </Button>
            </div>
            <DataTable
                :columns="metaPageColumns"
                :rows="metaPages"
                :row-key="(row) => row.id"
                empty-title="No Meta pages connected yet."
            >
                <template #cell-active="{ row }">
                    <Badge :variant="row.active ? 'outline' : 'secondary'">{{
                        t(row.active ? 'Active' : 'Inactive')
                    }}</Badge>
                </template>
                <template #cell-subscribed_at="{ row }">
                    <DateText
                        v-if="row.subscribed_at"
                        :value="row.subscribed_at"
                    />
                    <span v-else class="text-faint">—</span>
                </template>
            </DataTable>
        </section>
    </div>
</template>
