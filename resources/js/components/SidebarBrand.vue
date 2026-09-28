<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

const page = usePage();
const organization = computed(
    () => page.props.organization?.name ?? page.props.name,
);
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <SidebarMenuButton
                size="lg"
                as-child
                class="hover:bg-transparent active:bg-transparent"
            >
                <Link href="/dashboard">
                    <span
                        class="border-champagne/45 text-champagne font-display grid size-9 shrink-0 place-items-center rounded-md border bg-white/5 text-lg font-semibold"
                        >Z1</span
                    >
                    <span class="grid min-w-0 leading-tight">
                        <span class="truncate text-sm font-semibold"
                            >Z1 ERP</span
                        >
                        <span class="text-sidebar-muted truncate text-[11px]">{{
                            organization
                        }}</span>
                    </span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
