<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { funnelSlices, groupStages } from '@/lib/crm-pipeline-editor';
import type { Stage } from '@/types/crm-pipeline';

const props = defineProps<{ stages: Stage[] }>();
const { t } = useLocale();
const groups = computed(() => groupStages(props.stages));
const running = computed(() => [
    ...(groups.value.initial ? [groups.value.initial] : []),
    ...groups.value.additional,
]);
const finals = computed(() => [...groups.value.won, ...groups.value.lost]);
const funnelMain = computed(() => funnelSlices(running.value));
const funnelLost = computed(() => funnelSlices(groups.value.lost));
</script>

<template>
    <div
        class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
        aria-hidden="true"
    >
        <div>
            <p class="text-eyebrow mb-2">{{ t('Scale preview') }}</p>
            <div class="flex items-end gap-4">
                <div class="flex min-w-0 flex-1 gap-px">
                    <div
                        v-for="(stage, index) in running"
                        :key="stage.id"
                        class="min-w-0 flex-1"
                    >
                        <div
                            class="h-3"
                            :style="{ backgroundColor: stage.color }"
                        />
                        <p
                            class="text-muted-foreground mt-1 text-center text-[10px]"
                        >
                            {{ index + 1 }}
                        </p>
                    </div>
                </div>
                <div class="flex w-24 shrink-0 gap-px">
                    <div
                        v-for="stage in finals"
                        :key="stage.id"
                        class="min-w-0 flex-1"
                    >
                        <div
                            class="h-3"
                            :style="{ backgroundColor: stage.color }"
                        />
                    </div>
                </div>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <figure>
                <figcaption class="text-eyebrow mb-1 text-center">
                    {{ t('Success stages') }}
                </figcaption>
                <svg viewBox="0 0 100 60" class="w-full">
                    <polygon
                        v-for="slice in funnelMain"
                        :key="slice.id"
                        :points="slice.points"
                        :fill="slice.color"
                        stroke="var(--background)"
                        stroke-width="0.4"
                    />
                </svg>
            </figure>
            <figure>
                <figcaption class="text-eyebrow mb-1 text-center">
                    {{ t('Failed stages') }}
                </figcaption>
                <svg viewBox="0 0 100 60" class="w-full">
                    <polygon
                        v-for="slice in funnelLost"
                        :key="slice.id"
                        :points="slice.points"
                        :fill="slice.color"
                        stroke="var(--background)"
                        stroke-width="0.4"
                    />
                </svg>
            </figure>
        </div>
    </div>
</template>
