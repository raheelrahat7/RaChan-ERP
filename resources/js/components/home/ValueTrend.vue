<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useLocale } from '@/composables/useLocale';
import { areaPath, linePath } from '@/lib/chart-paths';
import { formatCompact, formatMonth } from '@/lib/format';
import type { HomeTrend } from '@/types/home';

const props = defineProps<{ trend: HomeTrend | null }>();
const { t, locale } = useLocale();
const span = ref<3 | 6 | 12>(12);
const spans = [3, 6, 12] as const;
const id = useId();
const W = 760;
const H = 230;

const slice = computed(() => {
    if (!props.trend || props.trend.months.length === 0) {
        return null;
    }
    const from = Math.max(0, props.trend.months.length - span.value);

    return {
        months: props.trend.months.slice(from),
        sales: props.trend.sales_value.slice(from),
        rental: props.trend.rental_value.slice(from),
    };
});
const domain = computed<[number, number]>(() => {
    const values = [
        ...(slice.value?.sales ?? []),
        ...(slice.value?.rental ?? []),
    ];

    return [0, Math.max(1, ...values) * 1.1];
});
const total = computed(
    () =>
        (props.trend?.sales_value ?? []).reduce((sum, v) => sum + v, 0) +
        (props.trend?.rental_value ?? []).reduce((sum, v) => sum + v, 0),
);
</script>

<template>
    <HomePanel
        title="Sales & rental value"
        subtitle="Closed transaction value, AED"
    >
        <template #action>
            <div
                v-if="slice"
                class="bg-surface-sunken inline-flex rounded-md border p-0.5 text-xs"
            >
                <button
                    v-for="n in spans"
                    :key="n"
                    type="button"
                    :aria-pressed="span === n"
                    :class="[
                        'rounded px-2.5 py-0.5',
                        span === n
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground',
                    ]"
                    @click="span = n"
                >
                    {{ n }}M
                </button>
            </div>
        </template>
        <ComingSoon v-if="!slice" />
        <template v-else>
            <div
                class="text-muted-foreground mb-2 flex flex-wrap gap-5 text-xs"
            >
                <span class="inline-flex items-center gap-2"
                    ><i class="bg-chart-1 h-0.5 w-3 rounded" />{{
                        t('Sales value')
                    }}</span
                >
                <span class="inline-flex items-center gap-2"
                    ><i class="bg-chart-2 h-0.5 w-3 rounded" />{{
                        t('Rental value')
                    }}</span
                >
                <span class="ms-auto"
                    >{{ t('12-month total') }}
                    <b class="text-foreground font-medium"
                        >AED {{ formatCompact(total, locale) }}</b
                    ></span
                >
            </div>
            <figure dir="ltr">
                <svg
                    :viewBox="`0 -6 ${W} ${H + 8}`"
                    class="block h-auto w-full"
                    role="img"
                    :aria-label="t('Sales & rental value')"
                >
                    <defs>
                        <linearGradient
                            :id="`${id}-s`"
                            x1="0"
                            x2="0"
                            y1="0"
                            y2="1"
                        >
                            <stop
                                offset="0"
                                stop-color="var(--chart-1)"
                                stop-opacity="0.22"
                            />
                            <stop
                                offset="1"
                                stop-color="var(--chart-1)"
                                stop-opacity="0"
                            />
                        </linearGradient>
                        <linearGradient
                            :id="`${id}-r`"
                            x1="0"
                            x2="0"
                            y1="0"
                            y2="1"
                        >
                            <stop
                                offset="0"
                                stop-color="var(--chart-2)"
                                stop-opacity="0.25"
                            />
                            <stop
                                offset="1"
                                stop-color="var(--chart-2)"
                                stop-opacity="0"
                            />
                        </linearGradient>
                    </defs>
                    <line
                        v-for="f in [0.25, 0.5, 0.75]"
                        :key="f"
                        x1="0"
                        :x2="W"
                        :y1="H * f"
                        :y2="H * f"
                        class="stroke-border"
                        stroke-dasharray="2 5"
                    />
                    <path
                        :d="areaPath(slice.sales, W, H, 18, domain)"
                        :fill="`url(#${id}-s)`"
                    />
                    <path
                        :d="linePath(slice.sales, W, H, 18, domain)"
                        fill="none"
                        stroke="var(--chart-1)"
                        stroke-width="2"
                    />
                    <path
                        :d="areaPath(slice.rental, W, H, 18, domain)"
                        :fill="`url(#${id}-r)`"
                    />
                    <path
                        :d="linePath(slice.rental, W, H, 18, domain)"
                        fill="none"
                        stroke="var(--chart-2)"
                        stroke-width="2"
                    />
                </svg>
                <figcaption
                    class="text-faint mt-2 flex justify-between text-[10.5px]"
                >
                    <span v-for="month in slice.months" :key="month">{{
                        formatMonth(month, locale)
                    }}</span>
                </figcaption>
            </figure>
        </template>
    </HomePanel>
</template>
