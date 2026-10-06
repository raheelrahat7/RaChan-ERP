<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CrmConditionEditor from '@/components/CrmConditionEditor.vue';
import CrmLeadPicker from '@/components/CrmLeadPicker.vue';
import CrmDealFormSheet from '@/components/CrmDealFormSheet.vue';
import CrmDealMoveDialog from '@/components/CrmDealMoveDialog.vue';
import CrmDealsView from '@/components/CrmDealsView.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocale } from '@/composables/useLocale';
import { cleanConditions, exportQuery } from '@/lib/crm-deal-filters';
import type { Condition, FilterField } from '@/lib/crm-deal-filters';
import { creatablePipelines, stageTotalsFromCounts } from '@/lib/crm-deals';
import type {
    CategoryOption,
    Deal,
    DealPipeline,
    StageCount,
} from '@/types/crm-deals';

const props = defineProps<{
    deals: {
        data: Deal[];
        current_page: number;
        last_page: number;
        total: number;
    };
    pipelines: DealPipeline[];
    stageCounts: StageCount[];
    filters: {
        pipeline_id?: number | null;
        q?: string | null;
        page?: number | null;
        custom_filters?: Condition[];
    };
    categoryOptions: CategoryOption[];
    filterFields?: FilterField[];
}>();
const { t } = useLocale();

const pipelineId = ref(
    props.filters.pipeline_id ?? props.pipelines[0]?.id ?? 0,
);
const formOpen = ref(false);
const editing = ref<Deal | null>(null);
const moveOpen = ref(false);
const moving = ref<Deal | null>(null);
const moveStage = ref<number | null>(null);
const pickerOpen = ref(false);
const fromLeadId = ref<number | null>(null);
const prefill = ref<Record<string, string> | undefined>(undefined);
const query = ref(props.filters.q ?? '');
const filterFields = computed(() => props.filterFields ?? []);
const appliedFilters = computed(() =>
    cleanConditions(filterFields.value, props.filters.custom_filters ?? []),
);
const filtersOpen = ref(false);
const draft = ref<Condition[]>([]);
const exportHref = computed(() =>
    (pipeline.value?.permissions?.export?.length ?? 0) > 0
        ? `/crm/deals/export?${exportQuery({
              pipeline_id: pipelineId.value,
              q: query.value,
              custom_filters: appliedFilters.value,
          })}`
        : null,
);

const pipeline = computed(() =>
    props.pipelines.find((item) => item.id === Number(pipelineId.value)),
);
const totals = computed(() =>
    pipeline.value
        ? stageTotalsFromCounts(pipeline.value, props.stageCounts)
        : undefined,
);
const canCreate = computed(
    () => creatablePipelines(props.pipelines).length > 0,
);
const canMove = computed(
    () => (pipeline.value?.permissions?.move?.length ?? 0) > 0,
);

function visit(extra: Record<string, unknown> = {}): void {
    router.get(
        '/deals',
        {
            pipeline_id: pipelineId.value,
            q: query.value || undefined,
            custom_filters: appliedFilters.value.length
                ? appliedFilters.value
                : undefined,
            ...extra,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}
function openFilters(): void {
    draft.value = structuredClone(appliedFilters.value);
    filtersOpen.value = true;
}
function applyFilters(conditions: Condition[]): void {
    filtersOpen.value = false;
    visit({
        custom_filters: conditions.length ? conditions : undefined,
        page: undefined,
    });
}
function reload(): void {
    router.reload({ only: ['deals', 'stageCounts', 'pipelines'] });
}
function create(): void {
    editing.value = null;
    fromLeadId.value = null;
    prefill.value = undefined;
    formOpen.value = true;
}
function pickLead(
    lead: { id: number },
    values: { title: string; first_name: string; last_name: string },
): void {
    editing.value = null;
    fromLeadId.value = lead.id;
    prefill.value = values;
    formOpen.value = true;
}
function startMove(deal: Deal, stageId: number | null): void {
    moving.value = deal;
    moveStage.value = stageId;
    moveOpen.value = true;
}
watch(pipelineId, (value) => {
    if (value !== props.filters.pipeline_id) {
        visit({ page: undefined });
    }
});
</script>

<template>
    <Head :title="t('Deals')" />
    <div class="flex w-full flex-1 flex-col gap-4 p-4 md:p-6">
        <CrmDealsView
            v-model:pipeline-id="pipelineId"
            :pipelines="pipelines"
            :deals="deals.data"
            :totals="totals"
            :categories="categoryOptions"
            :can-create="canCreate"
            :can-move="canMove"
            linkable
            server-search
            :initial-query="query"
            :export-href="exportHref"
            :filter-count="appliedFilters.length"
            @filters="openFilters"
            @create="create"
            @create-from-lead="pickerOpen = true"
            @move="startMove"
            @search="
                (value) => {
                    query = value;
                    visit({ page: undefined });
                }
            "
        />
        <div
            v-if="deals.last_page > 1"
            class="flex items-center justify-center gap-3 text-sm"
        >
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="deals.current_page <= 1"
                @click="visit({ page: deals.current_page - 1 })"
                >{{ t('Previous') }}</Button
            >
            <span
                >{{ t('Page') }} {{ deals.current_page }} {{ t('of') }}
                {{ deals.last_page }} · {{ deals.total }} {{ t('deals') }}</span
            >
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="deals.current_page >= deals.last_page"
                @click="visit({ page: deals.current_page + 1 })"
                >{{ t('Next') }}</Button
            >
        </div>

        <CrmDealFormSheet
            v-model:open="formOpen"
            :pipelines="pipelines"
            :categories="categoryOptions"
            :deal="editing"
            :lead-id="fromLeadId"
            :prefill="prefill"
            :default-pipeline-id="pipelineId"
            @saved="reload"
        />
        <CrmDealMoveDialog
            v-model:open="moveOpen"
            :deal="moving"
            :stages="pipeline?.stages ?? []"
            :initial-stage-id="moveStage"
            @moved="reload"
        />

        <Dialog v-model:open="filtersOpen">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ t('Filters') }}</DialogTitle>
                    <DialogDescription>{{
                        t('Show only deals that match all of these conditions.')
                    }}</DialogDescription>
                </DialogHeader>
                <CrmConditionEditor v-model="draft" :fields="filterFields" />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="applyFilters([])"
                        >{{ t('Clear') }}</Button
                    >
                    <Button
                        type="button"
                        @click="
                            applyFilters(cleanConditions(filterFields, draft))
                        "
                        >{{ t('Apply') }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <CrmLeadPicker v-model:open="pickerOpen" @pick="pickLead" />
    </div>
</template>
