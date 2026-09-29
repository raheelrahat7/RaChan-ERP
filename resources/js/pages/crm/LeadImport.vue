<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
    customFields: {
        key: string;
        name: string;
        type: string;
        required: boolean;
    }[];
    fieldTypes: string[];
    canConfigureFields: boolean;
}>();
const upload = useForm<{ file: File | null }>({ file: null });
const commit = useForm({
    mapping: {} as Record<string, string>,
    required_targets: [] as string[],
    duplicate_mode: 'skip',
    pipeline_id: props.pipelines[0]?.id ?? 0,
});
const activeHeader = ref<string | null>(null);
const fieldForm = useForm({
    name: '',
    key: '',
    type: 'text',
    options: '',
    required: false,
    view_roles: ['owner', 'administrator'] as string[],
    edit_roles: ['owner', 'administrator'] as string[],
});
function startField(header: string): void {
    activeHeader.value = header;
    fieldForm.name = header;
    fieldForm.key = header
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '');
    fieldForm.type = 'text';
    fieldForm.options = '';
    fieldForm.required = false;
    fieldForm.clearErrors();
}
function createField(): void {
    const header = activeHeader.value;
    fieldForm
        .transform((data) => ({
            ...data,
            options: data.options
                .split('\n')
                .map((value) => value.trim())
                .filter(Boolean),
        }))
        .post('/crm/custom-fields', {
            preserveScroll: true,
            onSuccess: () => {
                if (header) commit.mapping[header] = `custom:${fieldForm.key}`;
                activeHeader.value = null;
                fieldForm.reset();
            },
        });
}
function toggleRequired(target: string, enabled: boolean): void {
    commit.required_targets = enabled
        ? [...new Set([...commit.required_targets, target])]
        : commit.required_targets.filter((item) => item !== target);
}
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
        commit.required_targets = [];
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
                    <div
                        v-for="header in batch.headers"
                        :key="header"
                        class="space-y-2 rounded-md border p-3"
                    >
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
                        <div class="flex flex-wrap items-center gap-3 text-xs">
                            <label
                                v-if="commit.mapping[header]"
                                class="flex items-center gap-2"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        commit.required_targets.includes(
                                            commit.mapping[header],
                                        ) ||
                                        customFields.some(
                                            (field) =>
                                                `custom:${field.key}` ===
                                                    commit.mapping[header] &&
                                                field.required,
                                        )
                                    "
                                    :disabled="
                                        customFields.some(
                                            (field) =>
                                                `custom:${field.key}` ===
                                                    commit.mapping[header] &&
                                                field.required,
                                        )
                                    "
                                    @change="
                                        toggleRequired(
                                            commit.mapping[header],
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                Required in this import
                            </label>
                            <Button
                                v-if="canConfigureFields"
                                type="button"
                                variant="link"
                                size="sm"
                                @click="startField(header)"
                                >Create field from column</Button
                            >
                        </div>
                    </div>
                </div>
                <form
                    v-if="activeHeader && canConfigureFields"
                    class="grid gap-3 rounded-md border p-4 sm:grid-cols-2"
                    @submit.prevent="createField"
                >
                    <h3 class="font-medium sm:col-span-2">
                        New lead field for “{{ activeHeader }}”
                    </h3>
                    <div>
                        <Label for="new-field-name">Field name</Label
                        ><Input
                            id="new-field-name"
                            v-model="fieldForm.name"
                            required
                        />
                    </div>
                    <div>
                        <Label for="new-field-key">Internal key</Label
                        ><Input
                            id="new-field-key"
                            v-model="fieldForm.key"
                            required
                            pattern="[A-Za-z0-9_-]+"
                        />
                    </div>
                    <div>
                        <Label for="new-field-type">Type</Label
                        ><select
                            id="new-field-type"
                            v-model="fieldForm.type"
                            class="border-input bg-background h-9 w-full rounded-md border px-3"
                        >
                            <option
                                v-for="type in fieldTypes"
                                :key="type"
                                :value="type"
                            >
                                {{ type.replaceAll('_', ' ') }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="
                            ['single_select', 'multi_select'].includes(
                                fieldForm.type,
                            )
                        "
                    >
                        <Label for="new-field-options"
                            >Options, one per line</Label
                        ><textarea
                            id="new-field-options"
                            v-model="fieldForm.options"
                            class="border-input bg-background min-h-20 w-full rounded-md border p-2"
                            required
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="fieldForm.required"
                            type="checkbox"
                        />Required on every new lead</label
                    >
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            type="checkbox"
                            :checked="fieldForm.view_roles.length > 2"
                            @change="
                                fieldForm.view_roles = (
                                    $event.target as HTMLInputElement
                                ).checked
                                    ? [
                                          'owner',
                                          'administrator',
                                          'manager',
                                          'member',
                                          'viewer',
                                      ]
                                    : ['owner', 'administrator'];
                                fieldForm.edit_roles = [
                                    ...fieldForm.view_roles,
                                ];
                            "
                        />Visible to all CRM roles</label
                    >
                    <p class="text-muted-foreground text-xs sm:col-span-2">
                        New fields default to owner and administrator access.
                        Import-only required fields can be chosen above without
                        making the field compulsory on every lead.
                    </p>
                    <InputError
                        v-for="(error, key) in fieldForm.errors"
                        :key="key"
                        :message="error"
                        class="sm:col-span-2"
                    />
                    <div class="flex gap-2 sm:col-span-2">
                        <Button :disabled="fieldForm.processing"
                            >Create and map field</Button
                        ><Button
                            type="button"
                            variant="outline"
                            @click="activeHeader = null"
                            >Cancel</Button
                        >
                    </div>
                </form>
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
