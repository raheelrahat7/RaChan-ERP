<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CrmPermissionsMatrix from '@/components/CrmPermissionsMatrix.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useLocale } from '@/composables/useLocale';
import type {
    PermissionGroup,
    PermissionRole,
    PermissionValues,
} from '@/lib/crm-permissions';

defineProps<{
    groups: PermissionGroup[];
    roles: PermissionRole[];
    values: PermissionValues;
    canEdit?: boolean;
}>();
const { t } = useLocale();
</script>

<template>
    <Head :title="t('Access permissions')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="t('Access permissions')" :translate="false" />
        <CrmPermissionsMatrix
            :groups="groups"
            :roles="roles"
            :values="values"
            :can-edit="canEdit"
        />
    </div>
</template>
