<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import PartyDetailSheet from '@/components/PartyDetailSheet.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
import { partyQuery, shorten } from '@/lib/parties';
import type { DataTableColumn } from '@/lib/data-table';
import type { PartyRecord, PartyType } from '@/lib/parties';

type Paged = {
    data: PartyRecord[];
    current_page: number;
    last_page: number;
    total: number;
};
type Row = PartyRecord & { terms: string; notes: string; actions: string };

const props = defineProps<{ type: PartyType; canCreate: boolean }>();
const { t } = useLocale();

const records = ref<Paged>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const query = ref('');
const loading = ref(true);
const loadError = ref('');
const sheetOpen = ref(false);
const selected = ref<number | null>(null);
const allowCreate = ref(props.canCreate);
let timer: number | null = null;

const rows = computed<Row[]>(() =>
    records.value.data.map((record) => ({
        ...record,
        terms: shorten(record.payment_terms),
        notes: shorten(record.commission_notes),
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'name', label: t('Name') },
    { key: 'reference', label: t('Reference') },
    { key: 'email', label: t('Email') },
    { key: 'phone', label: t('Phone') },
    { key: 'terms', label: t('Payment terms') },
    { key: 'notes', label: t('Commission notes') },
    { key: 'actions', label: '' },
]);

async function load(page = 1): Promise<void> {
    loading.value = true;
    try {
        const data = await apiJson<{
            records: Paged;
            permissions: { create: boolean };
        }>(
            `/real-estate/people/${props.type}/data?${partyQuery(query.value, page)}`,
        );
        records.value = data.records;
        allowCreate.value = data.permissions.create;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load this list.');
    } finally {
        loading.value = false;
    }
}
function reload(): void {
    void load(records.value.current_page);
}
function add(): void {
    selected.value = null;
    sheetOpen.value = true;
}
function show(record: PartyRecord): void {
    selected.value = record.id;
    sheetOpen.value = true;
}
defineExpose({ add });

watch(
    () => props.type,
    () => {
        query.value = '';
        void load(1);
    },
);
watch(query, () => {
    if (timer) {
        clearTimeout(timer);
    }
    timer = window.setTimeout(() => void load(1), 300);
});
onMounted(() => void load());
onBeforeUnmount(() => timer && clearTimeout(timer));
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <Input
                v-model="query"
                type="search"
                class="w-full sm:w-72"
                :placeholder="t('Name or reference')"
                :aria-label="t('Search')"
            />
            <Button v-if="allowCreate" type="button" @click="add">{{
                type === 'owners' ? t('Add owner') : t('Add developer')
            }}</Button>
        </div>
        <DataTable
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            :loading="loading"
            :error="loadError || null"
            :empty-title="t('Nothing here yet.')"
            max-height=""
            @retry="reload"
        >
            <template #cell-actions="{ row }">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="show(row)"
                    >{{
                        row.permissions.edit ? t('Edit') : t('Details')
                    }}</Button
                >
            </template>
        </DataTable>
        <div
            v-if="records.last_page > 1"
            class="flex items-center justify-center gap-3 text-sm"
        >
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="records.current_page <= 1"
                @click="load(records.current_page - 1)"
                >{{ t('Previous') }}</Button
            >
            <span>{{ records.current_page }} / {{ records.last_page }}</span>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="records.current_page >= records.last_page"
                @click="load(records.current_page + 1)"
                >{{ t('Next') }}</Button
            >
        </div>
        <PartyDetailSheet
            v-model:open="sheetOpen"
            :type="type"
            :record-id="selected"
            @saved="reload"
        />
    </div>
</template>
