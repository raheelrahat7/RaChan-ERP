<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';
import type { SlaCycle } from '@/types/sla';

type Job = {
    id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    sla: SlaCycle | null;
};
type Row = {
    id: number;
    job: string;
    priority: string;
    status: string;
    sla: string;
};

const props = defineProps<{
    jobs: {
        data: Job[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: { status?: string; search?: string };
}>();
const { t } = useLocale();
const STATUSES = ['open', 'in_progress', 'on_hold', 'completed', 'cancelled'];
const filters = useForm({
    status: props.filters.status ?? '',
    search: props.filters.search ?? '',
});
const label = (value: string): string => value.replaceAll('_', ' ');

function slaText(sla: SlaCycle | null): string {
    if (!sla) {
        return t('No service targets configured');
    }
    const resolution =
        sla.outcome === 'cancelled'
            ? t('cancelled')
            : sla.resolution_breached
              ? t('breached')
              : t('within target');

    return [
        `${t('Cycle')} ${sla.cycle_number}`,
        sla.held_at ? t('On hold') : '',
        `${t('Response')} ${sla.response_breached ? t('breached') : t('within target')}`,
        `${t('Resolution')} ${resolution}`,
    ]
        .filter(Boolean)
        .join(' · ');
}

const rows = computed<Row[]>(() =>
    props.jobs.data.map((job) => ({
        id: job.id,
        job: `${job.reference} · ${job.title}`,
        priority: job.priority,
        status: job.status,
        sla: slaText(job.sla),
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'job', label: t('Job'), sortable: true },
    { key: 'priority', label: t('Priority'), sortable: true },
    { key: 'status', label: t('Status') },
    { key: 'sla', label: t('Service targets') },
]);

function search(): void {
    filters.get('/operations/helpdesk', { preserveState: true });
}
</script>

<template>
    <Head :title="t('Service helpdesk')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Service helpdesk"
            description="Track jobs, acknowledgements and service targets."
        >
            <template #actions>
                <Link href="/maintenance" class="text-sm underline">{{
                    t('Maintenance')
                }}</Link>
            </template>
        </PageHeader>

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="search">
            <div class="space-y-1">
                <Label for="hd-search">{{ t('Search jobs') }}</Label>
                <Input
                    id="hd-search"
                    v-model="filters.search"
                    :placeholder="t('Reference or title')"
                />
            </div>
            <div class="space-y-1">
                <Label for="hd-status">{{ t('Status') }}</Label>
                <select
                    id="hd-status"
                    v-model="filters.status"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                >
                    <option value="">{{ t('All statuses') }}</option>
                    <option
                        v-for="status in STATUSES"
                        :key="status"
                        :value="status"
                    >
                        {{ label(status) }}
                    </option>
                </select>
            </div>
            <Button :disabled="filters.processing">{{ t('Filter') }}</Button>
        </form>

        <CrmSettingsTable
            title=""
            add-label=""
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.job"
            :selectable="false"
        >
            <template #cell-job="{ row }">
                <Link
                    :href="`/maintenance/${row.id}/job-card`"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    >{{ row.job }}</Link
                >
            </template>
            <template #cell-status="{ row }">
                <Badge variant="secondary">{{ label(row.status) }}</Badge>
            </template>
        </CrmSettingsTable>

        <nav :aria-label="t('Helpdesk pagination')" class="flex gap-4 text-sm">
            <Link
                v-if="jobs.prev_page_url"
                :href="jobs.prev_page_url"
                class="underline"
                >{{ t('Previous') }}</Link
            >
            <Link
                v-if="jobs.next_page_url"
                :href="jobs.next_page_url"
                class="underline"
                >{{ t('Next') }}</Link
            >
        </nav>
    </div>
</template>
