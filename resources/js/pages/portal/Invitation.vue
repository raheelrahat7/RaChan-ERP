<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t, status } = useLocale();

import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
const props = defineProps<{
    token: string;
    role: string;
    organizationName: string;
}>();
const form = useForm({});
function accept(): void {
    form.post(`/portal/invitations/${props.token}/accept`);
}
</script>
<template>
    <Head :title="t('Portal invitation')" /><PageHeader
        :translate="false"
        :title="t('Accept portal access')"
        :description="
            t(':organization invited you as a :role.', {
                organization: organizationName,
                role: status(role),
            })
        "
    />
    <form @submit.prevent="accept">
        <Button :disabled="form.processing">{{
            t('Accept invitation')
        }}</Button>
    </form>
</template>
