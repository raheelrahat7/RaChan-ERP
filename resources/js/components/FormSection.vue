<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

defineProps<{ title: string; description?: string }>();

const { t } = useLocale();
</script>

<template>
    <section class="grid gap-6 border-b py-7 md:grid-cols-[240px_1fr] md:gap-8">
        <div>
            <h2 class="font-display text-[22px] leading-tight font-medium">
                {{ t(title) }}
            </h2>
            <p
                v-if="description"
                class="text-muted-foreground mt-1.5 text-[13px]"
            >
                {{ t(description) }}
            </p>
        </div>
        <div class="grid content-start gap-x-4 gap-y-5 sm:grid-cols-2">
            <slot />
        </div>
    </section>
</template>
