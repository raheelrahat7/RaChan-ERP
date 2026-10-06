<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type Row = {
    id: number;
    name: string;
    abilities: string;
    expires: string;
    status: string;
    actions: string;
};

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
const { t } = useLocale();
const open = ref(false);
const form = useForm({ name: '', days: 30, abilities: [] as string[] });
const rows = computed<Row[]>(() =>
    props.tokens.data.map((token) => ({
        id: token.id,
        name: token.name,
        abilities: token.abilities.join(', '),
        expires: token.expires_at,
        status: token.revoked_at ? t('Revoked') : t('Active'),
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'abilities', label: t('Read access') },
    { key: 'expires', label: t('Expires'), sortable: true },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);

function create(): void {
    form.post('/organization/api-tokens', {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
function revoke(id: number): void {
    if (confirm(t('Revoke this token? Anything using it stops working.'))) {
        router.delete(`/organization/api-tokens/${id}`);
    }
}
</script>

<template>
    <Head :title="t('Read-only API tokens')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Read-only API tokens"
            description="Personal access to organization data. Current permissions and job assignments are checked on every request."
        >
            <template #actions>
                <Link href="/organization" class="text-sm underline">{{
                    t('Organization settings')
                }}</Link>
            </template>
        </PageHeader>
        <Card v-if="props.secret" role="status">
            <CardContent class="space-y-2 text-sm">
                <p class="font-medium">
                    {{ t('Copy this token now. It will only be shown once.') }}
                </p>
                <code
                    class="bg-muted block rounded-md px-3 py-2 break-all select-all"
                    >{{ props.secret }}</code
                >
            </CardContent>
        </Card>

        <CrmSettingsTable
            :show-title="false"
            title="Read-only API tokens"
            add-label="Create token"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            :selectable="false"
            can-edit
            @add="open = true"
        >
            <template #cell-status="{ row }"
                ><Badge
                    :variant="
                        row.status === t('Active') ? 'secondary' : 'outline'
                    "
                    >{{ row.status }}</Badge
                ></template
            >
            <template #cell-actions="{ row }">
                <div class="flex justify-end">
                    <Button
                        v-if="row.status === t('Active')"
                        size="sm"
                        variant="outline"
                        @click="revoke(row.id)"
                        >{{ t('Revoke') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>
        <Pagination :links="props.tokens.links" />
        <p class="text-muted-foreground max-w-3xl text-xs">
            {{
                t(
                    'Send the token in the Authorization: Bearer header to GET /api/v1/jobs, GET /api/v1/properties, GET /api/v1/leads or GET /api/v1/invoices. Page size defaults to 25 and is limited to 100. API access cannot change workflows or finances.',
                )
            }}
        </p>

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Create token')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Personal access to organization data. Current permissions and job assignments are checked on every request.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="token-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="tk-name">{{ t('Name') }}</Label
                        ><Input
                            id="tk-name"
                            v-model="form.name"
                            required
                            maxlength="100"
                        /><InputError :message="form.errors.name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="tk-days">{{ t('Expires in days') }}</Label
                        ><Input
                            id="tk-days"
                            v-model.number="form.days"
                            type="number"
                            min="1"
                            max="90"
                            required
                        /><InputError :message="form.errors.days" />
                    </div>
                    <fieldset class="space-y-2 rounded-md border p-3">
                        <legend class="px-1 text-sm">
                            {{ t('Read access') }}
                        </legend>
                        <label
                            v-for="ability in props.abilities"
                            :key="ability"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.abilities"
                                type="checkbox"
                                :value="ability"
                            />{{ ability }}</label
                        >
                        <InputError :message="form.errors.abilities" />
                    </fieldset>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="token-form"
                        :disabled="
                            form.processing || form.abilities.length === 0
                        "
                        >{{ t('Create token') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>
    </div>
</template>
