<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

defineProps<{ title: string; subtitle?: string }>();
const { t } = useLocale();
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex items-baseline justify-between gap-3 px-0.5">
            <h2 class="font-display text-2xl font-medium">{{ t(title) }}</h2>
            <span v-if="subtitle" class="text-muted-foreground text-xs">{{
                t(subtitle)
            }}</span>
        </div>
        <div
            class="bg-border shadow-panel grid grid-cols-2 gap-px overflow-hidden rounded-xl border md:grid-cols-3 xl:grid-cols-6"
        >
            <slot />
        </div>
    </section>
</template>
