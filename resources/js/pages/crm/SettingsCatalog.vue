<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import {
    buildPayload,
    catalogCode,
    catalogSummary,
    findCatalogSection,
    formFor,
    isActive,
} from '@/lib/crm-catalog';
import type { CatalogForm, CatalogRecord } from '@/lib/crm-catalog';
import { ApiError, apiJson } from '@/lib/crm-api';
import type { DataTableColumn } from '@/lib/data-table';

type Row = {
    id: number;
    name: string;
    code: string;
    summary: string;
    status: string;
};

const props = defineProps<{ section: string }>();
const { t } = useLocale();
const config = computed(() => findCatalogSection(props.section)!);
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const records = ref<CatalogRecord[]>([]);
const units = ref<CatalogRecord[]>([]);
const taxes = ref<CatalogRecord[]>([]);
const loading = ref(true);
const loadError = ref('');
const message = ref('');
const open = ref(false);
const editing = ref<CatalogRecord | null>(null);
const form = ref<CatalogForm>(formFor(config.value));
const errors = ref<Record<string, string>>({});
const busy = ref(false);

const rows = computed<Row[]>(() =>
    records.value.map((record) => ({
        id: record.id,
        name: record.name,
        code: record.code,
        summary: catalogSummary(config.value, record),
        status: isActive(record) ? t('Active') : t('Archived'),
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'code', label: t('Code'), sortable: true },
    { key: 'summary', label: t(config.value.summaryLabel) },
    { key: 'status', label: t('Status') },
]);
const activeOf = (list: CatalogRecord[]): CatalogRecord[] =>
    list.filter(isActive);

async function fetchKind(kind: string): Promise<CatalogRecord[]> {
    const data = await apiJson<{ records: CatalogRecord[] }>(
        `/organization/crm-catalog/${kind}`,
    );

    return data.records;
}

async function load(): Promise<void> {
    try {
        records.value = await fetchKind(config.value.key);
        if (config.value.key === 'products') {
            [units.value, taxes.value] = await Promise.all([
                fetchKind('units'),
                fetchKind('taxes'),
            ]);
        }
        loadError.value = '';
    } catch (failure) {
        loadError.value =
            failure instanceof ApiError && failure.status === 403
                ? t('Only owners and administrators can change these settings.')
                : t('Could not load these settings.');
    } finally {
        loading.value = false;
    }
}

function openForm(record?: CatalogRecord): void {
    editing.value = record ?? null;
    form.value = formFor(config.value, record);
    errors.value = {};
    open.value = true;
}

async function persist(
    record: CatalogRecord | null,
    data: Record<string, unknown>,
): Promise<void> {
    const base = `/organization/crm-catalog/${config.value.key}`;
    const versioned =
        record && config.value.key === 'other-settings'
            ? { expected_version: record.version ?? 1 }
            : {};
    await apiJson(
        record ? `${base}/${record.id}` : base,
        record ? 'PUT' : 'POST',
        { ...data, ...versioned },
    );
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await persist(
            editing.value,
            buildPayload(config.value, form.value, editing.value?.code),
        );
        open.value = false;
        message.value = t('Saved.');
        await load();
    } catch (failure) {
        if (failure instanceof ApiError) {
            const fields = failure.fieldErrors();
            errors.value = Object.keys(fields).length
                ? fields
                : { form: failure.message };
        } else {
            errors.value = { form: t('Could not save.') };
        }
    } finally {
        busy.value = false;
    }
}

/** Catalog records are archived, never removed. */
async function archive(keys: (string | number)[]): Promise<void> {
    message.value = '';
    for (const key of keys) {
        const record = records.value.find((item) => item.id === key);
        if (!record || !isActive(record)) {
            continue;
        }
        const data = buildPayload(
            config.value,
            formFor(config.value, record),
            record.code,
        );
        try {
            await persist(record, { ...data, active: false });
        } catch (failure) {
            message.value =
                failure instanceof ApiError
                    ? failure.message
                    : t('Could not archive.');
        }
    }
    await load();
}

function editSelected(keys: (string | number)[]): void {
    const record = records.value.find((item) => item.id === keys[0]);
    if (record) {
        openForm(record);
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t(config.title)" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <Link
            href="/crm/settings"
            class="text-muted-foreground text-sm underline"
            >{{ t('Back to CRM settings') }}</Link
        >
        <p v-if="message" role="status" class="text-sm">{{ message }}</p>
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <CrmSettingsTable
            v-else
            :title="config.title"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            :add-label="config.addLabel"
            searchable
            can-edit
            @add="openForm()"
            @edit="editSelected"
            @delete="archive"
        >
            <template #cell-status="{ row }">
                <Badge
                    :variant="
                        row.status === t('Active') ? 'secondary' : 'outline'
                    "
                    >{{ row.status }}</Badge
                >
            </template>
        </CrmSettingsTable>
        <p v-if="loading" class="text-muted-foreground text-sm">
            {{ t('Loading…') }}
        </p>
        <p
            v-if="config.key === 'taxes' || config.key === 'products'"
            class="text-muted-foreground text-xs"
        >
            {{
                t(
                    'These are reference values. They do not change invoice, VAT or estimate calculations automatically.',
                )
            }}
        </p>
        <p
            v-if="config.key === 'mailboxes'"
            class="text-muted-foreground text-xs"
        >
            {{
                t(
                    'Mailboxes hold sender details only. Sending email is not configured.',
                )
            }}
        </p>

        <Dialog v-model:open="open">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? t('Edit') : t(config.addLabel)
                    }}</DialogTitle>
                    <DialogDescription>{{ t(config.title) }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="save">
                    <InputError :message="errors.form" />
                    <div class="space-y-1">
                        <Label for="cat-name">{{ t('Name') }}</Label>
                        <Input id="cat-name" v-model="form.name" />
                        <InputError :message="errors.name" />
                    </div>
                    <div v-if="!editing" class="space-y-1">
                        <Label for="cat-code">{{ t('Code') }}</Label>
                        <Input
                            id="cat-code"
                            v-model="form.code"
                            :placeholder="catalogCode(form.name)"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{
                                t(
                                    'Lowercase letters, numbers and dashes. It cannot change later.',
                                )
                            }}
                        </p>
                        <InputError :message="errors.code" />
                    </div>
                    <div
                        v-for="field in config.fields"
                        :key="field.key"
                        class="space-y-1"
                    >
                        <Label :for="`cat-${field.key}`">{{
                            t(field.label)
                        }}</Label>
                        <textarea
                            v-if="
                                field.type === 'textarea' ||
                                field.type === 'lines'
                            "
                            :id="`cat-${field.key}`"
                            v-model="form.settings[field.key]"
                            rows="3"
                            class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                        />
                        <select
                            v-else-if="field.type === 'select' && field.options"
                            :id="`cat-${field.key}`"
                            v-model="form.settings[field.key]"
                            :class="selectClass"
                        >
                            <option
                                v-for="option in field.options"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ t(option.label) }}
                            </option>
                        </select>
                        <select
                            v-else-if="field.type === 'select'"
                            :id="`cat-${field.key}`"
                            v-model="form.settings[field.key]"
                            :class="selectClass"
                        >
                            <option value="">—</option>
                            <option
                                v-for="item in activeOf(
                                    field.key === 'unit_id' ? units : taxes,
                                )"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                        <Input
                            v-else
                            :id="`cat-${field.key}`"
                            v-model="form.settings[field.key]"
                            :type="field.type"
                            :step="field.type === 'number' ? 'any' : undefined"
                        />
                        <InputError
                            :message="errors[`settings.${field.key}`]"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="cat-pos">{{ t('Sorting') }}</Label>
                        <Input
                            id="cat-pos"
                            v-model.number="form.position"
                            type="number"
                            min="0"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="form.active" type="checkbox" />{{
                            t('Active')
                        }}</label
                    >
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="busy">{{
                            t('Save')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
