<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';

const props = defineProps<{
    stages: { label: string; count: number; won?: boolean }[];
}>();
const { t } = useLocale();
const first = computed(() => props.stages[0]?.count || 0);

function share(count: number): number {
    return first.value ? Math.round((count / first.value) * 100) : 0;
}
</script>

<template>
    <div class="flex overflow-x-auto">
        <div
            v-for="(stage, i) in stages"
            :key="stage.label"
            :class="[
                'relative min-w-[92px] flex-1 border px-3 pt-3 pb-2.5',
                i > 0 ? 'border-s-0' : 'rounded-s-lg',
                i === stages.length - 1 ? 'rounded-e-lg' : '',
                stage.won ? 'bg-success/8' : 'bg-surface-sunken',
            ]"
        >
            <span
                class="text-muted-foreground block truncate text-[10px] tracking-[0.14em] uppercase"
                >{{ t(stage.label) }}</span
            >
            <b
                :class="[
                    'font-display block text-[28px] leading-tight font-medium lining-nums',
                    stage.won ? 'text-success' : '',
                ]"
                >{{ stage.count }}</b
            >
            <span class="text-muted-foreground block min-h-4 text-[11px]">{{
                i > 0 && first
                    ? t(':percent of start', {
                          percent: `${share(stage.count)}%`,
                      })
                    : ''
            }}</span>
            <i
                class="from-primary to-champagne absolute start-0 bottom-0 h-[3px] bg-linear-to-r rtl:bg-linear-to-l"
                :style="{ width: `${Math.max(6, share(stage.count))}%` }"
            />
        </div>
    </div>
</template>
