<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    rows: { label: string; value: number; display?: string }[];
}>();
const max = computed(() => Math.max(1, ...props.rows.map((row) => row.value)));
const colors = [
    'var(--chart-1)',
    'var(--chart-5)',
    'var(--chart-2)',
    'var(--chart-4)',
    'var(--chart-3)',
];
</script>

<template>
    <ul class="flex flex-col gap-2.5">
        <li
            v-for="(row, i) in rows"
            :key="row.label"
            class="grid grid-cols-[120px_1fr_64px] items-center gap-3 text-[13px]"
        >
            <span class="truncate">{{ row.label }}</span>
            <span class="bg-surface-sunken h-2 overflow-hidden rounded">
                <span
                    class="block h-full rounded"
                    :style="{
                        width: `${(row.value / max) * 100}%`,
                        background: colors[i % colors.length],
                    }"
                />
            </span>
            <b class="text-end font-medium tabular-nums">{{
                row.display ?? row.value
            }}</b>
        </li>
    </ul>
</template>
