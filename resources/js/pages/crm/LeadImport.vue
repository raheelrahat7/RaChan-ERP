<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Batch = {
    id: number;
    headers: string[];
    preview: Record<string, string>[];
    row_count: number;
    summary: {
        created: number;
        skipped_duplicates: number;
        failed: number;
    } | null;
    errors:
        | { row: number; messages: string[] | Record<string, string[]> }[]
        | null;
    committed_at: string | null;
};
const props = defineProps<{
    batch: Batch | null;
    targets: string[];
    pipelines: { id: number; name: string }[];
}>();
const upload = useForm<{ file: File | null }>({ file: null });
const commit = useForm({
    mapping: {} as Record<string, string>,
    duplicate_mode: 'skip',
    pipeline_id: props.pipelines[0]?.id ?? 0,
});
watch(
    () => props.batch?.id,
    () => {
        const mapping: Record<string, string> = {};
        for (const header of props.batch?.headers ?? []) {
            const guess = header
                .trim()
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '_');
            mapping[header] = props.targets.includes(guess) ? guess : '';
        }
        commit.mapping = mapping;
    },
    { immediate: true },
);
function uploadFile(event: Event): void {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function preview(): void {
    upload.post('/crm/leads/import/preview', {
        preserveScroll: true,
        forceFormData: true,
    });
}
function importRows(): void {
    if (props.batch)
        commit.post(`/crm/leads/import/${props.batch.id}/commit`, {
            preserveScroll: true,
        });
}
function downloadErrors(): void {
    if (!props.batch?.errors?.length) return;
    const csv = [
        'Row,Errors',
        ...props.batch.errors.map((item) => {
            const messages = Array.isArray(item.messages)
                ? item.messages
                : Object.values(item.messages).flat();
            return `${item.row},"${messages.join('; ').replaceAll('"', '""')}"`;
        }),
    ].join('\n');
    const url = URL.createObjectURL(
        new Blob([csv], { type: 'text/csv;charset=utf-8' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = 'lead-import-errors.csv';
    link.click();
    URL.revokeObjectURL(url);
}
</script>
<template>
    <Head title="Import leads" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Import leads"
            description="Preview a UTF-8 CSV, map columns to permitted lead fields, then import with a row-level result report."
        />
        <Link href="/crm/leads" class="text-sm underline">Back to leads</Link>
        <Card
            ><CardHeader><CardTitle>1. Source file</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="preview"
                >
                    <div>
                        <Label for="lead-import-file"
                            >CSV file · comma separated, 1,000 rows
                            maximum</Label
                        ><Input
                            id="lead-import-file"
                            type="file"
                            accept=".csv,.txt,text/csv"
                            required
                            @change="uploadFile"
                        />
                    </div>
                    <Button :disabled="upload.processing || !upload.file"
                        >Preview file</Button
                    >
                </form>
                <InputError
                    v-for="(error, key) in upload.errors"
                    :key="key"
                    :message="error"
                /> </CardContent
        ></Card>
        <Card v-if="batch"
            ><CardHeader
                ><CardTitle>2. Preview and field mapping</CardTitle></CardHeader
            ><CardContent class="space-y-4">
                <p class="text-sm">
                    {{ batch.row_count }} data rows ·
                    {{ batch.headers.length }} columns. The first 10 rows are
                    shown below.
                </p>
                <div class="overflow-x-auto rounded-md border">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr>
                                <th
                                    v-for="header in batch.headers"
                                    :key="header"
                                    class="border-b p-2 text-left"
                                >
                                    {{ header }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in batch.preview"
                                :key="index"
                            >
                                <td
                                    v-for="header in batch.headers"
                                    :key="header"
                                    class="border-b p-2"
                                >
                                    {{ row[header] }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div v-for="header in batch.headers" :key="header">
                        <Label :for="`mapping-${header}`">{{ header }}</Label
                        ><select
                            :id="`mapping-${header}`"
                            v-model="commit.mapping[header]"
                            class="border-input bg-background h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Skip column</option>
                            <option
                                v-for="target in targets"
                                :key="target"
                                :value="target"
                            >
                                {{
                                    target
                                        .replace('custom:', 'Custom · ')
                                        .replaceAll('_', ' ')
                                }}
                            </option>
                        </select>
                    </div>
                </div>
                <p class="text-muted-foreground text-sm">
                    Map first and last name, or full name. Blank source cells
                    stay empty. Custom fields are limited to those you may edit.
                </p>
                <div class="flex flex-wrap gap-4">
                    <div>
                        <Label for="import-pipeline">Pipeline</Label
                        ><select
                            id="import-pipeline"
                            v-model="commit.pipeline_id"
                            class="border-input bg-background h-9 rounded-md border px-3"
                        >
                            <option
                                v-for="pipeline in pipelines"
                                :key="pipeline.id"
                                :value="pipeline.id"
                            >
                                {{ pipeline.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="duplicate-mode">Duplicates</Label
                        ><select
                            id="duplicate-mode"
                            v-model="commit.duplicate_mode"
                            class="border-input bg-background h-9 rounded-md border px-3"
                        >
                            <option value="skip">
                                Skip matching email or phone
                            </option>
                            <option value="allow">Import all rows</option>
                        </select>
                    </div>
                </div>
                <div aria-live="polite">
                    <InputError
                        v-for="(error, key) in commit.errors"
                        :key="key"
                        :message="error"
                    />
                </div>
                <Button
                    v-if="!batch.committed_at"
                    :disabled="commit.processing"
                    @click="importRows"
                    >Import mapped rows</Button
                >
            </CardContent></Card
        >
        <Card v-if="batch?.summary"
            ><CardHeader><CardTitle>3. Result</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p>
                    Created {{ batch.summary.created }} · skipped duplicates
                    {{ batch.summary.skipped_duplicates }} · failed
                    {{ batch.summary.failed }}
                </p>
                <Button
                    v-if="batch.errors?.length"
                    variant="outline"
                    @click="downloadErrors"
                    >Download error report</Button
                >
                <div
                    v-for="error in batch.errors"
                    :key="error.row"
                    class="text-sm"
                >
                    Row {{ error.row }}:
                    {{
                        Array.isArray(error.messages)
                            ? error.messages.join('; ')
                            : Object.values(error.messages).flat().join('; ')
                    }}
                </div></CardContent
            ></Card
        >
    </div>
</template>
