<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmFieldFormSheet from '@/components/CrmFieldFormSheet.vue';
import type { FieldRecord } from '@/components/CrmFieldFormSheet.vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import { Badge } from '@/components/ui/badge';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn, RowKey } from '@/lib/data-table';

const props = defineProps<{ fields: FieldRecord[]; types: string[] }>();
const { t } = useLocale();

type FieldRow = {
    id: number;
    sort_order: number;
    name: string;
    key: string;
    type: string;
    required: string;
    visible_to: string;
    status: string;
};
const ENTITIES = [
    { key: 'lead', label: 'Lead', connected: true },
    { key: 'contact', label: 'Contact', connected: false },
    { key: 'company', label: 'Company', connected: false },
    { key: 'deal', label: 'Deal', connected: false },
];
const sheetOpen = ref(false);
const editing = ref<FieldRecord | null>(null);
const rows = computed<FieldRow[]>(() =>
    props.fields.map((field) => ({
        id: field.id,
        sort_order: field.sort_order ?? 0,
        name: field.name,
        key: field.key,
        type: field.type.replaceAll('_', ' '),
        required: field.required ? t('Yes') : t('No'),
        visible_to: (field.view_roles ?? ['everyone']).join(', '),
        status: field.active ? t('Active') : t('Archived'),
    })),
);
const columns = computed<DataTableColumn<FieldRow>[]>(() => [
    { key: 'sort_order', label: t('Sorting'), sortable: true },
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'key', label: t('Internal key'), sortable: true },
    { key: 'type', label: t('Type'), sortable: true },
    { key: 'required', label: t('Required') },
    { key: 'visible_to', label: t('Visible to') },
    { key: 'status', label: t('Status') },
]);

function byId(id: RowKey): FieldRecord | undefined {
    return props.fields.find((field) => field.id === id);
}
function add(): void {
    editing.value = null;
    sheetOpen.value = true;
}
function edit(keys: RowKey[]): void {
    const field = keys.length ? byId(keys[0]) : undefined;
    if (field) {
        editing.value = field;
        sheetOpen.value = true;
    }
}
function archive(keys: RowKey[]): void {
    const targets = keys
        .map(byId)
        .filter((field): field is FieldRecord => field !== undefined);
    if (
        targets.length &&
        confirm(
            `${t('Archive')} ${targets.map((field) => field.name).join(', ')}? ${t('Saved lead values will be preserved.')}`,
        )
    ) {
        for (const field of targets) {
            router.delete(`/crm/custom-fields/${field.id}`, {
                preserveScroll: true,
            });
        }
    }
}
</script>

<template>
    <Head :title="t('CRM field settings')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav :aria-label="t('Field types')" class="flex flex-wrap gap-2">
                <template v-for="entity in ENTITIES" :key="entity.key">
                    <span
                        v-if="entity.connected"
                        class="bg-primary text-primary-foreground rounded-md px-3 py-1.5 text-sm font-medium"
                        aria-current="page"
                        >{{ t(entity.label) }}</span
                    >
                    <span
                        v-else
                        class="text-muted-foreground flex items-center gap-2 rounded-md border border-dashed px-3 py-1.5 text-sm"
                        aria-disabled="true"
                        >{{ t(entity.label)
                        }}<Badge variant="outline">{{
                            t('Not connected yet')
                        }}</Badge></span
                    >
                </template>
            </nav>
            <div class="flex gap-3 text-sm">
                <Link href="/crm/leads" class="underline">{{
                    t('Back to leads')
                }}</Link>
            </div>
        </div>

        <CrmSettingsTable
            title="Fields: Lead"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            searchable
            can-edit
            add-label="Add field"
            @add="add"
            @edit="edit"
            @delete="archive"
        >
            <template #cell-name="{ row }">
                <button
                    type="button"
                    class="text-primary text-start font-medium underline-offset-2 hover:underline"
                    @click="edit([row.id])"
                >
                    {{ row.name }}
                </button>
            </template>
        </CrmSettingsTable>
        <p class="text-muted-foreground text-xs">
            {{
                t(
                    'Select a field to edit it. Delete archives a field and keeps the values saved on existing leads.',
                )
            }}
        </p>

        <CrmFieldFormSheet
            v-model:open="sheetOpen"
            :field="editing"
            :types="types"
        />
    </div>
</template>
