<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, Download, Filter, Plus, Search } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CrmDealBoard from '@/components/CrmDealBoard.vue';
import DataTable from '@/components/DataTable.vue';
import DateText from '@/components/DateText.vue';
import EmptyState from '@/components/EmptyState.vue';
import Money from '@/components/Money.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import {
    amountOf,
    categoryLabel,
    contactName,
    searchDeals,
} from '@/lib/crm-deals';
import type { DataTableColumn, SortState } from '@/lib/data-table';
import type {
    CategoryOption,
    Deal,
    DealPipeline,
    StageTotal,
} from '@/types/crm-deals';

const props = withDefaults(
    defineProps<{
        pipelines: DealPipeline[];
        deals: Deal[];
        totals?: StageTotal[];
        categories?: CategoryOption[];
        canCreate?: boolean;
        canMove?: boolean;
        linkable?: boolean;
        /** When true the server filters by search text; this view only reports what was typed. */
        serverSearch?: boolean;
        initialQuery?: string;
        exportHref?: string | null;
        filterCount?: number;
    }>(),
    {
        totals: undefined,
        categories: () => [],
        canCreate: false,
        canMove: false,
        linkable: false,
        serverSearch: false,
        initialQuery: '',
        exportHref: null,
        filterCount: 0,
    },
);
const pipelineId = defineModel<number>('pipelineId', { required: true });
const emit = defineEmits<{
    create: [];
    createFromLead: [];
    move: [deal: Deal, stageId: number | null];
    search: [query: string];
    filters: [];
}>();

const { t } = useLocale();
type View = 'board' | 'list';
type DealRow = Deal & {
    category_label: string;
    stage_name: string;
    contact_name: string;
    assignee_name: string;
    amount_value: number | null;
};
const view = ref<View>('board');
const query = ref(props.initialQuery);
const sort = ref<SortState>(null);
let searchTimer: number | null = null;

watch(query, (value) => {
    if (!props.serverSearch) {
        return;
    }
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
    searchTimer = window.setTimeout(() => emit('search', value), 350);
});
onBeforeUnmount(() => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
});

const pipeline = computed(() =>
    props.pipelines.find((item) => item.id === Number(pipelineId.value)),
);
const inPipeline = computed(() =>
    props.deals.filter((deal) => deal.pipeline_id === Number(pipelineId.value)),
);
const visible = computed(() =>
    props.serverSearch
        ? inPipeline.value
        : searchDeals(inPipeline.value, query.value, props.categories),
);
const stageName = computed(
    () =>
        new Map(
            (pipeline.value?.stages ?? []).map((stage) => [
                stage.id,
                stage.name,
            ]),
        ),
);
const rows = computed<DealRow[]>(() =>
    visible.value.map((deal) => ({
        ...deal,
        category_label: categoryLabel(deal.category, props.categories),
        stage_name: stageName.value.get(deal.current_stage_id) ?? '',
        contact_name: contactName(deal),
        assignee_name: deal.assignee?.name ?? '',
        amount_value: amountOf(deal),
    })),
);
const columns = computed<DataTableColumn<DealRow>[]>(() => [
    { key: 'title', label: t('Deal'), sortable: true },
    { key: 'category_label', label: t('Type'), sortable: true },
    { key: 'stage_name', label: t('Stage'), sortable: true },
    { key: 'amount_value', label: t('Amount'), sortable: true, align: 'end' },
    { key: 'contact_name', label: t('Contact'), sortable: true },
    { key: 'assignee_name', label: t('Responsible person'), sortable: true },
    { key: 'created_at', label: t('Created'), sortable: true },
]);
const sortedRows = computed(() => {
    if (!sort.value) {
        return rows.value;
    }
    const { key, direction } = sort.value;
    const factor = direction === 'asc' ? 1 : -1;

    return [...rows.value].sort((a, b) => {
        const left = (a as Record<string, unknown>)[key];
        const right = (b as Record<string, unknown>)[key];
        if (left === right) {
            return 0;
        }
        if (left === null || left === '') {
            return 1;
        }
        if (right === null || right === '') {
            return -1;
        }

        return (left! > right! ? 1 : -1) * factor;
    });
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-display text-3xl leading-tight font-medium">
                {{ t('Deals') }}
            </h1>
            <div class="flex">
                <Button
                    type="button"
                    class="rounded-e-none"
                    :disabled="!canCreate"
                    @click="emit('create')"
                    ><Plus class="size-4" aria-hidden="true" />{{
                        t('Create')
                    }}</Button
                >
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            type="button"
                            class="rounded-s-none border-s border-white/20 px-2"
                            :disabled="!canCreate"
                            :aria-label="t('More ways to create a deal')"
                            ><ChevronDown class="size-4" aria-hidden="true"
                        /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem @select="emit('create')">{{
                            t('New deal')
                        }}</DropdownMenuItem>
                        <DropdownMenuItem @select="emit('createFromLead')">{{
                            t('From a lead')
                        }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <select
                v-model="pipelineId"
                :aria-label="t('Pipeline')"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option
                    v-for="item in pipelines"
                    :key="item.id"
                    :value="item.id"
                >
                    {{ item.name }}
                </option>
            </select>
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
            <Button
                v-if="serverSearch"
                type="button"
                variant="outline"
                @click="emit('filters')"
                ><Filter class="size-4" aria-hidden="true" />{{ t('Filters')
                }}<span
                    v-if="filterCount"
                    class="bg-primary text-primary-foreground ms-1 rounded-full px-1.5 text-xs"
                    >{{ filterCount }}</span
                ></Button
            >
            <Button v-if="exportHref" as-child variant="outline"
                ><a :href="exportHref" download
                    ><Download class="size-4" aria-hidden="true" />{{
                        t('Export')
                    }}</a
                ></Button
            >
            <div class="ms-auto flex items-center gap-3 text-sm">
                <Link href="/crm/deal-pipelines" class="underline">{{
                    t('Pipelines and stages')
                }}</Link>
                <Link href="/crm/deal-automation" class="underline">{{
                    t('Automation rules')
                }}</Link>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center gap-2"
            role="group"
            :aria-label="t('Deal view')"
        >
            <Button
                v-for="item in ['board', 'list'] as View[]"
                :key="item"
                type="button"
                size="sm"
                :variant="view === item ? 'default' : 'outline'"
                :aria-pressed="view === item"
                @click="view = item"
                >{{ item === 'board' ? t('Kanban') : t('List') }}</Button
            >
            <span class="text-muted-foreground ms-2 text-sm"
                >{{ visible.length }} {{ t('of') }} {{ inPipeline.length }}
                {{ t('shown') }}</span
            >
        </div>

        <EmptyState
            v-if="!pipelines.length"
            :title="t('No pipelines available to you')"
            :description="
                t('Ask an administrator to give you access to a deal pipeline.')
            "
        />
        <template v-else-if="pipeline">
            <CrmDealBoard
                v-if="view === 'board'"
                :stages="pipeline.stages"
                :deals="visible"
                :totals="totals"
                :categories="categories"
                :can-move="canMove"
                :can-create="canCreate"
                :linkable="linkable"
                @quick="emit('create')"
                @move="(deal, stageId) => emit('move', deal, stageId)"
            />
            <DataTable
                v-else
                v-model:sort="sort"
                :columns="columns"
                :rows="sortedRows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.title"
                :empty-title="t('Create your first deal')"
                :empty-description="
                    t(
                        'Deals appear here when a qualified lead is moved to a pipeline, or when you create one directly.',
                    )
                "
                :caption="t('Deals')"
            >
                <template #cell-title="{ row }"
                    ><Link
                        v-if="linkable"
                        :href="`/deals/${row.id}`"
                        class="text-primary font-medium hover:underline"
                        >{{ row.title }}</Link
                    ><span v-else class="font-medium">{{
                        row.title
                    }}</span></template
                >
                <template #cell-amount_value="{ row }"
                    ><Money
                        v-if="row.amount_value !== null"
                        :value="row.amount_value"
                        :currency="row.currency ?? 'AED'"
                        :decimals="0"
                    /><span v-else>—</span></template
                >
                <template #cell-created_at="{ row }"
                    ><DateText :value="row.created_at"
                /></template>
                <template #empty-action
                    ><Button v-if="canCreate" @click="emit('create')"
                        ><Plus />{{ t('New deal') }}</Button
                    ></template
                >
            </DataTable>
        </template>
    </div>
</template>
