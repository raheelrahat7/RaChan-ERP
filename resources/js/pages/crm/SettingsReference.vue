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
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    findReferenceSection,
    formDefaults,
    isActive,
    numberPreview,
    parentOptions,
    rebaseRates,
    sectionRows,
} from '@/lib/crm-reference';
import type { ReferenceRecord, RebaseRate } from '@/lib/crm-reference';
import type { DataTableColumn } from '@/lib/data-table';

type Row = ReferenceRecord & { flags: string; parent: string; preview: string };

const props = defineProps<{ section: string }>();
const { t } = useLocale();
const config = computed(() => findReferenceSection(props.section)!);

const records = ref<ReferenceRecord[]>([]);
const loading = ref(true);
const loadError = ref('');
const open = ref(false);
const editing = ref<ReferenceRecord | null>(null);
type RefForm = {
    code: string;
    name: string;
    exchange_rate: string;
    face_value: number;
    is_base: boolean;
    is_reporting: boolean;
    type: string;
    parent_id: number | null;
    kind: string;
    prefix: string;
    padding: number;
    next_number: number;
    include_year: boolean;
    active: boolean;
};
const blank: RefForm = {
    code: '',
    name: '',
    exchange_rate: '1',
    face_value: 1,
    is_base: false,
    is_reporting: false,
    type: 'country',
    parent_id: null,
    kind: 'document',
    prefix: '',
    padding: 5,
    next_number: 1,
    include_year: false,
    active: true,
};
const form = ref<RefForm>({ ...blank });
const errors = ref<Record<string, string>>({});
const message = ref('');
const busy = ref(false);

const rows = computed(() => sectionRows(config.value, records.value));
const columns = computed((): DataTableColumn<Row>[] => {
    const kind = config.value.kind;
    if (kind === 'currencies') {
        return [
            { key: 'code', label: t('Code'), sortable: true },
            { key: 'name', label: t('Name'), sortable: true },
            { key: 'exchange_rate', label: t('Rate'), align: 'end' as const },
            { key: 'flags', label: t('Role') },
            { key: 'active', label: t('Status') },
        ];
    }
    if (kind === 'locations') {
        return [
            { key: 'name', label: t('Name'), sortable: true },
            { key: 'type', label: t('Type'), sortable: true },
            { key: 'parent', label: t('Belongs to') },
            { key: 'active', label: t('Status') },
        ];
    }

    return [
        { key: 'prefix', label: t('Prefix'), sortable: true },
        { key: 'preview', label: t('Next number') },
        { key: 'padding', label: t('Digits'), align: 'end' as const },
        { key: 'active', label: t('Status') },
    ];
});
const tableRows = computed((): Row[] =>
    rows.value.map((row) => ({
        ...row,
        flags: [
            row.is_base ? t('Base') : '',
            row.is_reporting ? t('Reporting') : '',
        ]
            .filter(Boolean)
            .join(', '),
        parent: String(
            records.value.find((item) => item.id === row.parent_id)?.name ?? '',
        ),
        preview: numberPreview(
            String(row.prefix ?? ''),
            Number(row.padding ?? 1),
            Number(row.next_number ?? 1),
            row.include_year === 1 || row.include_year === true,
            new Date().getFullYear(),
        ),
    })),
);
const parents = computed(() =>
    parentOptions(records.value, String(form.value.type ?? '')),
);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiJson<{ records: ReferenceRecord[] }>(
            `/organization/reference-settings/${config.value.kind}`,
        );
        records.value = data.records;
        loadError.value = '';
    } catch (error) {
        loadError.value =
            error instanceof ApiError && error.status === 403
                ? t('Only owners and administrators can change these settings.')
                : t('Could not load these settings.');
    } finally {
        loading.value = false;
    }
}

function openForm(record?: ReferenceRecord): void {
    editing.value = record ?? null;
    form.value = { ...blank, ...formDefaults(config.value, record) } as RefForm;
    errors.value = {};
    open.value = true;
}

async function persist(
    record: ReferenceRecord | null,
    data: Record<string, unknown>,
): Promise<void> {
    const base = `/organization/reference-settings/${config.value.kind}`;
    await apiJson(
        record ? `${base}/${record.id}` : base,
        record ? 'PUT' : 'POST',
        data,
    );
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        const keys = Object.keys(
            formDefaults(config.value, editing.value ?? undefined),
        );
        await persist(
            editing.value,
            Object.fromEntries(
                Object.entries(form.value).filter(([key]) =>
                    keys.includes(key),
                ),
            ),
        );
        open.value = false;
        message.value = t('Saved.');
        await load();
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.fieldErrors();
            if (!Object.keys(errors.value).length) {
                errors.value = { form: error.message };
            }
        } else {
            errors.value = { form: t('Could not save.') };
        }
    } finally {
        busy.value = false;
    }
}

/** Reference data is archived, never removed, so the table's Delete archives. */
async function archive(keys: (string | number)[]): Promise<void> {
    message.value = '';
    for (const key of keys) {
        const record = records.value.find((row) => row.id === key);
        if (!record || !isActive(record)) {
            continue;
        }
        try {
            await persist(record, {
                ...formDefaults(config.value, record),
                active: false,
            });
        } catch (error) {
            message.value =
                error instanceof ApiError
                    ? Object.values(error.fieldErrors())[0] || error.message
                    : t('Could not archive.');
        }
    }
    await load();
}

function editSelected(keys: (string | number)[]): void {
    const record = records.value.find((row) => row.id === keys[0]);
    if (record) {
        openForm(record);
    }
}

// Rebase ---------------------------------------------------------------
const rebaseOpen = ref(false);
const rebaseBase = ref('');
const rebaseList = ref<RebaseRate[]>([]);
const rebaseError = ref('');
const activeCurrencies = computed(() => records.value.filter(isActive));

function openRebase(): void {
    const current = records.value.find(
        (row) => row.is_base === 1 || row.is_base === true,
    );
    rebaseBase.value = String(
        current?.code ?? activeCurrencies.value[0]?.code ?? '',
    );
    rebaseList.value = rebaseRates(records.value, rebaseBase.value);
    rebaseError.value = '';
    rebaseOpen.value = true;
}

function pickBase(): void {
    rebaseList.value = rebaseRates(
        records.value.map((row) => ({
            ...row,
            exchange_rate:
                rebaseList.value.find((rate) => rate.code === row.code)
                    ?.exchange_rate ?? row.exchange_rate,
            face_value:
                rebaseList.value.find((rate) => rate.code === row.code)
                    ?.face_value ?? row.face_value,
        })),
        rebaseBase.value,
    );
}

async function rebase(): Promise<void> {
    busy.value = true;
    rebaseError.value = '';
    try {
        await apiJson(
            '/organization/reference-settings/currencies/rebase',
            'POST',
            {
                base_code: rebaseBase.value,
                rates: rebaseList.value,
            },
        );
        rebaseOpen.value = false;
        message.value = t('Saved.');
        await load();
    } catch (failure) {
        rebaseError.value = Object.values(
            collectErrors(failure, t('Could not save.')),
        )[0];
    } finally {
        busy.value = false;
    }
}

function collectErrors(
    failure: unknown,
    fallback: string,
): Record<string, string> {
    if (failure instanceof ApiError) {
        const fields = failure.fieldErrors();

        return Object.keys(fields).length ? fields : { form: failure.message };
    }

    return { form: fallback };
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
            :rows="tableRows"
            :row-key="(row) => row.id as number"
            :row-label="(row) => String(row.name ?? row.prefix ?? row.id)"
            :add-label="config.addLabel"
            searchable
            can-edit
            @add="openForm()"
            @edit="editSelected"
            @delete="archive"
        >
            <template #cell-active="{ row }">
                <Badge
                    :variant="
                        isActive(row as ReferenceRecord)
                            ? 'secondary'
                            : 'outline'
                    "
                    >{{
                        isActive(row as ReferenceRecord)
                            ? t('Active')
                            : t('Archived')
                    }}</Badge
                >
            </template>
        </CrmSettingsTable>
        <div
            v-if="config.kind === 'currencies' && !loadError"
            class="flex items-center gap-3 text-sm"
        >
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="!activeCurrencies.length"
                @click="openRebase"
                >{{ t('Change base currency') }}</Button
            >
            <span class="text-muted-foreground">{{
                t('Supply every rate against the new base in one step.')
            }}</span>
        </div>
        <p v-if="loading" class="text-muted-foreground text-sm">
            {{ t('Loading…') }}
        </p>

        <Dialog v-model:open="rebaseOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ t('Change base currency') }}</DialogTitle>
                    <DialogDescription>{{
                        t(
                            'The base currency has rate 1. Enter how much of each other currency equals the face value in the new base.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="rebase">
                    <InputError :message="rebaseError" />
                    <div class="space-y-1">
                        <Label for="rb-base">{{
                            t('New base currency')
                        }}</Label>
                        <select
                            id="rb-base"
                            v-model="rebaseBase"
                            class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                            @change="pickBase"
                        >
                            <option
                                v-for="row in activeCurrencies"
                                :key="row.id"
                                :value="String(row.code)"
                            >
                                {{ row.code }} — {{ row.name }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-for="rate in rebaseList"
                        :key="rate.code"
                        class="grid grid-cols-[4rem_1fr_6rem] items-center gap-2 text-sm"
                    >
                        <span class="font-medium">{{ rate.code }}</span>
                        <Input
                            v-model="rate.exchange_rate"
                            inputmode="decimal"
                            :disabled="rate.code === rebaseBase"
                            :aria-label="`${rate.code} ${t('Rate')}`"
                        />
                        <Input
                            v-model.number="rate.face_value"
                            type="number"
                            min="1"
                            :disabled="rate.code === rebaseBase"
                            :aria-label="`${rate.code} ${t('Face value')}`"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="rebaseOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="busy">{{
                            t('Save')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

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
                    <template v-if="config.kind === 'currencies'">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1">
                                <Label for="ref-code">{{ t('Code') }}</Label>
                                <Input
                                    id="ref-code"
                                    v-model="form.code"
                                    maxlength="3"
                                    :disabled="!!editing"
                                    placeholder="AED"
                                />
                                <InputError :message="errors.code" />
                            </div>
                            <div class="space-y-1">
                                <Label for="ref-name">{{ t('Name') }}</Label>
                                <Input id="ref-name" v-model="form.name" />
                                <InputError :message="errors.name" />
                            </div>
                            <div class="space-y-1">
                                <Label for="ref-rate">{{
                                    t('Rate to base')
                                }}</Label>
                                <Input
                                    id="ref-rate"
                                    v-model="form.exchange_rate"
                                    inputmode="decimal"
                                />
                                <InputError :message="errors.exchange_rate" />
                            </div>
                            <div class="space-y-1">
                                <Label for="ref-face">{{
                                    t('Face value')
                                }}</Label>
                                <Input
                                    id="ref-face"
                                    v-model.number="form.face_value"
                                    type="number"
                                    min="1"
                                />
                                <InputError :message="errors.face_value" />
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm"
                            ><input v-model="form.is_base" type="checkbox" />{{
                                t('Base currency')
                            }}</label
                        >
                        <InputError :message="errors.is_base" />
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.is_reporting"
                                type="checkbox"
                            />{{ t('Reporting currency') }}</label
                        >
                        <InputError :message="errors.is_reporting" />
                    </template>
                    <template v-else-if="config.kind === 'locations'">
                        <div class="space-y-1">
                            <Label for="ref-name">{{ t('Name') }}</Label>
                            <Input id="ref-name" v-model="form.name" />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="space-y-1">
                            <Label for="ref-type">{{ t('Type') }}</Label>
                            <select
                                id="ref-type"
                                v-model="form.type"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                @change="form.parent_id = null"
                            >
                                <option value="country">
                                    {{ t('Country') }}
                                </option>
                                <option value="region">
                                    {{ t('Region') }}
                                </option>
                                <option value="city">{{ t('City') }}</option>
                            </select>
                            <InputError :message="errors.type" />
                        </div>
                        <div v-if="form.type !== 'country'" class="space-y-1">
                            <Label for="ref-parent">{{
                                t('Belongs to')
                            }}</Label>
                            <select
                                id="ref-parent"
                                v-model.number="form.parent_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                            >
                                <option :value="null">—</option>
                                <option
                                    v-for="parent in parents"
                                    :key="parent.id"
                                    :value="parent.id"
                                >
                                    {{ parent.name }}
                                </option>
                            </select>
                            <InputError :message="errors.parent_id" />
                        </div>
                    </template>
                    <template v-else>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="space-y-1">
                                <Label for="ref-prefix">{{
                                    t('Prefix')
                                }}</Label>
                                <Input id="ref-prefix" v-model="form.prefix" />
                                <InputError :message="errors.prefix" />
                            </div>
                            <div class="space-y-1">
                                <Label for="ref-padding">{{
                                    t('Digits')
                                }}</Label>
                                <Input
                                    id="ref-padding"
                                    v-model.number="form.padding"
                                    type="number"
                                    min="1"
                                    max="12"
                                />
                                <InputError :message="errors.padding" />
                            </div>
                            <div class="space-y-1">
                                <Label for="ref-next">{{
                                    t('Next number')
                                }}</Label>
                                <Input
                                    id="ref-next"
                                    v-model.number="form.next_number"
                                    type="number"
                                    min="1"
                                />
                                <InputError :message="errors.next_number" />
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.include_year"
                                type="checkbox"
                            />{{ t('Include the year') }}</label
                        >
                        <p class="text-muted-foreground text-sm">
                            {{ t('Preview') }}:
                            <span class="font-mono">{{
                                numberPreview(
                                    String(form.prefix ?? ''),
                                    Number(form.padding ?? 1),
                                    Number(form.next_number ?? 1),
                                    !!form.include_year,
                                    new Date().getFullYear(),
                                )
                            }}</span>
                        </p>
                        <InputError :message="errors.kind" />
                    </template>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="form.active" type="checkbox" />{{
                            t('Active')
                        }}</label
                    >
                    <InputError :message="errors.active" />
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
