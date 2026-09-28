<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import HomePanel from '@/components/home/HomePanel.vue';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import type { HomeView } from '@/lib/home';
import { attentionItems } from '@/lib/home';

const props = defineProps<{ view: HomeView }>();
const { t } = useLocale();
const { money } = useFormat();
const items = computed(() => attentionItems(props.view));
const tones = {
    danger: 'bg-destructive/10 text-destructive',
    warning: 'bg-warning/12 text-warning',
    info: 'bg-info/10 text-info',
    brand: 'bg-primary/10 text-accent-text',
} as const;
</script>

<template>
    <HomePanel title="Needs attention" subtitle="Most urgent first">
        <p
            v-if="items.length === 0"
            class="text-muted-foreground py-10 text-center text-sm"
        >
            {{ t('Nothing needs your attention right now.') }}
        </p>
        <ul v-else class="divide-y">
            <li v-for="(item, i) in items" :key="i" class="py-3 first:pt-0">
                <component
                    :is="item.href ? Link : 'div'"
                    :href="item.href ?? undefined"
                    class="grid grid-cols-[auto_1fr_auto] items-start gap-3"
                >
                    <span
                        :class="[
                            'mt-0.5 rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-[0.1em] uppercase',
                            tones[item.tone],
                        ]"
                        >{{ t(item.tag) }}</span
                    >
                    <span>
                        <b class="block font-medium">{{
                            t(item.key, item.params)
                        }}</b>
                        <small
                            v-if="item.amount !== undefined"
                            class="text-muted-foreground text-xs"
                            >{{
                                money(item.amount, 'AED', { decimals: 0 })
                            }}</small
                        >
                        <small
                            v-else-if="item.count !== undefined"
                            class="text-muted-foreground text-xs"
                            >{{ item.count }}</small
                        >
                    </span>
                    <ChevronRight
                        v-if="item.href"
                        class="text-faint size-4 rtl:-scale-x-100"
                    />
                </component>
            </li>
        </ul>
    </HomePanel>
</template>
