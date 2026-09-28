<script setup lang="ts">
import { computed, useId } from 'vue';
import { areaPath, linePath } from '@/lib/chart-paths';
import type { ChartValue } from '@/lib/chart-paths';

const props = withDefaults(
    defineProps<{
        values: ChartValue[];
        label: string;
        labels?: string[];
        height?: number;
    }>(),
    { labels: () => [], height: 190 },
);

const width = 620;
const gradientId = useId();
const line = computed(() => linePath(props.values, width, props.height, 22));
const area = computed(() => areaPath(props.values, width, props.height, 22));
</script>

<template>
    <figure class="text-chart-1" dir="ltr">
        <svg
            :viewBox="`0 -4 ${width} ${height + 8}`"
            class="block h-auto w-full"
            role="img"
            :aria-label="label"
        >
            <defs>
                <linearGradient :id="gradientId" x1="0" x2="0" y1="0" y2="1">
                    <stop
                        offset="0"
                        stop-color="currentColor"
                        stop-opacity="0.2"
                    />
                    <stop
                        offset="1"
                        stop-color="currentColor"
                        stop-opacity="0"
                    />
                </linearGradient>
            </defs>
            <line
                v-for="fraction in [0.25, 0.5, 0.75]"
                :key="fraction"
                x1="0"
                :x2="width"
                :y1="height * fraction"
                :y2="height * fraction"
                class="stroke-border"
                stroke-dasharray="2 4"
            />
            <path :d="area" :fill="`url(#${gradientId})`" />
            <path
                :d="line"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
            />
        </svg>
        <figcaption
            v-if="labels.length"
            class="text-muted-foreground mt-2 flex justify-between text-[11px]"
        >
            <span v-for="(text, index) in labels" :key="index">{{ text }}</span>
        </figcaption>
    </figure>
</template>
