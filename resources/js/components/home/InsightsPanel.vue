<script setup lang="ts">
import {
    MessageCircle,
    Scale,
    Sparkles,
    Target,
    TrendingUp,
    TriangleAlert,
} from '@lucide/vue';
import type { Component } from 'vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useLocale } from '@/composables/useLocale';
import type { Insight } from '@/types/home';

defineProps<{ insights: Insight[] | null }>();
const { t } = useLocale();
const icons: Record<string, Component> = {
    'trending-up': TrendingUp,
    'alert-triangle': TriangleAlert,
    target: Target,
    scale: Scale,
};
</script>

<template>
    <HomePanel title="This week's insights" brand>
        <template #eyebrow>
            <span
                class="mb-1 inline-flex items-center gap-1.5 text-[10px] tracking-[0.18em] text-[#e6c9a2] uppercase"
            >
                <Sparkles class="size-3" />Z1 Intelligence
            </span>
        </template>
        <ComingSoon
            v-if="!insights || insights.length === 0"
            inverse
            reason="Z1 Intelligence will surface trends and risks from your data here."
        />
        <ul v-else class="relative flex flex-col">
            <li
                v-for="(insight, i) in insights"
                :key="i"
                class="grid grid-cols-[26px_1fr] gap-2.5 border-t border-white/12 py-3 first:border-t-0 first:pt-0"
            >
                <component
                    :is="icons[insight.icon] ?? Sparkles"
                    class="mt-0.5 size-4 text-[#e6c9a2]"
                />
                <p class="text-[13px] text-[#fbf3ef]/85">{{ insight.text }}</p>
            </li>
        </ul>
        <div
            class="relative mt-3 flex h-10 items-center gap-2.5 rounded-md border border-white/18 bg-white/8 px-3 text-sm text-[#fbf3ef]/55"
            aria-disabled="true"
        >
            <MessageCircle class="size-4 text-[#e6c9a2]" />{{
                t('Ask about your portfolio…')
            }}
            <span class="ms-auto text-[10px] tracking-[0.1em] uppercase">{{
                t('Soon')
            }}</span>
        </div>
    </HomePanel>
</template>
