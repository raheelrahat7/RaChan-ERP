<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { statFigure } from '@/lib/format';
import type { Figure } from '@/lib/home';

const props = withDefaults(
    defineProps<{
        label: string;
        icon: Component;
        figure: Figure;
        kind?: 'money' | 'count';
        note?: string;
        noteParams?: Record<string, string | number>;
        invertTone?: boolean;
    }>(),
    {
        kind: 'money',
        note: undefined,
        noteParams: () => ({}),
        invertTone: false,
    },
);

const { t, locale } = useLocale();
const parts = computed(() =>
    statFigure(
        {
            value: props.figure.value,
            currency: props.kind === 'money' ? 'AED' : undefined,
            compact: false,
            decimals: 0,
        },
        locale.value,
    ),
);
const trend = computed(() => {
    const change = props.figure.change;
    if (change === null) {
        return null;
    }
    const good = props.invertTone ? change < 0 : change > 0;

    return {
        text: `${change > 0 ? '▲' : change < 0 ? '▼' : '■'} ${Math.abs(change)}%`,
        tone:
            change === 0
                ? 'text-muted-foreground'
                : good
                  ? 'text-success'
                  : 'text-destructive',
    };
});
</script>

<template>
    <div class="bg-card flex flex-col px-5 pt-4 pb-4">
        <span class="text-label flex items-center gap-1.5">
            <component :is="icon" class="text-faint size-3.5" />{{ t(label) }}
        </span>
        <span
            class="font-display mt-3 mb-2 text-[32px] leading-none font-medium whitespace-nowrap lining-nums tabular-nums"
        >
            <span
                v-if="parts.prefix"
                class="text-muted-foreground me-1.5 align-[0.8em] font-sans text-[10.5px] tracking-[0.12em]"
                >{{ parts.prefix }}</span
            >{{ parts.figure
            }}<span
                v-if="parts.suffix"
                class="text-muted-foreground ms-2 text-[0.45em]"
                >{{ parts.suffix }}</span
            >
        </span>
        <span class="text-xs">
            <span v-if="figure.soon" class="text-faint">{{
                t('Coming soon')
            }}</span>
            <template v-else>
                <span v-if="trend" :class="trend.tone">{{ trend.text }}</span>
                <span v-if="note" class="text-muted-foreground"
                    >{{ trend ? ' · ' : '' }}{{ t(note, noteParams) }}</span
                >
            </template>
        </span>
    </div>
</template>
