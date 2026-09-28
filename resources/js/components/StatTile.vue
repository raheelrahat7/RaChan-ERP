<script setup lang="ts">
import { computed } from 'vue';
import Sparkline from '@/components/Sparkline.vue';
import { useLocale } from '@/composables/useLocale';
import { statFigure } from '@/lib/format';
import type { NumericInput } from '@/lib/format';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        label: string;
        value: NumericInput;
        currency?: string;
        unit?: string;
        decimals?: number;
        compact?: boolean;
        trend?: string;
        trendTone?: 'positive' | 'negative' | 'neutral';
        series?: number[];
    }>(),
    {
        currency: undefined,
        unit: undefined,
        decimals: 0,
        compact: true,
        trend: undefined,
        trendTone: 'neutral',
        series: undefined,
    },
);

const { t, locale } = useLocale();
const parts = computed(() =>
    statFigure(
        {
            value: props.value,
            currency: props.currency,
            unit: props.unit,
            compact: props.compact,
            decimals: props.decimals,
        },
        locale.value,
    ),
);
const trendClass = computed(
    () =>
        ({
            positive: 'text-success',
            negative: 'text-destructive',
            neutral: 'text-muted-foreground',
        })[props.trendTone],
);
</script>

<template>
    <div class="bg-background flex flex-col px-6 py-5">
        <span class="text-label">{{ t(label) }}</span>
        <span
            class="font-display mt-3 mb-2.5 text-[38px] leading-none font-medium tracking-[-0.02em] whitespace-nowrap lining-nums tabular-nums"
        >
            <span
                v-if="parts.prefix"
                class="text-muted-foreground me-1.5 align-[0.9em] font-sans text-[11px] tracking-[0.12em]"
                >{{ parts.prefix }}</span
            >{{ parts.figure
            }}<span
                v-if="parts.suffix"
                class="text-muted-foreground ms-2.5 text-[0.5em]"
                >{{ parts.suffix }}</span
            >
        </span>
        <div class="flex min-h-6 items-center justify-between gap-2 text-xs">
            <span v-if="trend" :class="trendClass">{{ trend }}</span>
            <Sparkline
                v-if="series?.length"
                :values="series"
                :class="cn('ms-auto', trendClass)"
            />
        </div>
    </div>
</template>
