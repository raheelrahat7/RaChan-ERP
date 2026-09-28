<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';

withDefaults(defineProps<{ label?: string }>(), {
    label: 'Loading',
});
</script>

<template>
    <div
        class="text-muted-foreground flex items-center gap-2 text-sm"
        role="status"
    >
        <Spinner class="size-4" />
        <span>{{ label }}</span>
    </div>
</template>
