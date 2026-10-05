<script setup lang="ts">
import { computed, ref } from 'vue';
import CrmLeadStageEditor from '@/components/CrmLeadStageEditor.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocale } from '@/composables/useLocale';
import type { Pipeline, TransitionOption } from '@/types/crm-pipeline';

const props = defineProps<{
    leadId: number;
    pipeline: Pipeline;
    currentStageId: number;
    transitionOptions: TransitionOption[];
    canMove: boolean;
}>();

const { t } = useLocale();
const target = ref<number | null>(null);
const open = computed({
    get: () => target.value !== null,
    set: (value: boolean) => {
        if (!value) {
            target.value = null;
        }
    },
});
const stages = computed(() =>
    props.pipeline.stages
        .filter((stage) => stage.active || stage.id === props.currentStageId)
        .sort((a, b) => a.position - b.position),
);
const currentIndex = computed(() =>
    stages.value.findIndex((stage) => stage.id === props.currentStageId),
);
const targetStage = computed(() =>
    props.pipeline.stages.find((stage) => stage.id === target.value),
);

function state(index: number): 'current' | 'past' | 'future' {
    if (index === currentIndex.value) {
        return 'current';
    }

    return index < currentIndex.value ? 'past' : 'future';
}
</script>

<template>
    <nav :aria-label="t('Lead stage')">
        <ol class="flex gap-1 overflow-x-auto pb-1">
            <li
                v-for="(stage, index) in stages"
                :key="stage.id"
                class="min-w-[7.5rem] flex-1"
            >
                <button
                    type="button"
                    class="stage-step focus-visible:ring-ring relative flex h-10 w-full items-center justify-center truncate px-5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:cursor-default"
                    :class="{
                        'bg-primary text-primary-foreground':
                            state(index) === 'current',
                        'bg-primary/15 text-foreground hover:bg-primary/25':
                            state(index) === 'past',
                        'bg-muted text-muted-foreground hover:bg-muted/70':
                            state(index) === 'future',
                    }"
                    :aria-current="
                        state(index) === 'current' ? 'step' : undefined
                    "
                    :disabled="!canMove || state(index) === 'current'"
                    :title="stage.name"
                    @click="target = stage.id"
                >
                    <span
                        class="absolute inset-x-0 bottom-0 h-0.5"
                        :style="{ backgroundColor: stage.color }"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{ stage.name }}</span>
                </button>
            </li>
        </ol>
        <Dialog v-model:open="open">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ t('Move lead') }}</DialogTitle>
                    <DialogDescription>
                        {{ targetStage?.name }}
                    </DialogDescription>
                </DialogHeader>
                <CrmLeadStageEditor
                    v-if="target !== null"
                    :lead-id="leadId"
                    :current-stage-id="currentStageId"
                    :pipeline="pipeline"
                    :initial-stage-id="target"
                    :transition-options="transitionOptions"
                    id-prefix="detail"
                    @moved="target = null"
                />
            </DialogContent>
        </Dialog>
    </nav>
</template>

<style scoped>
.stage-step {
    clip-path: polygon(
        0 0,
        calc(100% - 0.75rem) 0,
        100% 50%,
        calc(100% - 0.75rem) 100%,
        0 100%,
        0.75rem 50%
    );
}
[dir='rtl'] .stage-step {
    transform: scaleX(-1);
}
[dir='rtl'] .stage-step > span {
    transform: scaleX(-1);
}
</style>
