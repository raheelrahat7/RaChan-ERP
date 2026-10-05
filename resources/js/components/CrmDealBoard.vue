<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRightLeft, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import DateText from '@/components/DateText.vue';
import Money from '@/components/Money.vue';
import { Badge } from '@/components/ui/badge';
import { useLocale } from '@/composables/useLocale';
import {
    amountOf,
    categoryLabel,
    computeStageTotals,
    contactName,
    groupDealsByStage,
} from '@/lib/crm-deals';
import { readableOn } from '@/lib/crm-pipeline-board';
import type {
    CategoryOption,
    Deal,
    DealStage,
    StageTotal,
} from '@/types/crm-deals';

const props = withDefaults(
    defineProps<{
        stages: DealStage[];
        deals: Deal[];
        totals?: StageTotal[];
        categories?: CategoryOption[];
        canMove?: boolean;
        canCreate?: boolean;
        linkable?: boolean;
    }>(),
    {
        totals: undefined,
        categories: () => [],
        canMove: false,
        canCreate: false,
        linkable: false,
    },
);
const emit = defineEmits<{
    quick: [];
    move: [deal: Deal, stageId: number | null];
}>();

const { t } = useLocale();
const dragged = ref<Deal | null>(null);
const hovered = ref<number | null>(null);
const columns = computed(() => groupDealsByStage(props.stages, props.deals));
const stageTotals = computed(
    () => props.totals ?? computeStageTotals(props.stages, props.deals),
);
const firstStageId = computed(() => columns.value[0]?.stage.id ?? null);
function totalFor(id: number): StageTotal | undefined {
    return stageTotals.value.find((total) => total.id === id);
}
function movable(deal: Deal): boolean {
    return props.canMove && deal.permissions?.move !== false;
}
function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}
function drop(stage: DealStage): void {
    const deal = dragged.value;
    dragged.value = null;
    hovered.value = null;
    if (deal && deal.current_stage_id !== stage.id && stage.active) {
        emit('move', deal, stage.id);
    }
}
</script>

<template>
    <div
        role="region"
        tabindex="0"
        :aria-label="t('Deal stages')"
        class="focus-visible:outline-ring flex gap-2 overflow-x-auto rounded-md pb-4 focus-visible:outline-2"
    >
        <section
            v-for="column in columns"
            :key="column.stage.id"
            :aria-labelledby="`deal-stage-${column.stage.id}`"
            class="bg-muted/40 flex max-h-[calc(100vh-14rem)] min-h-64 w-72 shrink-0 flex-col rounded-md"
            :class="{
                'ring-primary ring-2': hovered === column.stage.id && dragged,
            }"
            @dragover.prevent="hovered = column.stage.id"
            @dragleave="hovered = null"
            @drop.prevent="drop(column.stage)"
        >
            <h3
                :id="`deal-stage-${column.stage.id}`"
                class="deal-head flex items-center justify-between gap-2 px-4 py-2 text-sm font-medium"
                :style="{
                    backgroundColor: column.stage.color,
                    color: readableOn(column.stage.color),
                }"
            >
                <span class="truncate"
                    >{{ column.stage.name
                    }}<span v-if="!column.stage.active">
                        ({{ t('inactive') }})</span
                    ></span
                >
                <span class="shrink-0 tabular-nums">{{
                    totalFor(column.stage.id)?.count ?? column.deals.length
                }}</span>
            </h3>
            <div
                class="flex items-center justify-between gap-2 px-3 pt-2 text-sm"
            >
                <span
                    v-if="totalFor(column.stage.id)?.amount != null"
                    class="font-medium"
                    ><Money
                        :value="totalFor(column.stage.id)?.amount ?? 0"
                        :decimals="0"
                /></span>
                <span v-else />
                <button
                    v-if="canCreate && column.stage.id === firstStageId"
                    type="button"
                    class="hover:bg-muted rounded p-1"
                    :aria-label="t('Quick deal')"
                    @click="emit('quick')"
                >
                    <Plus class="size-4" aria-hidden="true" />
                </button>
            </div>
            <div class="flex-1 space-y-2 overflow-y-auto p-2">
                <p
                    v-if="!column.deals.length"
                    class="text-muted-foreground rounded-md border border-dashed p-4 text-sm"
                >
                    {{ t('No deals in the current filters.') }}
                </p>
                <article
                    v-for="deal in column.deals"
                    :key="deal.id"
                    class="bg-card space-y-1.5 rounded-md border p-3 text-sm shadow-xs"
                    :class="{
                        'cursor-grab': movable(deal),
                        'opacity-50': dragged?.id === deal.id,
                    }"
                    :draggable="movable(deal)"
                    @dragstart="dragged = deal"
                    @dragend="
                        dragged = null;
                        hovered = null;
                    "
                >
                    <p class="font-medium">
                        <Link
                            v-if="linkable"
                            :href="`/deals/${deal.id}`"
                            class="hover:underline"
                            >{{ deal.title }}</Link
                        >
                        <template v-else>{{ deal.title }}</template>
                    </p>
                    <p
                        v-if="amountOf(deal) !== null"
                        class="font-medium tabular-nums"
                    >
                        <Money
                            :value="amountOf(deal) ?? 0"
                            :currency="deal.currency ?? 'AED'"
                            :decimals="0"
                        />
                    </p>
                    <p
                        v-if="contactName(deal)"
                        class="text-primary truncate text-xs"
                    >
                        {{ contactName(deal) }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{{
                            t(categoryLabel(deal.category, categories))
                        }}</Badge>
                        <span class="text-muted-foreground text-xs"
                            ><DateText :value="deal.created_at"
                        /></span>
                    </div>
                    <div
                        class="flex items-center justify-between gap-2 pt-1 text-xs"
                    >
                        <button
                            v-if="movable(deal)"
                            type="button"
                            class="text-primary inline-flex items-center gap-1 hover:underline"
                            :aria-label="`${t('Move')}: ${deal.title}`"
                            @click="emit('move', deal, null)"
                        >
                            <ArrowRightLeft
                                class="size-3"
                                aria-hidden="true"
                            />{{ t('Move') }}
                        </button>
                        <span v-else />
                        <span
                            v-if="deal.assignee"
                            class="bg-primary/10 text-primary flex size-6 items-center justify-center rounded-full text-[10px] font-semibold"
                            :title="deal.assignee.name"
                            >{{ initials(deal.assignee.name) }}</span
                        >
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>

<style scoped>
.deal-head {
    clip-path: polygon(
        0 0,
        calc(100% - 0.75rem) 0,
        100% 50%,
        calc(100% - 0.75rem) 100%,
        0 100%
    );
}
[dir='rtl'] .deal-head {
    clip-path: polygon(100% 0, 0.75rem 0, 0 50%, 0.75rem 100%, 100% 100%);
}
</style>
