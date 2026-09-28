<script setup lang="ts">
import { X } from '@lucide/vue';
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{ label: string; value?: string; removable?: boolean }>(),
    { value: undefined, removable: false },
);

const emit = defineEmits<{ remove: [] }>();
const { t } = useLocale();
</script>

<template>
    <span
        class="border-input inline-flex h-[30px] items-center gap-1.5 rounded-full border px-3 text-xs"
    >
        <span>
            {{ t(label)
            }}<template v-if="value"
                >:
                <b class="text-accent-text font-medium">{{
                    value
                }}</b></template
            >
        </span>
        <button
            v-if="removable"
            type="button"
            class="text-muted-foreground hover:text-foreground focus-visible:ring-ring -me-1 rounded-full p-0.5 outline-none focus-visible:ring-2"
            :aria-label="t('Remove filter')"
            @click="emit('remove')"
        >
            <X class="size-3" />
        </button>
    </span>
</template>
