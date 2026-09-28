<script setup lang="ts">
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
function logout(): void {
    router.post('/logout');
}
</script>
<template>
    <div class="bg-background text-foreground min-h-screen">
        <a
            href="#portal-content"
            class="bg-background focus:ring-ring sr-only p-3 focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:ring-2"
            >{{ t('Skip to content') }}</a
        >
        <header class="border-b">
            <nav
                aria-label="Customer portal"
                class="mx-auto flex max-w-6xl items-center justify-between gap-4 p-4"
            >
                <Link href="/portal" class="font-semibold">{{
                    t('Customer portal')
                }}</Link
                ><LanguageSwitcher /><Button
                    variant="outline"
                    @click="logout"
                    >{{ t('Sign out') }}</Button
                >
            </nav>
        </header>
        <main
            id="portal-content"
            class="mx-auto max-w-6xl space-y-6 p-4 md:p-6"
        >
            <slot />
        </main>
    </div>
</template>
