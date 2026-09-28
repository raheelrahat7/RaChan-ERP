<script setup lang="ts" generic="Row">
import { ArrowDown, ArrowUp, ArrowUpDown, TriangleAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLocale } from '@/composables/useLocale';
import {
    ariaSort,
    nextSort,
    pruneSelection,
    selectionState,
    toggleAll,
    toggleOne,
} from '@/lib/data-table';
import type { DataTableColumn, RowKey, SortState } from '@/lib/data-table';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        columns: DataTableColumn<Row>[];
        rows: Row[];
        rowKey: (row: Row) => RowKey;
        rowLabel?: (row: Row) => string;
        /** Scroll area limit so the header can stay in view; '' to disable. */
        maxHeight?: string;
        loading?: boolean;
        error?: string | null;
        emptyTitle?: string;
        emptyDescription?: string;
        selectable?: boolean;
        caption?: string;
    }>(),
    {
        rowLabel: undefined,
        maxHeight: 'max-h-[70vh]',
        loading: false,
        error: null,
        emptyTitle: 'Nothing here yet',
        emptyDescription: undefined,
        selectable: false,
        caption: undefined,
    },
);

const sort = defineModel<SortState>('sort', { default: null });
const selected = defineModel<RowKey[]>('selected', { default: () => [] });
const emit = defineEmits<{ retry: [] }>();
defineSlots<
    {
        toolbar?: () => unknown;
        bulk?: (props: { selected: RowKey[] }) => unknown;
        'empty-action'?: () => unknown;
        footer?: () => unknown;
    } & {
        [Key in `cell-${Extract<keyof Row, string>}`]?: (props: {
            row: Row;
            value: unknown;
        }) => unknown;
    }
>();
const { t } = useLocale();

const visibleKeys = computed(() => props.rows.map((row) => props.rowKey(row)));
const headerState = computed(() =>
    selectionState(selected.value, visibleKeys.value),
);
// Selection only ever covers rows the user can see, so bulk actions never
// act on rows hidden by a filter or another page.
watch(visibleKeys, (keys) => {
    const pruned = pruneSelection(selected.value, keys);
    if (pruned !== selected.value) {
        selected.value = pruned;
    }
});

const columnCount = computed(
    () => props.columns.length + (props.selectable ? 1 : 0),
);

function cellValue(row: Row, key: Extract<keyof Row, string>): unknown {
    return row[key];
}

function rowName(row: Row): string {
    return props.rowLabel ? props.rowLabel(row) : String(props.rowKey(row));
}

function display(value: unknown): string {
    return value === null || value === undefined || value === ''
        ? '—'
        : String(value);
}

function alignClass(column: DataTableColumn<Row>): string | undefined {
    return column.align === 'end' ? 'text-end' : undefined;
}
</script>

<template>
    <div class="bg-card shadow-panel overflow-hidden rounded-lg border">
        <slot name="toolbar" />
        <div
            v-if="selectable && selected.length"
            class="bg-champagne/10 flex flex-wrap items-center gap-3 border-b px-5 py-2.5 text-xs"
        >
            <span class="font-medium">{{
                t(':count selected', { count: selected.length })
            }}</span>
            <slot name="bulk" :selected="selected" />
        </div>
        <Table :container-class="maxHeight">
            <caption v-if="caption" class="sr-only">
                {{
                    caption
                }}
            </caption>
            <TableHeader class="bg-card sticky top-0 z-10">
                <TableRow class="hover:bg-transparent">
                    <TableHead v-if="selectable">
                        <Checkbox
                            :model-value="headerState"
                            :aria-label="t('Select all rows')"
                            @update:model-value="
                                selected = toggleAll(selected, visibleKeys)
                            "
                        />
                    </TableHead>
                    <TableHead
                        v-for="column in columns"
                        :key="column.key"
                        :aria-sort="
                            column.sortable
                                ? ariaSort(sort, column.key)
                                : undefined
                        "
                        :class="cn(alignClass(column), column.class)"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            :class="
                                cn(
                                    'hover:text-foreground focus-visible:ring-ring inline-flex items-center gap-1 rounded-sm uppercase outline-none focus-visible:ring-2',
                                    sort?.key === column.key &&
                                        'text-foreground',
                                )
                            "
                            @click="sort = nextSort(sort, column.key)"
                        >
                            {{ t(column.label) }}
                            <ArrowUp
                                v-if="
                                    sort?.key === column.key &&
                                    sort.direction === 'asc'
                                "
                                class="size-3"
                            />
                            <ArrowDown
                                v-else-if="sort?.key === column.key"
                                class="size-3"
                            />
                            <ArrowUpDown v-else class="size-3 opacity-40" />
                        </button>
                        <template v-else>{{ t(column.label) }}</template>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <template v-if="loading">
                    <TableRow
                        v-for="n in 5"
                        :key="`skeleton-${n}`"
                        class="hover:bg-transparent"
                    >
                        <TableCell v-if="selectable">
                            <Skeleton class="size-4" />
                        </TableCell>
                        <TableCell v-for="column in columns" :key="column.key">
                            <Skeleton
                                class="h-3"
                                :style="{ width: `${50 + ((n * 17) % 40)}%` }"
                            />
                        </TableCell>
                    </TableRow>
                </template>
                <TableRow v-else-if="error" class="hover:bg-transparent">
                    <TableCell :colspan="columnCount" class="p-5">
                        <Alert variant="destructive">
                            <TriangleAlert />
                            <AlertTitle>{{
                                t('Something went wrong')
                            }}</AlertTitle>
                            <AlertDescription>
                                <p>{{ error }}</p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="mt-2"
                                    @click="emit('retry')"
                                    >{{ t('Try again') }}</Button
                                >
                            </AlertDescription>
                        </Alert>
                    </TableCell>
                </TableRow>
                <TableRow
                    v-else-if="rows.length === 0"
                    class="hover:bg-transparent"
                >
                    <TableCell :colspan="columnCount">
                        <EmptyState
                            :title="t(emptyTitle)"
                            :description="
                                emptyDescription
                                    ? t(emptyDescription)
                                    : undefined
                            "
                        >
                            <slot name="empty-action" />
                        </EmptyState>
                    </TableCell>
                </TableRow>
                <template v-else>
                    <TableRow
                        v-for="row in rows"
                        :key="rowKey(row)"
                        :data-state="
                            selected.includes(rowKey(row))
                                ? 'selected'
                                : undefined
                        "
                    >
                        <TableCell v-if="selectable">
                            <Checkbox
                                :model-value="selected.includes(rowKey(row))"
                                :aria-label="
                                    t('Select :row', { row: rowName(row) })
                                "
                                @update:model-value="
                                    selected = toggleOne(selected, rowKey(row))
                                "
                            />
                        </TableCell>
                        <TableCell
                            v-for="column in columns"
                            :key="column.key"
                            :class="cn(alignClass(column), column.class)"
                        >
                            <slot
                                :name="`cell-${column.key}`"
                                :row="row"
                                :value="cellValue(row, column.key)"
                                >{{ display(cellValue(row, column.key)) }}</slot
                            >
                        </TableCell>
                    </TableRow>
                </template>
            </TableBody>
        </Table>
        <slot name="footer" />
    </div>
</template>
