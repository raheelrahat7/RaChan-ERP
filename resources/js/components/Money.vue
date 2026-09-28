<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import type { NumericInput } from '@/lib/format';

const props = withDefaults(
    defineProps<{
        value: NumericInput;
        currency?: string;
        compact?: boolean;
        decimals?: number;
    }>(),
    { currency: 'AED', compact: false, decimals: 2 },
);

const { money } = useFormat();
const text = computed(() =>
    money(props.value, props.currency, {
        compact: props.compact,
        decimals: props.decimals,
    }),
);
</script>

<template>
    <span class="whitespace-nowrap lining-nums tabular-nums">{{ text }}</span>
</template>
