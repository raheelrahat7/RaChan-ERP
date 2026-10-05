<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CrmSettingsHub from '@/components/CrmSettingsHub.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useLocale } from '@/composables/useLocale';

const { t } = useLocale();
</script>

<template>
    <Head :title="t('CRM settings')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="t('CRM settings')" :translate="false" />
        <CrmSettingsHub />
    </div>
</template>
