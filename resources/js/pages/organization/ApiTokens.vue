<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
const props = defineProps<{
    abilities: string[];
    secret: string | null;
    tokens: {
        data: {
            id: number;
            name: string;
            abilities: string[];
            expires_at: string;
            revoked_at: string | null;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
}>();
const form = useForm({ name: '', days: 30, abilities: [] as string[] });
function create(): void {
    form.post('/organization/api-tokens', { onSuccess: () => form.reset() });
}
function revoke(id: number): void {
    router.delete(`/organization/api-tokens/${id}`);
}
</script>
<template>
    <Head title="Read-only API tokens" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <Heading
            title="Read-only API tokens"
            description="Personal access to organization data. Current permissions and job assignments are checked on every request."
        />
        <Link href="/organization" class="underline">{{
            t('Organization settings')
        }}</Link>
        <div v-if="props.secret" role="status" class="rounded border p-4">
            <p>Copy this token now. It will only be shown once.</p>
            <code class="block break-all select-all">{{ props.secret }}</code>
        </div>
        <form class="space-y-4 rounded border p-4" @submit.prevent="create">
            <label class="block"
                >{{ t('Name')
                }}<input
                    v-model="form.name"
                    required
                    maxlength="100"
                    class="ml-3 rounded border p-2"
            /></label>
            <label class="block"
                >{{ t('Expires in days')
                }}<input
                    v-model="form.days"
                    type="number"
                    min="1"
                    max="90"
                    required
                    class="ml-3 rounded border p-2"
            /></label>
            <fieldset>
                <legend>{{ t('Read access') }}</legend>
                <label
                    v-for="ability in props.abilities"
                    :key="ability"
                    class="mr-4 inline-flex gap-2"
                    ><input
                        v-model="form.abilities"
                        type="checkbox"
                        :value="ability"
                    />{{ ability }}</label
                >
            </fieldset>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <Button
                :disabled="form.processing || form.abilities.length === 0"
                >{{ t('Create token') }}</Button
            >
        </form>
        <p class="text-sm">
            Send the token in the Authorization: Bearer header to GET
            /api/v1/jobs GET /api/v1/properties, GET /api/v1/leads or GET
            /api/v1/invoices. Page size defaults to 25 and is limited to 100.
            API access cannot change workflows or finances.
        </p>
        <ul class="space-y-3">
            <li
                v-for="token in props.tokens.data"
                :key="token.id"
                class="rounded border p-4"
            >
                <strong>{{ token.name }}</strong>
                <p>
                    {{ token.abilities.join(', ') }} · Expires
                    {{ token.expires_at }}
                </p>
                <span v-if="token.revoked_at">{{ t('Revoked') }}</span
                ><Button v-else variant="outline" @click="revoke(token.id)">{{
                    t('Revoke')
                }}</Button>
            </li>
        </ul>
        <Pagination :links="props.tokens.links" />
    </div>
</template>
