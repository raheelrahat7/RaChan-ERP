<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t, status } = useLocale();
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
defineProps<{
    grants: { id: number; role: string; organization_name: string }[];
}>();
</script>
<template>
    <Head :title="t('Customer portal')" /><PageHeader
        :translate="false"
        :title="t('Your portal access')"
        :description="t('Select an organization and role.')"
    />
    <p v-if="!grants.length">
        {{
            t(
                'No active access. Ask the property owner or administrator for an invitation.',
            )
        }}
    </p>
    <ul class="space-y-3">
        <li v-for="grant in grants" :key="grant.id">
            <Link
                :href="`/portal/${grant.id}`"
                class="block rounded-md border p-4 underline"
                >{{ grant.organization_name }} · {{ status(grant.role) }}</Link
            >
        </li>
    </ul>
</template>
