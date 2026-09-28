<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Check,
    Languages,
    LogOut,
    Monitor,
    Moon,
    Settings,
    Sun,
} from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useLocale } from '@/composables/useLocale';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { Appearance, User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

const { t, locale } = useLocale();
const { appearance, updateAppearance } = useAppearance();

const appearanceOptions: {
    value: Appearance;
    label: string;
    icon: typeof Sun;
}[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

const handleLogout = () => {
    router.flushAll();
};

function setLocale(value: 'en' | 'ar'): void {
    router.post('/locale', { locale: value }, { preserveState: true });
}
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuLabel
        class="text-muted-foreground px-2 text-[10px] font-medium tracking-[0.16em] uppercase"
        >{{ t('Appearance') }}</DropdownMenuLabel
    >
    <DropdownMenuItem
        v-for="option in appearanceOptions"
        :key="option.value"
        @select="updateAppearance(option.value)"
    >
        <component :is="option.icon" class="me-2 h-4 w-4" />{{
            t(option.label)
        }}
        <Check v-if="appearance === option.value" class="ms-auto h-4 w-4" />
    </DropdownMenuItem>
    <DropdownMenuSeparator />
    <DropdownMenuLabel
        class="text-muted-foreground px-2 text-[10px] font-medium tracking-[0.16em] uppercase"
        >{{ t('Language') }}</DropdownMenuLabel
    >
    <DropdownMenuItem @select="setLocale('en')">
        <Languages class="me-2 h-4 w-4" />English
        <Check v-if="locale === 'en'" class="ms-auto h-4 w-4" />
    </DropdownMenuItem>
    <DropdownMenuItem @select="setLocale('ar')">
        <Languages class="me-2 h-4 w-4" /><span lang="ar">العربية</span>
        <Check v-if="locale === 'ar'" class="ms-auto h-4 w-4" />
    </DropdownMenuItem>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="me-2 h-4 w-4" />{{ t('Settings') }}</Link
            >
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="me-2 h-4 w-4" />{{ t('Log out') }}</Link
        >
    </DropdownMenuItem>
</template>
