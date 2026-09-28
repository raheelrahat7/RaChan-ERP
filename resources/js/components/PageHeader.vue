<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{
        title: string;
        eyebrow?: string;
        description?: string;
        translate?: boolean;
    }>(),
    { eyebrow: undefined, description: undefined, translate: true },
);

const { t } = useLocale();
</script>

<template>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p v-if="eyebrow" class="text-eyebrow">
                {{ translate ? t(eyebrow) : eyebrow }}
            </p>
            <h1
                class="font-display mt-2 text-4xl leading-[1.05] font-medium tracking-[-0.01em] md:text-[44px]"
            >
                {{ translate ? t(title) : title }}
            </h1>
            <p
                v-if="description"
                class="text-muted-foreground mt-2 max-w-2xl text-sm"
            >
                {{ translate ? t(description) : description }}
            </p>
            <slot name="meta" />
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
