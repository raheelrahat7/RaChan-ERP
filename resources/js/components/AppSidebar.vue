<script setup lang="ts">
import NavSection from '@/components/NavSection.vue';
import NavUser from '@/components/NavUser.vue';
import SidebarBrand from '@/components/SidebarBrand.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useLocale } from '@/composables/useLocale';
import { useNavigation } from '@/composables/useNavigation';

const { locale } = useLocale();
const { groups, active, badge } = useNavigation();
</script>

<template>
    <Sidebar
        :side="locale === 'ar' ? 'right' : 'left'"
        collapsible="icon"
        variant="inset"
    >
        <SidebarHeader class="pt-3"><SidebarBrand /></SidebarHeader>
        <SidebarContent class="gap-0 pb-3">
            <NavSection
                v-for="group in groups"
                :key="group.id"
                :group="group"
                :active="active"
                :badge="badge"
            />
        </SidebarContent>
        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
