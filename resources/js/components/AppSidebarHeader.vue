<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, Search } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useLocale } from '@/composables/useLocale';
import type { BreadcrumbItem } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const { t } = useLocale();
const { toggle } = useCommandPalette();
const unread = computed(() => page.props.counts?.notifications_unread ?? 0);
</script>

<template>
    <header
        class="border-sidebar-border/70 bg-background/85 sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b px-4 backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-6"
    >
        <SidebarTrigger class="-ms-1" />
        <Breadcrumbs v-if="breadcrumbs.length > 0" :breadcrumbs="breadcrumbs" />
        <div class="ms-auto flex items-center gap-2">
            <button
                type="button"
                class="bg-surface-sunken text-muted-foreground hover:text-foreground focus-visible:ring-ring hidden h-9 w-72 items-center gap-2 rounded-md border px-3 text-sm outline-none focus-visible:ring-2 md:flex"
                @click="toggle"
            >
                <Search class="size-4" />
                {{ t('Search or jump to…') }}
                <kbd class="bg-card ms-auto rounded border px-1.5 text-[10.5px]"
                    >⌘K</kbd
                >
            </button>
            <Button
                variant="outline"
                size="icon"
                class="md:hidden"
                :aria-label="t('Search')"
                @click="toggle"
            >
                <Search />
            </Button>
            <Button variant="outline" size="icon" as-child>
                <Link
                    href="/notifications"
                    class="relative"
                    :aria-label="t('Notifications')"
                >
                    <Bell />
                    <span
                        v-if="unread > 0"
                        class="bg-champagne ring-background absolute -end-1 -top-1 grid min-w-4 place-items-center rounded-full px-1 text-[10px] leading-4 font-semibold text-[#3a1520] ring-2"
                        >{{ unread > 99 ? '99+' : unread }}</span
                    >
                </Link>
            </Button>
        </div>
        <CommandPalette />
    </header>
</template>
