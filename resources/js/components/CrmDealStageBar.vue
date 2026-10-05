<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { readableOn } from '@/lib/crm-pipeline-board';
import { sortedStages } from '@/lib/crm-deals';
import type { DealStage } from '@/types/crm-deals';

const props = defineProps<{
    stages: DealStage[];
    currentStageId: number;
    canMove: boolean;
}>();
const emit = defineEmits<{ move: [stageId: number] }>();
const { t } = useLocale();
const shown = computed(() =>
    sortedStages(props.stages).filter(
        (stage) => stage.active || stage.id === props.currentStageId,
    ),
);
const currentIndex = computed(() =>
    shown.value.findIndex((stage) => stage.id === props.currentStageId),
);
function state(index: number): 'current' | 'past' | 'future' {
    return index === currentIndex.value
        ? 'current'
        : index < currentIndex.value
          ? 'past'
          : 'future';
}
</script>

<template>
    <nav :aria-label="t('Deal stage')">
        <ol class="flex gap-1 overflow-x-auto pb-1">
            <li
                v-for="(stage, index) in shown"
                :key="stage.id"
                class="min-w-[7.5rem] flex-1"
            >
                <button
                    type="button"
                    class="deal-step focus-visible:ring-ring flex h-10 w-full items-center justify-center truncate px-5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:cursor-default"
                    :class="{
                        'bg-muted text-muted-foreground hover:bg-muted/70':
                            state(index) === 'future',
                        'bg-primary/15 text-foreground hover:bg-primary/25':
                            state(index) === 'past',
                    }"
                    :style="
                        state(index) === 'current'
                            ? {
                                  backgroundColor: stage.color,
                                  color: readableOn(stage.color),
                              }
                            : undefined
                    "
                    :aria-current="
                        state(index) === 'current' ? 'step' : undefined
                    "
                    :disabled="!canMove || state(index) === 'current'"
                    :title="stage.name"
                    @click="emit('move', stage.id)"
                >
                    <span class="truncate">{{ stage.name }}</span>
                </button>
            </li>
        </ol>
    </nav>
</template>

<style scoped>
.deal-step {
    clip-path: polygon(
        0 0,
        calc(100% - 0.75rem) 0,
        100% 50%,
        calc(100% - 0.75rem) 100%,
        0 100%,
        0.75rem 50%
    );
}
[dir='rtl'] .deal-step {
    transform: scaleX(-1);
}
[dir='rtl'] .deal-step > span {
    transform: scaleX(-1);
}
</style>
