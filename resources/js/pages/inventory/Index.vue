<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, Plus, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import InventoryCreateSheet from '@/components/InventoryCreateSheet.vue';
import type { InventoryKind } from '@/components/InventoryCreateSheet.vue';
import Money from '@/components/Money.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn, SortState } from '@/lib/data-table';

type Property = {
    id: number;
    name: string;
    type: string;
    city: string | null;
    buildings?: { id: number; name: string }[];
};
type Unit = {
    id: number;
    number: string;
    floor: string | null;
    type: string;
    status: string;
    area: string | number | null;
    area_unit: string | null;
    asking_price: string | number | null;
    currency: string | null;
    property: { id: number; name: string } | null;
    building: { id: number; name: string } | null;
};
type UnitRow = Unit & { property_name: string; building_name: string };
type PropertyRow = Property & { building_count: number };

const props = defineProps<{
    properties: Property[];
    units: Unit[];
    canManageInventory: boolean;
}>();
const { t } = useLocale();

const STATUSES = ['available', 'reserved', 'leased', 'sold', 'unavailable'];
const tab = ref<'units' | 'properties'>('units');
const query = ref('');
const status = ref('');
const sort = ref<SortState>(null);
const sheetOpen = ref(false);
const createKind = ref<InventoryKind>('unit');

const unitRows = computed<UnitRow[]>(() => {
    const needle = query.value.trim().toLowerCase();

    return props.units
        .filter((unit) => !status.value || unit.status === status.value)
        .map((unit) => ({
            ...unit,
            property_name: unit.property?.name ?? '',
            building_name: unit.building?.name ?? '',
        }))
        .filter(
            (unit) =>
                !needle ||
                [unit.number, unit.property_name, unit.building_name, unit.type]
                    .join(' ')
                    .toLowerCase()
                    .includes(needle),
        );
});
const propertyRows = computed<PropertyRow[]>(() => {
    const needle = query.value.trim().toLowerCase();

    return props.properties
        .map((property) => ({
            ...property,
            building_count: property.buildings?.length ?? 0,
        }))
        .filter(
            (property) =>
                !needle ||
                [property.name, property.type, property.city ?? '']
                    .join(' ')
                    .toLowerCase()
                    .includes(needle),
        );
});
function sorted<Row extends Record<string, unknown>>(rows: Row[]): Row[] {
    if (!sort.value) {
        return rows;
    }
    const { key, direction } = sort.value;
    const factor = direction === 'asc' ? 1 : -1;

    return [...rows].sort((a, b) => {
        const left = a[key] as string | number | null;
        const right = b[key] as string | number | null;
        if (left === right) {
            return 0;
        }
        if (left === null || left === '') {
            return 1;
        }
        if (right === null || right === '') {
            return -1;
        }

        return (left > right ? 1 : -1) * factor;
    });
}
const unitColumns = computed<DataTableColumn<UnitRow>[]>(() => [
    { key: 'number', label: t('Unit'), sortable: true },
    { key: 'property_name', label: t('Property'), sortable: true },
    { key: 'building_name', label: t('Building'), sortable: true },
    { key: 'floor', label: t('Floor'), sortable: true },
    { key: 'type', label: t('Type'), sortable: true },
    { key: 'status', label: t('Status'), sortable: true },
    { key: 'area', label: t('Area'), sortable: true, align: 'end' },
    {
        key: 'asking_price',
        label: t('Asking price'),
        sortable: true,
        align: 'end',
    },
]);
const propertyColumns = computed<DataTableColumn<PropertyRow>[]>(() => [
    { key: 'name', label: t('Property'), sortable: true },
    { key: 'type', label: t('Type'), sortable: true },
    { key: 'city', label: t('City'), sortable: true },
    {
        key: 'building_count',
        label: t('Buildings'),
        sortable: true,
        align: 'end',
    },
]);

function create(kind: InventoryKind): void {
    createKind.value = kind;
    sheetOpen.value = true;
}
function updateStatus(unit: Unit, value: string): void {
    router.put(
        `/inventory/units/${unit.id}/status`,
        { status: value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="t('Inventory')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-display text-3xl leading-tight font-medium">
                {{ t('Inventory') }}
            </h1>
            <div v-if="canManageInventory" class="flex">
                <Button
                    type="button"
                    class="rounded-e-none"
                    @click="
                        create(
                            tab === 'units' && properties.length
                                ? 'unit'
                                : 'property',
                        )
                    "
                    ><Plus class="size-4" aria-hidden="true" />{{
                        t('Create')
                    }}</Button
                >
                <DropdownMenu>
                    <DropdownMenuTrigger as-child
                        ><Button
                            type="button"
                            class="rounded-s-none border-s border-white/20 px-2"
                            :aria-label="t('More ways to create')"
                            ><ChevronDown
                                class="size-4"
                                aria-hidden="true" /></Button
                    ></DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem @select="create('property')">{{
                            t('New property')
                        }}</DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="!properties.length"
                            @select="create('building')"
                            >{{ t('New building') }}</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            :disabled="!properties.length"
                            @select="create('unit')"
                            >{{ t('New unit') }}</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <div class="relative min-w-56 flex-1 sm:max-w-md">
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
            <select
                v-if="tab === 'units'"
                v-model="status"
                :aria-label="t('Status')"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">{{ t('All statuses') }}</option>
                <option v-for="item in STATUSES" :key="item" :value="item">
                    {{ t(item) }}
                </option>
            </select>
            <Link
                v-if="canManageInventory"
                href="/inventory/imports"
                class="ms-auto text-sm underline"
                >{{ t('Import inventory CSV') }}</Link
            >
        </div>

        <div
            class="flex gap-2"
            role="tablist"
            :aria-label="t('Inventory lists')"
        >
            <Button
                type="button"
                size="sm"
                role="tab"
                :aria-selected="tab === 'units'"
                :variant="tab === 'units' ? 'default' : 'outline'"
                @click="
                    tab = 'units';
                    sort = null;
                "
                >{{ t('Units') }} ({{ units.length }})</Button
            >
            <Button
                type="button"
                size="sm"
                role="tab"
                :aria-selected="tab === 'properties'"
                :variant="tab === 'properties' ? 'default' : 'outline'"
                @click="
                    tab = 'properties';
                    sort = null;
                "
                >{{ t('Properties') }} ({{ properties.length }})</Button
            >
        </div>

        <DataTable
            v-if="tab === 'units'"
            v-model:sort="sort"
            :columns="unitColumns"
            :rows="sorted(unitRows)"
            :row-key="(row) => row.id"
            :row-label="(row) => `${row.property_name} ${row.number}`"
            :caption="t('Units')"
            :empty-title="
                units.length
                    ? t('No units match your filters')
                    : t('Create your first unit')
            "
            :empty-description="
                properties.length
                    ? t('Units appear here once you add them to a property.')
                    : t('Add a property first, then add units to it.')
            "
        >
            <template #cell-number="{ row }"
                ><Link
                    :href="`/inventory/units/${row.id}`"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    >{{ row.number }}</Link
                ></template
            >
            <template #cell-property_name="{ row }"
                ><Link
                    v-if="row.property"
                    :href="`/inventory/properties/${row.property.id}`"
                    class="underline-offset-2 hover:underline"
                    >{{ row.property.name }}</Link
                ></template
            >
            <template #cell-status="{ row }">
                <select
                    v-if="canManageInventory"
                    :value="row.status"
                    :aria-label="`${t('Status')}: ${row.number}`"
                    class="border-input bg-background h-8 rounded-md border px-2 text-xs"
                    @change="
                        updateStatus(
                            row,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option v-for="item in STATUSES" :key="item" :value="item">
                        {{ t(item) }}
                    </option>
                </select>
                <StatusDot v-else :status="row.status" />
            </template>
            <template #cell-area="{ row }"
                ><span v-if="row.area !== null" class="tabular-nums"
                    >{{ row.area }} {{ row.area_unit ?? '' }}</span
                ><span v-else>—</span></template
            >
            <template #cell-asking_price="{ row }"
                ><Money
                    v-if="row.asking_price !== null"
                    :value="row.asking_price"
                    :currency="row.currency ?? 'AED'"
                    :decimals="0"
                /><span v-else>—</span></template
            >
            <template #empty-action
                ><Button
                    v-if="canManageInventory"
                    @click="create(properties.length ? 'unit' : 'property')"
                    ><Plus />{{
                        properties.length ? t('New unit') : t('New property')
                    }}</Button
                ></template
            >
        </DataTable>
        <DataTable
            v-else
            v-model:sort="sort"
            :columns="propertyColumns"
            :rows="sorted(propertyRows)"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            :caption="t('Properties')"
            :empty-title="t('Create your first property')"
            :empty-description="t('Properties hold buildings and units.')"
        >
            <template #cell-name="{ row }"
                ><Link
                    :href="`/inventory/properties/${row.id}`"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    >{{ row.name }}</Link
                ></template
            >
            <template #cell-type="{ row }"
                ><span class="capitalize">{{
                    row.type.replaceAll('_', ' ')
                }}</span></template
            >
            <template #empty-action
                ><Button v-if="canManageInventory" @click="create('property')"
                    ><Plus />{{ t('New property') }}</Button
                ></template
            >
        </DataTable>

        <InventoryCreateSheet
            v-if="canManageInventory"
            v-model:open="sheetOpen"
            :properties="properties"
            :initial-kind="createKind"
        />
    </div>
</template>
