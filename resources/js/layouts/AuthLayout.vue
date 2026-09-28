<script setup lang="ts">
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <div class="flex justify-end"><LanguageSwitcher /></div>
        <slot />
    </AuthLayout>
</template>
