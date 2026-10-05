<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    Boxes,
    Building2,
    Calculator,
    Coins,
    CreditCard,
    FileText,
    Handshake,
    ListChecks,
    Mail,
    MapPinned,
    Plug,
    Ruler,
    Settings2,
    ShieldCheck,
    Store,
    Users,
    Workflow,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useLocale } from '@/composables/useLocale';
import { SETTINGS_CATEGORIES, findCategory } from '@/lib/crm-settings';

const { t } = useLocale();
const active = ref(SETTINGS_CATEGORIES[0].key);
const category = computed(() => findCategory(active.value));
const icons: Record<string, Component> = {
    book: BookOpen,
    list: ListChecks,
    users: Users,
    building: Building2,
    coins: Coins,
    map: MapPinned,
    calculator: Calculator,
    ruler: Ruler,
    handshake: Handshake,
    file: FileText,
    card: CreditCard,
    shield: ShieldCheck,
    workflow: Workflow,
    mail: Mail,
    plug: Plug,
    store: Store,
    settings: Settings2,
};
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <nav :aria-label="t('Settings sections')">
            <ul class="space-y-1">
                <li v-for="item in SETTINGS_CATEGORIES" :key="item.key">
                    <button
                        type="button"
                        class="hover:bg-muted w-full rounded-md px-4 py-3 text-start text-sm font-medium"
                        :class="
                            item.key === active
                                ? 'bg-primary/10 text-primary'
                                : 'bg-muted/50'
                        "
                        :aria-current="item.key === active ? 'true' : undefined"
                        @click="active = item.key"
                    >
                        {{ t(item.label) }}
                    </button>
                </li>
            </ul>
        </nav>
        <section :aria-label="t(category.label)">
            <h2 class="text-eyebrow mb-4">{{ t(category.label) }}</h2>
            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                <li v-for="tile in category.tiles" :key="tile.key">
                    <component
                        :is="tile.href ? Link : 'div'"
                        v-bind="
                            tile.href
                                ? { href: tile.href }
                                : { 'aria-disabled': 'true' }
                        "
                        class="bg-card flex h-full flex-col items-center gap-3 rounded-md border p-4 text-center text-sm font-medium"
                        :class="
                            tile.href
                                ? 'hover:border-primary hover:shadow-sm'
                                : 'opacity-60'
                        "
                    >
                        <span
                            class="bg-muted flex size-14 items-center justify-center rounded-lg"
                            ><component
                                :is="icons[tile.icon] ?? Boxes"
                                class="size-7"
                                aria-hidden="true"
                        /></span>
                        <span>{{ t(tile.label) }}</span>
                        <Badge v-if="!tile.href" variant="outline">{{
                            t('Not connected yet')
                        }}</Badge>
                    </component>
                </li>
            </ul>
        </section>
    </div>
</template>
