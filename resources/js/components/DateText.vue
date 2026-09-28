<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';

const props = withDefaults(
    defineProps<{
        value: string | null | undefined;
        withTime?: boolean;
    }>(),
    { withTime: false },
);

const { date } = useFormat();
const text = computed(() => date(props.value, { withTime: props.withTime }));
</script>

<template>
    <time :datetime="value ?? undefined" class="whitespace-nowrap tabular-nums">
        {{ text }}
    </time>
</template>
