<script setup lang="ts" generic="Row extends Record<string, unknown>">
import { Plus, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn, RowKey, SortState } from '@/lib/data-table';
import { pageCount, pageSlice } from '@/lib/crm-settings';

const props = withDefaults(
    defineProps<{
        title: string;
        columns: DataTableColumn<Row>[];
        rows: Row[];
        rowKey: (row: Row) => RowKey;
        rowLabel: (row: Row) => string;
        searchable?: boolean;
        canEdit?: boolean;
        addLabel?: string;
    }>(),
    { searchable: false, canEdit: false, addLabel: 'Add' },
);
const emit = defineEmits<{
    add: [];
    edit: [keys: RowKey[]];
    delete: [keys: RowKey[]];
}>();
const slots = defineSlots<Record<string, (props: { row: Row }) => unknown>>();

const { t } = useLocale();
const query = ref('');
const sort = ref<SortState>(null);
const selected = ref<RowKey[]>([]);
const page = ref(1);
const perPage = ref(20);
const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) {
        return props.rows;
    }

    return props.rows.filter((row) =>
        Object.values(row).join(' ').toLowerCase().includes(needle),
    );
});
const sorted = computed(() => {
    if (!sort.value) {
        return filtered.value;
    }
    const { key, direction } = sort.value;
    const factor = direction === 'asc' ? 1 : -1;

    return [...filtered.value].sort((a, b) => {
        const left = a[key] as string | number;
        const right = b[key] as string | number;

        return left === right ? 0 : (left > right ? 1 : -1) * factor;
    });
});
const pages = computed(() => pageCount(sorted.value.length, perPage.value));
const visible = computed(() =>
    pageSlice(sorted.value, page.value, perPage.value),
);

watch([query, perPage], () => (page.value = 1));
watch(pages, (count) => (page.value = Math.min(page.value, count)));
void slots;
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-display text-3xl leading-tight font-medium">
                {{ t(title) }}
            </h1>
            <Button type="button" :disabled="!canEdit" @click="emit('add')"
                ><Plus class="size-4" aria-hidden="true" />{{
                    t(addLabel)
                }}</Button
            >
            <div v-if="searchable" class="relative min-w-56 flex-1 sm:max-w-sm">
                <Search
                    class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="query"
                    type="search"
                    class="ps-9"
                    :aria-label="t('Filter and search')"
                    :placeholder="t('Filter and search')"
                />
            </div>
        </div>
        <DataTable
            v-model:sort="sort"
            v-model:selected="selected"
            :columns="columns"
            :rows="visible"
            :row-key="rowKey"
            :row-label="rowLabel"
            selectable
            :caption="t(title)"
            :empty-title="t('Nothing here yet')"
        >
            <template
                v-for="column in columns"
                :key="column.key"
                #[`cell-${column.key}`]="{ row }"
            >
                <slot :name="`cell-${column.key}`" :row="row">{{
                    row[column.key]
                }}</slot>
            </template>
            <template #bulk>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!canEdit"
                    @click="emit('edit', selected)"
                    >{{ t('Edit') }}</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!canEdit"
                    @click="emit('delete', selected)"
                    >{{ t('Delete') }}</Button
                >
            </template>
            <template #footer>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t p-3 text-xs"
                >
                    <span
                        >{{ t('Selected') }}: {{ selected.length }} /
                        {{ visible.length }} · {{ t('Total') }}:
                        {{ sorted.length }}</span
                    >
                    <span class="flex items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="page <= 1"
                            @click="page--"
                            >{{ t('Previous') }}</Button
                        >
                        <span>{{ t('Pages') }}: {{ page }} / {{ pages }}</span>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="page >= pages"
                            @click="page++"
                            >{{ t('Next') }}</Button
                        >
                    </span>
                    <label class="flex items-center gap-2"
                        >{{ t('Records') }}
                        <select
                            v-model.number="perPage"
                            class="border-input bg-background h-8 rounded-md border px-2"
                        >
                            <option :value="20">20</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </label>
                </div>
            </template>
        </DataTable>
    </div>
</template>
