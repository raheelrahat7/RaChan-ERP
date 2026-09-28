<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
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
    <Head title="Inventory imports" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <Heading
            title="Inventory imports"
            description="Preview and validate a CSV before creating inventory records."
        /><Link href="/inventory" class="underline">{{ t('Inventory') }}</Link>
        <p>
            UTF-8 CSV, up to 1 MB and 500 data rows. Headers must match exactly.
            Valid previews expire after 24 hours. Commit rechecks permissions
            and every row, then creates the whole batch once. Units can start as
            available or unavailable.
        </p>
        <form class="space-y-4 rounded border p-4" @submit.prevent="preview">
            <label class="block"
                >{{ t('Record type')
                }}<select v-model="form.kind" class="ml-3 rounded border p-2">
                    <option value="properties">{{ t('Properties') }}</option>
                    <option value="units">Units</option>
                </select></label
            >
            <p>Required header:</p>
            <code class="block break-all">{{
                form.kind === 'properties'
                    ? 'name,type,city,address_line_1'
                    : 'property_id,number,type,status,area,asking_price'
            }}</code
            ><label class="block"
                >{{ t('CSV file')
                }}<input
                    type="file"
                    accept=".csv,text/csv"
                    class="ml-3"
                    @change="fileChanged"
            /></label>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <Button :disabled="form.processing || !form.file">{{
                t('Validate preview')
            }}</Button>
        </form>
        <p
            v-for="(error, key) in $page.props.errors"
            :key="key"
            role="alert"
            class="text-destructive"
        >
            {{ error }}
        </p>
        <article
            v-for="batch in props.batches.data"
            :key="batch.id"
            class="space-y-3 rounded border p-4"
        >
            <h2 class="font-semibold">
                Batch #{{ batch.id }} · {{ batch.kind }} ·
                {{ batch.rows.length }} rows
            </h2>
            <p v-if="batch.committed_at">
                Imported {{ batch.created_ids?.length }} records at
                {{ batch.committed_at }}
            </p>
            <p v-else>Expires {{ batch.expires_at }}</p>
            <ul class="text-destructive">
                <li v-for="error in batch.errors" :key="error.row">
                    Row {{ error.row }}: {{ error.messages.join(' ') }}
                </li>
            </ul>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="text-start">
                        {{
                            t('First 10 rows')
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th
                                v-for="key in Object.keys(batch.rows[0] ?? {})"
                                :key="key"
                                class="p-2 text-start"
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
                >Import {{ batch.rows.length }} records</Button
            >
        </article>
        <Pagination :links="props.batches.links" />
    </div>
</template>
