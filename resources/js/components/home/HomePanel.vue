<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{ title: string; subtitle?: string; brand?: boolean }>(),
    { subtitle: undefined, brand: false },
);
const { t } = useLocale();
</script>

<template>
    <section
        :class="[
            'relative min-w-0 overflow-hidden rounded-xl border p-6',
            brand
                ? 'bg-brand-deep shadow-overlay border-transparent'
                : 'bg-card shadow-panel',
        ]"
    >
        <header class="mb-4 flex items-start justify-between gap-3">
            <div>
                <slot name="eyebrow" />
                <h2 class="font-display text-[23px] leading-tight font-medium">
                    {{ t(title) }}
                </h2>
                <p
                    v-if="subtitle"
                    :class="[
                        'mt-0.5 text-xs',
                        brand ? 'text-[#fbf3ef]/65' : 'text-muted-foreground',
                    ]"
                >
                    {{ t(subtitle) }}
                </p>
            </div>
            <slot name="action" />
        </header>
        <slot />
    </section>
</template>
