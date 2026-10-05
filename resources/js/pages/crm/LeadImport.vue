<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Check, ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CrmImportFieldForm from '@/components/CrmImportFieldForm.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import {
    duplicateTargets,
    errorMessages,
    guessMapping,
    hasNameMapping,
    initialStep,
    sampleValues,
    STEPS,
    targetLabel,
} from '@/lib/crm-import-wizard';
import type { ImportStep } from '@/lib/crm-import-wizard';

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
    source_settings: Record<string, unknown> | null;
};
const props = defineProps<{
    batch: Batch | null;
    members: { id: number; name: string }[];
    sourceOptions: {
        encodings: string[];
        delimiters: string[];
        nameFormats: string[];
    };
    sampleUrl: string;
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

const { t } = useLocale();
const page = usePage();
const userId = computed(() => {
    const id = (page.props.auth as { user?: { id?: number } } | undefined)?.user
        ?.id;

    return typeof id === 'number' ? id : null;
});
const step = ref<ImportStep>(initialStep(props.batch));
const fileName = ref('');
const activeHeader = ref<string | null>(null);

const upload = useForm<{
    file: File | null;
    encoding: string;
    delimiter: string;
    has_header: boolean;
    skip_empty_columns: boolean;
    name_format: string;
}>({
    file: null,
    encoding: 'UTF-8',
    delimiter: 'comma',
    has_header: true,
    skip_empty_columns: false,
    name_format: 'first_last',
});
const commit = useForm({
    mapping: {} as Record<string, string>,
    required_targets: [] as string[],
    duplicate_mode: 'skip',
    pipeline_id: props.pipelines[0]?.id ?? 0,
    assigned_to:
        userId.value !== null &&
        props.members.some((m) => m.id === userId.value)
            ? String(userId.value)
            : '',
});
const customNames = computed(() =>
    Object.fromEntries(
        props.customFields.map((field) => [field.key, field.name]),
    ),
);
const mappedCount = computed(
    () => Object.values(commit.mapping).filter(Boolean).length,
);
const nameOk = computed(() => hasNameMapping(commit.mapping));
const repeated = computed(() => duplicateTargets(commit.mapping));
const canContinue = computed(() => nameOk.value && repeated.value.length === 0);
const pipelineName = computed(
    () =>
        props.pipelines.find((p) => p.id === Number(commit.pipeline_id))
            ?.name ?? '',
);
const memberName = computed(
    () =>
        props.members.find((m) => String(m.id) === commit.assigned_to)?.name ??
        t('Assign automatically'),
);
const labels: Record<string, string> = {
    comma: 'Comma',
    semicolon: 'Semicolon',
    tab: 'Tab',
    first_last: 'John Smith',
    last_first: 'Smith John',
};

watch(
    () => props.batch?.id,
    () => {
        commit.mapping = guessMapping(
            props.batch?.headers ?? [],
            props.targets,
        );
        commit.required_targets = [];
    },
    { immediate: true },
);

function pickFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    upload.file = file;
    fileName.value = file?.name ?? '';
}
function previewFile(): void {
    upload.post('/crm/leads/import/preview', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            step.value = 2;
        },
    });
}
function toggleRequired(target: string, enabled: boolean): void {
    commit.required_targets = enabled
        ? [...new Set([...commit.required_targets, target])]
        : commit.required_targets.filter((item) => item !== target);
}
function isRequired(target: string): boolean {
    return props.customFields.some(
        (field) => `custom:${field.key}` === target && field.required,
    );
}
function fieldCreated(key: string): void {
    if (activeHeader.value) {
        commit.mapping[activeHeader.value] = `custom:${key}`;
    }
    activeHeader.value = null;
}
function importRows(): void {
    if (!props.batch) {
        return;
    }
    commit
        .transform((data) => ({
            ...data,
            assigned_to:
                data.assigned_to === '' ? null : Number(data.assigned_to),
        }))
        .post(`/crm/leads/import/${props.batch.id}/commit`, {
            preserveScroll: true,
            onSuccess: () => {
                step.value = 4;
            },
            onError: () => {
                step.value =
                    commit.errors.mapping || commit.errors.required_targets
                        ? 2
                        : step.value;
            },
        });
}
function downloadErrors(): void {
    if (!props.batch?.errors?.length) {
        return;
    }
    const csv = [
        'Row,Errors',
        ...props.batch.errors.map(
            (item) =>
                `${item.row},"${errorMessages(item.messages).replaceAll('"', '""')}"`,
        ),
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
function startOver(): void {
    upload.reset();
    fileName.value = '';
    step.value = 1;
}
</script>

<template>
    <Head :title="t('Import leads')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="t('Import leads')" :translate="false">
            <template #actions>
                <Link href="/crm/leads" class="text-sm underline">{{
                    t('Back to leads')
                }}</Link>
            </template>
        </PageHeader>

        <nav :aria-label="t('Import steps')">
            <ol class="flex flex-wrap items-center gap-2 text-sm">
                <li
                    v-for="(item, index) in STEPS"
                    :key="item.step"
                    class="flex items-center gap-2"
                >
                    <span
                        class="flex items-center gap-1.5"
                        :class="
                            item.step === step
                                ? 'text-foreground font-semibold'
                                : item.step < step
                                  ? 'text-primary'
                                  : 'text-muted-foreground'
                        "
                        :aria-current="item.step === step ? 'step' : undefined"
                    >
                        <span
                            class="flex size-5 items-center justify-center rounded-full border text-xs"
                            :class="
                                item.step < step
                                    ? 'bg-primary text-primary-foreground border-primary'
                                    : item.step === step
                                      ? 'border-foreground'
                                      : ''
                            "
                        >
                            <Check
                                v-if="item.step < step"
                                class="size-3"
                                aria-hidden="true"
                            /><template v-else>{{ item.step }}</template>
                        </span>
                        {{ t(item.label) }}
                    </span>
                    <ChevronRight
                        v-if="index < STEPS.length - 1"
                        class="text-muted-foreground size-4 rtl:rotate-180"
                        aria-hidden="true"
                    />
                </li>
            </ol>
        </nav>

        <Card v-if="step === 1">
            <CardContent class="space-y-5">
                <form class="space-y-5" @submit.prevent="previewFile">
                    <div class="space-y-2">
                        <Label for="lead-import-file"
                            >{{ t('Import CSV file') }}
                            <span class="text-destructive">*</span></Label
                        >
                        <div class="flex flex-wrap items-center gap-3">
                            <Input
                                id="lead-import-file"
                                type="file"
                                accept=".csv,.txt,text/csv"
                                required
                                class="max-w-sm"
                                @change="pickFile"
                            />
                            <span
                                v-if="fileName"
                                class="text-muted-foreground text-sm"
                                >{{ fileName }}</span
                            >
                        </div>
                        <p class="bg-muted/50 rounded-md border p-3 text-sm">
                            <a
                                :href="sampleUrl"
                                class="text-primary underline"
                                >{{ t('Click here') }}</a
                            >
                            {{
                                t(
                                    'to download a sample import file and use it as a reference to make sure your source CSV file follows the source file guidelines.',
                                )
                            }}
                            {{ t('1,000 rows maximum.') }}
                        </p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <Label for="import-encoding">{{
                                t('File encoding')
                            }}</Label>
                            <select
                                id="import-encoding"
                                v-model="upload.encoding"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option
                                    v-for="option in sourceOptions.encodings"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ option }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="import-delimiter">{{
                                t('Field delimiter')
                            }}</Label>
                            <select
                                id="import-delimiter"
                                v-model="upload.delimiter"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option
                                    v-for="option in sourceOptions.delimiters"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ t(labels[option] ?? option) }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="import-name-format">{{
                                t('Name format')
                            }}</Label>
                            <select
                                id="import-name-format"
                                v-model="upload.name_format"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option
                                    v-for="option in sourceOptions.nameFormats"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ labels[option] ?? option }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-6 text-sm">
                        <label class="flex items-center gap-2"
                            ><input
                                v-model="upload.has_header"
                                type="checkbox"
                            />{{ t('First row is a header') }}</label
                        >
                        <label class="flex items-center gap-2"
                            ><input
                                v-model="upload.skip_empty_columns"
                                type="checkbox"
                            />{{ t('Skip empty columns') }}</label
                        >
                    </div>
                    <InputError
                        v-for="(error, key) in upload.errors"
                        :key="key"
                        :message="error"
                    />
                    <div class="flex gap-2">
                        <Button
                            type="submit"
                            :disabled="upload.processing || !upload.file"
                            >{{ t('Next') }}</Button
                        >
                        <Button
                            v-if="batch && !batch.committed_at"
                            type="button"
                            variant="outline"
                            @click="step = 2"
                            >{{ t('Use the previous upload') }}</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <template v-else-if="step === 2 && batch">
            <Card>
                <CardContent class="space-y-4">
                    <h2 class="text-eyebrow">{{ t('Default values') }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="import-responsible">{{
                                t('Responsible person')
                            }}</Label>
                            <select
                                id="import-responsible"
                                v-model="commit.assigned_to"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="">
                                    {{ t('Assign automatically') }}
                                </option>
                                <option
                                    v-for="member in members"
                                    :key="member.id"
                                    :value="String(member.id)"
                                >
                                    {{ member.name
                                    }}{{
                                        member.id === userId
                                            ? ` (${t('You')})`
                                            : ''
                                    }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="import-pipeline">{{
                                t('Pipeline')
                            }}</Label>
                            <select
                                id="import-pipeline"
                                v-model="commit.pipeline_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
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
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="space-y-4">
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h2 class="text-eyebrow">{{ t('Field mapping') }}</h2>
                        <p class="text-muted-foreground text-sm">
                            {{ batch.row_count }} {{ t('data rows') }} ·
                            {{ batch.headers.length }} {{ t('columns') }}
                        </p>
                    </div>
                    <div class="divide-y rounded-md border">
                        <div
                            v-for="header in batch.headers"
                            :key="header"
                            class="grid gap-2 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] md:items-start"
                        >
                            <div class="min-w-0">
                                <p class="font-medium break-words">
                                    {{ header }}
                                </p>
                                <p
                                    class="text-muted-foreground truncate text-xs"
                                    :title="
                                        sampleValues(
                                            batch.preview,
                                            header,
                                        ).join(' · ')
                                    "
                                >
                                    {{
                                        sampleValues(
                                            batch.preview,
                                            header,
                                        ).join(' · ') || t('No sample values')
                                    }}
                                </p>
                            </div>
                            <div class="space-y-2">
                                <select
                                    v-model="commit.mapping[header]"
                                    :aria-label="`${t('Map column')} ${header}`"
                                    class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                >
                                    <option value="">
                                        {{ t('Skip column') }}
                                    </option>
                                    <option
                                        v-for="target in targets"
                                        :key="target"
                                        :value="target"
                                    >
                                        {{ targetLabel(target, customNames) }}
                                    </option>
                                </select>
                                <div
                                    class="flex flex-wrap items-center gap-3 text-xs"
                                >
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
                                                isRequired(
                                                    commit.mapping[header],
                                                )
                                            "
                                            :disabled="
                                                isRequired(
                                                    commit.mapping[header],
                                                )
                                            "
                                            @change="
                                                toggleRequired(
                                                    commit.mapping[header],
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                )
                                            "
                                        />
                                        {{ t('Required in this import') }}
                                    </label>
                                    <Button
                                        v-if="canConfigureFields"
                                        type="button"
                                        variant="link"
                                        size="sm"
                                        class="h-auto p-0"
                                        @click="activeHeader = header"
                                        >{{
                                            t('Create field from column')
                                        }}</Button
                                    >
                                </div>
                                <CrmImportFieldForm
                                    v-if="
                                        activeHeader === header &&
                                        canConfigureFields
                                    "
                                    :header="header"
                                    :field-types="fieldTypes"
                                    @created="fieldCreated"
                                    @cancel="activeHeader = null"
                                />
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="!nameOk"
                        class="text-destructive text-sm"
                        role="status"
                    >
                        {{
                            t('Map first and last name, or a full-name column.')
                        }}
                    </p>
                    <p
                        v-if="repeated.length"
                        class="text-destructive text-sm"
                        role="status"
                    >
                        {{
                            t(
                                'Each lead field can be mapped to one column only.',
                            )
                        }}
                    </p>
                    <div aria-live="polite">
                        <InputError
                            v-for="(error, key) in commit.errors"
                            :key="key"
                            :message="error"
                        />
                    </div>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            :disabled="!canContinue"
                            @click="step = 3"
                            >{{ t('Next') }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            @click="step = 1"
                            >{{ t('Back') }}</Button
                        >
                    </div>
                </CardContent>
            </Card>
        </template>

        <Card v-else-if="step === 3 && batch">
            <CardContent class="space-y-5">
                <h2 class="text-eyebrow">{{ t('Duplicate control') }}</h2>
                <fieldset class="grid gap-3 sm:grid-cols-2">
                    <legend class="sr-only">
                        {{ t('Duplicate control') }}
                    </legend>
                    <label
                        v-for="option in [
                            {
                                value: 'skip',
                                title: 'Skip duplicates',
                                text: 'Rows whose email or phone already match a lead are skipped.',
                            },
                            {
                                value: 'allow',
                                title: 'Import anyway',
                                text: 'Every row is imported, even if a matching lead exists.',
                            },
                        ]"
                        :key="option.value"
                        class="flex cursor-pointer gap-3 rounded-md border p-4"
                        :class="
                            commit.duplicate_mode === option.value
                                ? 'border-primary bg-primary/5'
                                : ''
                        "
                    >
                        <input
                            v-model="commit.duplicate_mode"
                            type="radio"
                            name="duplicate_mode"
                            :value="option.value"
                            class="mt-1"
                        />
                        <span
                            ><span class="block font-medium">{{
                                t(option.title)
                            }}</span
                            ><span
                                class="text-muted-foreground block text-sm"
                                >{{ t(option.text) }}</span
                            ></span
                        >
                    </label>
                </fieldset>
                <dl class="grid gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            {{ t('Rows') }}
                        </dt>
                        <dd>{{ batch.row_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            {{ t('Mapped columns') }}
                        </dt>
                        <dd>{{ mappedCount }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            {{ t('Pipeline') }}
                        </dt>
                        <dd>{{ pipelineName }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            {{ t('Responsible person') }}
                        </dt>
                        <dd>{{ memberName }}</dd>
                    </div>
                </dl>
                <div aria-live="polite">
                    <InputError
                        v-for="(error, key) in commit.errors"
                        :key="key"
                        :message="error"
                    />
                </div>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        :disabled="commit.processing"
                        @click="importRows"
                        >{{ t('Import') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="commit.processing"
                        @click="step = 2"
                        >{{ t('Back') }}</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card v-else-if="step === 4">
            <CardContent class="space-y-5">
                <h2 class="text-eyebrow">{{ t('Result') }}</h2>
                <dl v-if="batch?.summary" class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-md border p-4">
                        <dt class="text-muted-foreground text-xs">
                            {{ t('Created') }}
                        </dt>
                        <dd class="font-display text-3xl">
                            {{ batch.summary.created }}
                        </dd>
                    </div>
                    <div class="rounded-md border p-4">
                        <dt class="text-muted-foreground text-xs">
                            {{ t('Skipped duplicates') }}
                        </dt>
                        <dd class="font-display text-3xl">
                            {{ batch.summary.skipped_duplicates }}
                        </dd>
                    </div>
                    <div
                        class="rounded-md border p-4"
                        :class="
                            batch.summary.failed ? 'border-destructive/50' : ''
                        "
                    >
                        <dt class="text-muted-foreground text-xs">
                            {{ t('Failed') }}
                        </dt>
                        <dd class="font-display text-3xl">
                            {{ batch.summary.failed }}
                        </dd>
                    </div>
                </dl>
                <p v-else class="text-muted-foreground text-sm">
                    {{ t('No import result yet.') }}
                </p>
                <div v-if="batch?.errors?.length" class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-medium">
                            {{ t('Rows that need attention') }}
                        </h3>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="downloadErrors"
                            >{{ t('Download error report') }}</Button
                        >
                    </div>
                    <ul
                        class="max-h-72 divide-y overflow-y-auto rounded-md border text-sm"
                    >
                        <li
                            v-for="error in batch.errors"
                            :key="error.row"
                            class="p-2"
                        >
                            {{ t('Row') }} {{ error.row }}:
                            {{ errorMessages(error.messages) }}
                        </li>
                    </ul>
                </div>
                <div class="flex gap-2">
                    <Button as-child
                        ><Link href="/crm/leads">{{
                            t('View leads')
                        }}</Link></Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="startOver"
                        >{{ t('Import another file') }}</Button
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
