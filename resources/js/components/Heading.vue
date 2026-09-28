<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    translateText?: boolean;
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
    translateText: true,
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ translateText ? t(title) : title }}
        </h2>
        <p v-if="description" class="text-muted-foreground text-sm">
            {{ translateText ? t(description) : description }}
        </p>
    </header>
</template>
