<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{
        title: string;
        eyebrow?: string;
        status?: string | null;
        facts?: { label: string; value: string }[];
    }>(),
    { eyebrow: undefined, status: null, facts: () => [] },
);

const { t } = useLocale();
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p v-if="eyebrow" class="text-eyebrow">{{ t(eyebrow) }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-4">
                    <h1
                        class="font-display text-4xl leading-[1.05] font-medium tracking-[-0.01em] md:text-[44px]"
                    >
                        {{ title }}
                    </h1>
                    <StatusDot
                        v-if="status"
                        :status="status"
                        class="border-input rounded-full border px-3 py-1"
                    />
                </div>
            </div>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </header>
        <dl
            v-if="facts.length"
            class="bg-border grid grid-cols-2 gap-px border-y md:grid-cols-3 xl:grid-cols-6"
        >
            <div
                v-for="fact in facts"
                :key="fact.label"
                class="bg-background py-4 pe-4"
            >
                <dt class="text-label">{{ t(fact.label) }}</dt>
                <dd class="mt-1.5 font-medium tabular-nums">
                    {{ fact.value }}
                </dd>
            </div>
        </dl>
        <slot />
    </div>
</template>
