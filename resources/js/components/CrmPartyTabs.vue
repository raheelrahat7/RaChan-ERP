<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useLocale } from '@/composables/useLocale';

defineProps<{ active: 'contacts' | 'companies' }>();
const { t } = useLocale();
const items = [
    { key: 'contacts', label: 'Contacts', href: '/crm/contacts' },
    { key: 'companies', label: 'Companies', href: '/companies' },
] as const;
</script>

<template>
    <nav :aria-label="t('People')" class="flex gap-2">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="
                item.key === active
                    ? 'bg-primary text-primary-foreground'
                    : 'hover:bg-muted border'
            "
            :aria-current="item.key === active ? 'page' : undefined"
            >{{ t(item.label) }}</Link
        >
    </nav>
</template>
