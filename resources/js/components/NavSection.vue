<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useLocale } from '@/composables/useLocale';
import { NAV_ICONS } from '@/lib/nav-icons';
import type { NavBadge, NavGroup } from '@/lib/navigation';

defineProps<{
    group: NavGroup;
    active: string | null;
    badge: (key?: NavBadge) => number | null;
}>();

const { t } = useLocale();
</script>

<template>
    <SidebarGroup class="py-1">
        <SidebarGroupLabel
            class="text-sidebar-muted h-7 text-[10px] font-medium tracking-[0.18em] uppercase"
            >{{ t(group.label) }}</SidebarGroupLabel
        >
        <SidebarMenu>
            <template v-for="item in group.items" :key="item.label">
                <Collapsible
                    v-if="item.children"
                    as-child
                    :default-open="
                        item.children.some((child) => child.href === active)
                    "
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :tooltip="t(item.label)"
                                :is-active="
                                    item.children.some(
                                        (child) => child.href === active,
                                    )
                                "
                            >
                                <component :is="NAV_ICONS[item.icon]" />
                                <span>{{ t(item.label) }}</span>
                                <ChevronRight
                                    class="ms-auto transition-transform group-data-[state=open]/collapsible:rotate-90 rtl:-scale-x-100"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem
                                    v-for="child in item.children"
                                    :key="child.href"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="child.href === active"
                                    >
                                        <Link :href="child.href">{{
                                            t(child.label)
                                        }}</Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
                <SidebarMenuItem v-else-if="item.soon">
                    <SidebarMenuButton
                        :tooltip="`${t(item.label)} · ${t('Soon')}`"
                        class="cursor-default opacity-55 hover:bg-transparent active:bg-transparent"
                        aria-disabled="true"
                        tabindex="-1"
                    >
                        <component :is="NAV_ICONS[item.icon]" />
                        <span>{{ t(item.label) }}</span>
                        <span
                            class="text-sidebar-muted ms-auto text-[9px] tracking-[0.1em] uppercase group-data-[collapsible=icon]:hidden"
                            >{{ t('Soon') }}</span
                        >
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :is-active="item.href === active"
                        :tooltip="t(item.label)"
                    >
                        <Link :href="item.href ?? '/dashboard'">
                            <component :is="NAV_ICONS[item.icon]" />
                            <span>{{ t(item.label) }}</span>
                        </Link>
                    </SidebarMenuButton>
                    <SidebarMenuBadge
                        v-if="badge(item.badge)"
                        class="bg-champagne/20 text-sidebar-foreground rounded-full"
                        >{{ badge(item.badge) }}</SidebarMenuBadge
                    >
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
