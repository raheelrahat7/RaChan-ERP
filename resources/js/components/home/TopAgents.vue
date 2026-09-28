<script setup lang="ts">
import { computed } from 'vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { getInitials } from '@/composables/useInitials';
import { useLocale } from '@/composables/useLocale';
import type { TopAgent } from '@/types/home';

const props = defineProps<{ agents: TopAgent[] | null }>();
const { t } = useLocale();
const { money } = useFormat();
const top = computed(() =>
    Math.max(1, ...(props.agents ?? []).map((agent) => agent.commission)),
);
</script>

<template>
    <HomePanel title="Top agents" subtitle="Commission earned this period">
        <ComingSoon v-if="!agents" />
        <p
            v-else-if="agents.length === 0"
            class="text-muted-foreground py-10 text-center text-sm"
        >
            {{ t('No agents earned commission this period.') }}
        </p>
        <ol v-else class="flex flex-col gap-3.5">
            <li
                v-for="(agent, i) in agents"
                :key="agent.user_id"
                class="grid grid-cols-[22px_34px_1fr_auto] items-center gap-3"
            >
                <span
                    :class="[
                        'font-display text-center text-xl',
                        i === 0 ? 'text-champagne' : 'text-faint',
                    ]"
                    >{{ i + 1 }}</span
                >
                <span
                    class="bg-surface-sunken grid size-[34px] place-items-center rounded-full border text-[11px] font-semibold"
                    >{{ getInitials(agent.name) }}</span
                >
                <span class="min-w-0">
                    <b class="block truncate font-medium">{{ agent.name }}</b>
                    <span
                        class="bg-surface-sunken mt-1.5 block h-1 overflow-hidden rounded"
                    >
                        <span
                            class="from-primary to-champagne block h-full rounded bg-linear-to-r rtl:bg-linear-to-l"
                            :style="{
                                width: `${(agent.commission / top) * 100}%`,
                            }"
                        />
                    </span>
                </span>
                <span class="text-end">
                    <b class="font-medium tabular-nums">{{
                        money(agent.commission, 'AED', { decimals: 0 })
                    }}</b>
                    <small class="text-muted-foreground block text-[11px]">{{
                        agent.team ?? t(':count deals', { count: agent.deals })
                    }}</small>
                </span>
            </li>
        </ol>
    </HomePanel>
</template>
