<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { locale } = useLocale();
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { Link } from '@inertiajs/vue3';
import {
    Building2,
    CalendarCheck,
    FileSignature,
    House,
    LayoutGrid,
    Search,
    Bell,
    UsersRound,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    { title: 'Search', href: '/search', icon: Search },
    { title: 'Notifications', href: '/notifications', icon: Bell },
    {
        title: 'Organization',
        href: '/organization',
        icon: Building2,
    },
    {
        title: 'CRM leads',
        href: '/crm/leads',
        icon: UsersRound,
    },
    {
        title: 'CRM contacts',
        href: '/crm/contacts',
        icon: UsersRound,
    },
    {
        title: 'Owners, tenants & brokers',
        href: '/real-estate/people',
        icon: UsersRound,
    },
    { title: 'Listings', href: '/real-estate/listings', icon: House },
    { title: 'Brokerage', href: '/real-estate/brokerage', icon: UsersRound },
    { title: 'Inventory', href: '/inventory', icon: House },
    { title: 'Reservations', href: '/reservations', icon: CalendarCheck },
    { title: 'Agreements', href: '/agreements', icon: FileSignature },
    { title: 'Handovers', href: '/handovers', icon: CalendarCheck },
    { title: 'Invoices', href: '/invoices', icon: FileSignature },
    { title: 'Vendor bills', href: '/vendor-bills', icon: FileSignature },
    { title: 'Accounting', href: '/accounting', icon: FileSignature },
    {
        title: 'Bank reconciliation',
        href: '/bank-reconciliation',
        icon: FileSignature,
    },
    { title: 'Procurement', href: '/procurement', icon: FileSignature },
    {
        title: 'Property performance',
        href: '/reports/property-profitability',
        icon: LayoutGrid,
    },
    {
        title: 'Owner statements',
        href: '/reports/owner-statements',
        icon: FileSignature,
    },
    { title: 'Operations overview', href: '/operations', icon: LayoutGrid },
    { title: 'Maintenance', href: '/maintenance', icon: House },
    {
        title: 'Preventive maintenance',
        href: '/preventive-maintenance',
        icon: CalendarCheck,
    },
    {
        title: 'Document compliance',
        href: '/compliance-documents',
        icon: FileSignature,
    },
];
</script>

<template>
    <Sidebar
        :side="locale === 'ar' ? 'right' : 'left'"
        collapsible="icon"
        variant="inset"
    >
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <div class="group-data-[collapsible=icon]:hidden">
                <LanguageSwitcher />
            </div>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
