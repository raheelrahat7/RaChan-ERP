<script setup lang="ts">
import { Clock } from '@lucide/vue';
import { useLocale } from '@/composables/useLocale';

withDefaults(defineProps<{ reason?: string; inverse?: boolean }>(), {
    reason: 'This section fills in as soon as its data is connected.',
    inverse: false,
});
const { t } = useLocale();
</script>

<template>
    <div
        :class="[
            'flex min-h-32 flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-6 text-center text-sm',
            inverse
                ? 'border-white/20 text-[#fbf3ef]/70'
                : 'text-muted-foreground',
        ]"
    >
        <Clock class="size-4 opacity-70" />
        <span class="font-medium">{{ t('Coming soon') }}</span>
        <span class="max-w-xs text-xs">{{ t(reason) }}</span>
    </div>
</template>
