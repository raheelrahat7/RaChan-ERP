<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
const props = defineProps<{
    batches: {
        data: {
            id: number;
            kind: string;
            rows: Record<string, string>[];
            errors: { row: number; messages: string[] }[];
            expires_at: string;
            committed_at: string | null;
            created_ids: number[] | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
const form = useForm<{ kind: string; file: File | null }>({
    kind: 'properties',
    file: null,
});
function fileChanged(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function preview(): void {
    form.post('/inventory/imports', {
        forceFormData: true,
        onSuccess: () => form.reset('file'),
    });
}
function commit(id: number): void {
    router.post(`/inventory/imports/${id}/commit`);
}
</script>
<template>
    <Head :title="t('Inventory imports')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Inventory imports"
            description="Preview and validate a CSV before creating inventory records."
        >
            <template #actions>
                <Link href="/inventory" class="text-sm underline">{{
                    t('Inventory')
                }}</Link>
            </template>
        </PageHeader>

        <Card>
            <CardHeader
                ><CardTitle class="text-eyebrow">{{
                    t('Upload a CSV')
                }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-4">
                <p class="text-muted-foreground text-sm">
                    {{
                        t(
                            'UTF-8 CSV, up to 1 MB and 500 data rows. Headers must match exactly. Valid previews expire after 24 hours. Commit rechecks permissions and every row, then creates the whole batch once. Units can start as available or unavailable.',
                        )
                    }}
                </p>
                <form
                    class="grid gap-4 md:grid-cols-[14rem_1fr_auto] md:items-end"
                    @submit.prevent="preview"
                >
                    <div class="space-y-1">
                        <Label for="imp-kind">{{ t('Record type') }}</Label>
                        <select
                            id="imp-kind"
                            v-model="form.kind"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option value="properties">
                                {{ t('Properties') }}
                            </option>
                            <option value="units">{{ t('Units') }}</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="imp-file">{{ t('CSV file') }}</Label>
                        <input
                            id="imp-file"
                            type="file"
                            accept=".csv,text/csv"
                            class="block w-full text-sm"
                            @change="fileChanged"
                        />
                    </div>
                    <Button :disabled="form.processing || !form.file">{{
                        t('Validate preview')
                    }}</Button>
                    <div class="md:col-span-3">
                        <p class="text-muted-foreground mb-1 text-xs">
                            {{ t('Required header:') }}
                        </p>
                        <code
                            class="bg-muted block rounded-md px-3 py-2 text-xs break-all"
                            >{{
                                form.kind === 'properties'
                                    ? 'name,type,city,address_line_1'
                                    : 'property_id,number,type,status,area,asking_price'
                            }}</code
                        >
                    </div>
                </form>
                <InputError
                    v-for="(error, key) in form.errors"
                    :key="key"
                    :message="error"
                />
                <InputError
                    v-for="(error, key) in $page.props.errors"
                    :key="`page-${key}`"
                    :message="String(error)"
                />
            </CardContent>
        </Card>

        <p
            v-if="!props.batches.data.length"
            class="text-muted-foreground text-sm"
        >
            {{ t('No imports yet.') }}
        </p>
        <Card v-for="batch in props.batches.data" :key="batch.id">
            <CardHeader>
                <CardTitle class="flex flex-wrap items-center gap-2 text-base">
                    {{ t('Batch') }} #{{ batch.id }} · {{ batch.kind }} ·
                    {{ batch.rows.length }} {{ t('rows') }}
                    <Badge v-if="batch.committed_at" variant="secondary">{{
                        t('Imported')
                    }}</Badge>
                    <Badge
                        v-else-if="batch.errors.length"
                        variant="destructive"
                        >{{ t('Has errors') }}</Badge
                    >
                    <Badge v-else variant="outline">{{ t('Ready') }}</Badge>
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <p class="text-muted-foreground text-sm">
                    <template v-if="batch.committed_at"
                        >{{ t('Imported') }} {{ batch.created_ids?.length }}
                        {{ t('records at') }} {{ batch.committed_at }}</template
                    >
                    <template v-else
                        >{{ t('Expires') }} {{ batch.expires_at }}</template
                    >
                </p>
                <ul
                    v-if="batch.errors.length"
                    class="text-destructive space-y-1 text-sm"
                >
                    <li v-for="error in batch.errors" :key="error.row">
                        {{ t('Row') }} {{ error.row }}:
                        {{ error.messages.join(' ') }}
                    </li>
                </ul>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <caption
                            class="text-muted-foreground p-2 text-start text-xs"
                        >
                            {{
                                t('First 10 rows')
                            }}
                        </caption>
                        <thead>
                            <tr class="bg-muted/40">
                                <th
                                    v-for="key in Object.keys(
                                        batch.rows[0] ?? {},
                                    )"
                                    :key="key"
                                    class="p-2 text-start font-medium"
                                >
                                    {{ key }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in batch.rows.slice(0, 10)"
                                :key="index"
                            >
                                <td
                                    v-for="(value, key) in row"
                                    :key="key"
                                    class="border-t p-2"
                                >
                                    {{ value }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Button
                    v-if="!batch.committed_at && !batch.errors.length"
                    @click="commit(batch.id)"
                    >{{ t('Import') }} {{ batch.rows.length }}
                    {{ t('records') }}</Button
                >
            </CardContent>
        </Card>
        <Pagination :links="props.batches.links" />
    </div>
</template>
