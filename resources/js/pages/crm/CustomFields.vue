<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CrmFieldFormSheet from '@/components/CrmFieldFormSheet.vue';
import type { FieldRecord } from '@/components/CrmFieldFormSheet.vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
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
    { key: 'lead', label: 'Lead' },
    { key: 'contact', label: 'Contact' },
    { key: 'company', label: 'Company' },
    { key: 'deal', label: 'Deal' },
];
const entity = ref(
    ENTITIES.find(
        (item) =>
            item.key ===
            new URLSearchParams(window.location.search).get('entity'),
    )?.key ?? 'lead',
);
const loaded = ref<FieldRecord[]>([]);
const loadError = ref('');
const current = computed<FieldRecord[]>(() =>
    entity.value === 'lead' ? props.fields : loaded.value,
);
const sheetOpen = ref(false);
const editing = ref<FieldRecord | null>(null);
const rows = computed<FieldRow[]>(() =>
    current.value.map((field) => ({
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
    return current.value.find((field) => field.id === id);
}
async function refresh(): Promise<void> {
    if (entity.value === 'lead') {
        return;
    }
    try {
        const data = await apiJson<{ fields: FieldRecord[] }>(
            `/crm/settings/fields?entity=${entity.value}`,
        );
        loaded.value = data.fields;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load these fields.');
    }
}
async function pick(key: string): Promise<void> {
    entity.value = key;
    loaded.value = [];
    window.history.replaceState(
        null,
        '',
        key === 'lead'
            ? '/crm/custom-fields'
            : `/crm/custom-fields?entity=${key}`,
    );
    await refresh();
}
onMounted(refresh);
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
            `${t('Archive')} ${targets.map((field) => field.name).join(', ')}? ${t('Saved values will be preserved.')}`,
        )
    ) {
        for (const field of targets) {
            router.delete(`/crm/custom-fields/${field.id}`, {
                preserveScroll: true,
                onSuccess: () => void refresh(),
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
                <button
                    v-for="item in ENTITIES"
                    :key="item.key"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium"
                    :class="
                        item.key === entity
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted border'
                    "
                    :aria-current="item.key === entity ? 'page' : undefined"
                    @click="pick(item.key)"
                >
                    {{ t(item.label) }}
                </button>
            </nav>
            <div class="flex gap-3 text-sm">
                <Link href="/crm/leads" class="underline">{{
                    t('Back to leads')
                }}</Link>
            </div>
        </div>

        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <CrmSettingsTable
            :title="`${t('Fields')}: ${t(ENTITIES.find((item) => item.key === entity)?.label ?? 'Lead')}`"
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
                    'Select a field to edit it. Delete archives a field and keeps the values saved on existing records.',
                )
            }}
        </p>

        <CrmFieldFormSheet
            v-model:open="sheetOpen"
            :field="editing"
            :types="types"
            :entity="entity"
            @saved="refresh"
        />
    </div>
</template>
