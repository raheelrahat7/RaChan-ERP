<script setup lang="ts">
import { computed, useId } from 'vue';
import { areaPath, linePath } from '@/lib/chart-paths';

const props = withDefaults(
    defineProps<{
        values: number[];
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

function labelX(index: number): number {
    return props.labels.length > 1
        ? (index * width) / (props.labels.length - 1)
        : 0;
}

function labelAnchor(index: number): 'start' | 'middle' | 'end' {
    if (index === 0) {
        return 'start';
    }

    return index === props.labels.length - 1 ? 'end' : 'middle';
}
</script>

<template>
    <figure class="text-chart-1">
        <svg
            :viewBox="`0 -4 ${width} ${height + 26}`"
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
            <text
                v-for="(text, index) in labels"
                :key="index"
                :x="labelX(index)"
                :y="height + 18"
                :text-anchor="labelAnchor(index)"
                class="fill-muted-foreground text-[10.5px]"
            >
                {{ text }}
            </text>
        </svg>
    </figure>
</template>
