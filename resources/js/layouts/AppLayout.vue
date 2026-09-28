<script setup lang="ts">
import { ConfigProvider } from 'reka-ui';
import { useLocale } from '@/composables/useLocale';
const { locale } = useLocale();
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <ConfigProvider :dir="locale === 'ar' ? 'rtl' : 'ltr'">
        <AppLayout :breadcrumbs="breadcrumbs">
            <slot />
        </AppLayout>
    </ConfigProvider>
</template>
