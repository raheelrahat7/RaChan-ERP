<script setup lang="ts">
import { computed } from 'vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import { donutSegments } from '@/lib/chart-paths';
import type { CommissionSplit } from '@/types/home';

const props = defineProps<{ split: CommissionSplit | null }>();
const { t } = useLocale();
const { money, compact } = useFormat();
const R = 66;
const C = 2 * Math.PI * R;
const parts = computed(() => {
    if (!props.split) {
        return [];
    }
    const s = props.split;

    return [
        { label: 'Net company', value: s.net_company, color: 'var(--chart-1)' },
        {
            label: 'Agent payable',
            value: s.agent_payable,
            color: 'var(--chart-2)',
        },
        { label: 'Co-broker', value: s.co_broker, color: 'var(--chart-3)' },
        { label: 'Referral', value: s.referral, color: 'var(--chart-4)' },
    ];
});
const total = computed(() => parts.value.reduce((sum, p) => sum + p.value, 0));
const segments = computed(() =>
    donutSegments(
        parts.value.map((p) => p.value),
        C,
        3,
    ),
);
</script>

<template>
    <HomePanel title="Commission" subtitle="Distribution this period">
        <ComingSoon v-if="!split" />
        <p
            v-else-if="total === 0"
            class="text-muted-foreground py-10 text-center text-sm"
        >
            {{ t('No commission earned this period.') }}
        </p>
        <div v-else class="grid grid-cols-[170px_1fr] items-center gap-5">
            <svg viewBox="0 0 170 170" class="size-[170px]" aria-hidden="true">
                <circle
                    v-for="(segment, i) in segments"
                    :key="i"
                    :r="R"
                    cx="85"
                    cy="85"
                    fill="none"
                    :stroke="parts[i].color"
                    stroke-width="16"
                    :stroke-dasharray="`${segment.length} ${C}`"
                    :stroke-dashoffset="segment.offset"
                    transform="rotate(-90 85 85)"
                />
                <text
                    x="85"
                    y="80"
                    text-anchor="middle"
                    font-size="10"
                    letter-spacing="1.5"
                    fill="var(--muted-foreground)"
                >
                    {{ t('TOTAL') }}
                </text>
                <text
                    x="85"
                    y="104"
                    text-anchor="middle"
                    class="font-display"
                    font-size="26"
                    fill="var(--foreground)"
                >
                    {{ compact(total) }}
                </text>
            </svg>
            <ul class="flex flex-col gap-3 text-[13px]">
                <li
                    v-for="part in parts"
                    :key="part.label"
                    class="grid grid-cols-[8px_1fr_auto] items-center gap-x-2.5"
                >
                    <i
                        class="size-2 rounded-full"
                        :style="{ background: part.color }"
                    />
                    <span>{{ t(part.label) }}</span>
                    <b class="font-medium tabular-nums"
                        >{{ Math.round((part.value / total) * 100) }}%</b
                    >
                    <small
                        class="text-muted-foreground col-span-2 col-start-2 text-[11px]"
                        >{{ money(part.value, 'AED', { decimals: 0 }) }}</small
                    >
                </li>
            </ul>
        </div>
    </HomePanel>
</template>
