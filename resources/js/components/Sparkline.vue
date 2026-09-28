<script setup lang="ts">
import { computed } from 'vue';
import { linePath } from '@/lib/chart-paths';
import type { ChartValue } from '@/lib/chart-paths';

const props = withDefaults(
    defineProps<{ values: ChartValue[]; width?: number; height?: number }>(),
    { width: 86, height: 24 },
);

const path = computed(() =>
    linePath(props.values, props.width, props.height, 2),
);
</script>

<template>
    <svg
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        fill="none"
        aria-hidden="true"
        class="shrink-0"
    >
        <path
            :d="path"
            stroke="currentColor"
            stroke-width="1.3"
            stroke-linecap="round"
        />
    </svg>
</template>
