<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import {
    STATUS_TONE_CLASSES,
    STRUCK_TEXT_CLASS,
    displayStatusLabel,
    isStruck,
    statusTone,
} from '@/lib/status-tones';
import type { StatusTone } from '@/lib/status-tones';
import { cn } from '@/lib/utils';

const props = defineProps<{
    status: string | null | undefined;
    label?: string;
    tone?: StatusTone;
}>();

const { t } = useLocale();
const resolvedTone = computed(() => props.tone ?? statusTone(props.status));
const text = computed(() => props.label ?? t(displayStatusLabel(props.status)));
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-2 text-xs whitespace-nowrap',
                isStruck(status) && STRUCK_TEXT_CLASS,
            )
        "
    >
        <span
            aria-hidden="true"
            :class="
                cn(
                    'size-1.5 shrink-0 rounded-full',
                    STATUS_TONE_CLASSES[resolvedTone],
                )
            "
        />
        {{ text }}
    </span>
</template>
