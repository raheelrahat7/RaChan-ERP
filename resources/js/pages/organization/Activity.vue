<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type Row = {
    id: number;
    event: string;
    actor: string;
    subject: string;
    when: string;
};

const props = defineProps<{
    events: {
        data: {
            id: number;
            event: string;
            actor: string;
            subject_type: string | null;
            subject_id: number | null;
            created_at: string;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    members: { id: number; name: string }[];
    filters: { module?: string; actor_id?: number };
}>();
const { t } = useLocale();
const MODULES = [
    'organization',
    'identity',
    'crm',
    'inventory',
    'leasing',
    'transactions',
    'finance',
    'accounting',
    'operations',
    'portal',
    'platform',
    'construction',
    'fleet',
];
const selectClass =
    'border-input bg-background h-9 rounded-md border px-3 text-sm';
const form = useForm({
    module: props.filters.module ?? '',
    actor_id: props.filters.actor_id?.toString() ?? '',
});
const label = (event: string): string =>
    event.replaceAll('.', ' · ').replaceAll('_', ' ');
const rows = computed<Row[]>(() =>
    props.events.data.map((event) => ({
        id: event.id,
        event: label(event.event),
        actor: event.actor,
        subject: event.subject_type
            ? `${event.subject_type} #${event.subject_id}`
            : '',
        when: event.created_at,
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'when', label: t('When'), sortable: true },
    { key: 'event', label: t('Event') },
    { key: 'actor', label: t('Actor'), sortable: true },
    { key: 'subject', label: t('Record') },
]);

function apply(): void {
    form.get('/organization/activity', { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Organization activity')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Organization activity"
            description="Recorded changes across your organization."
        >
            <template #actions>
                <Link href="/organization" class="text-sm underline">{{
                    t('Organization settings')
                }}</Link>
            </template>
        </PageHeader>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div class="space-y-1">
                <Label for="ac-module">{{ t('Module') }}</Label>
                <select
                    id="ac-module"
                    v-model="form.module"
                    :class="selectClass"
                >
                    <option value="">{{ t('All modules') }}</option>
                    <option
                        v-for="module in MODULES"
                        :key="module"
                        :value="module"
                    >
                        {{ module }}
                    </option>
                </select>
            </div>
            <div class="space-y-1">
                <Label for="ac-actor">{{ t('Actor') }}</Label>
                <select
                    id="ac-actor"
                    v-model="form.actor_id"
                    :class="selectClass"
                >
                    <option value="">{{ t('All actors') }}</option>
                    <option
                        v-for="member in members"
                        :key="member.id"
                        :value="String(member.id)"
                    >
                        {{ member.name }}
                    </option>
                </select>
            </div>
            <Button :disabled="form.processing">{{ t('Filter') }}</Button>
        </form>
        <CrmSettingsTable
            :show-title="false"
            title="Organization activity"
            add-label=""
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.event"
            :selectable="false"
        />
        <Pagination :links="events.links" />
    </div>
</template>
