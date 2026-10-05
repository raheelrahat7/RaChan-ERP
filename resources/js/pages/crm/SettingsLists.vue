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
    SELECTION_LISTS,
    codeFromName,
    listLabel,
    nextPosition,
    optionsFor,
} from '@/lib/crm-lists';
import type { SelectionOption } from '@/lib/crm-lists';
import type { DataTableColumn } from '@/lib/data-table';

type Row = {
    id: number;
    position: number;
    name: string;
    code: string;
    status: string;
};

const { t } = useLocale();
const options = ref<SelectionOption[]>([]);
const list = ref(SELECTION_LISTS[0].key);
const loading = ref(true);
const loadError = ref('');
const message = ref('');
const open = ref(false);
const editing = ref<SelectionOption | null>(null);
const form = ref({ name: '', position: 1, code: '', active: true });
const errors = ref<Record<string, string>>({});
const busy = ref(false);

const current = computed(() => optionsFor(list.value, options.value));
const isCategory = computed(() => list.value === 'deal_categories');
const active = (option: SelectionOption): boolean =>
    option.active === true || option.active === 1;
const rows = computed<Row[]>(() =>
    current.value.map((option) => ({
        id: option.id,
        position: option.position,
        name: option.name,
        code: option.code ?? '',
        status: active(option) ? t('Active') : t('Archived'),
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'position', label: t('Sorting'), sortable: true },
    { key: 'name', label: t('Name'), sortable: true },
    ...(isCategory.value ? [{ key: 'code' as const, label: t('Code') }] : []),
    { key: 'status', label: t('Status') },
]);

async function load(): Promise<void> {
    try {
        const data = await apiJson<{ options: SelectionOption[] }>(
            '/crm/settings/data',
        );
        options.value = data.options;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load these lists.');
    } finally {
        loading.value = false;
    }
}

function openForm(option?: SelectionOption): void {
    editing.value = option ?? null;
    form.value = {
        name: option?.name ?? '',
        position: option?.position ?? nextPosition(current.value),
        code: option?.code ?? '',
        active: option ? active(option) : true,
    };
    errors.value = {};
    open.value = true;
}

function payload(base: typeof form.value): Record<string, unknown> {
    const data: Record<string, unknown> = {
        list_key: list.value,
        name: base.name.trim(),
        position: Number(base.position) || 0,
        active: base.active,
    };
    if (isCategory.value) {
        data.code =
            editing.value?.code ?? (base.code || codeFromName(base.name));
    }

    return data;
}

async function send(
    option: SelectionOption | null,
    data: Record<string, unknown>,
): Promise<void> {
    await apiJson(
        option ? `/crm/settings/options/${option.id}` : '/crm/settings/options',
        option ? 'PUT' : 'POST',
        data,
    );
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await send(editing.value, payload(form.value));
        open.value = false;
        message.value = t('Saved.');
        await load();
    } catch (failure) {
        errors.value =
            failure instanceof ApiError
                ? Object.keys(failure.fieldErrors()).length
                    ? failure.fieldErrors()
                    : { form: failure.message }
                : { form: t('Could not save.') };
    } finally {
        busy.value = false;
    }
}

/** Options are archived rather than removed so existing records keep their value. */
async function archive(keys: (string | number)[]): Promise<void> {
    message.value = '';
    for (const key of keys) {
        const option = options.value.find((item) => item.id === key);
        if (option && active(option)) {
            try {
                await send(option, {
                    list_key: option.list_key,
                    name: option.name,
                    position: option.position,
                    active: false,
                    ...(option.code ? { code: option.code } : {}),
                });
            } catch (failure) {
                message.value =
                    failure instanceof ApiError
                        ? failure.message
                        : t('Could not archive.');
            }
        }
    }
    await load();
}

function editSelected(keys: (string | number)[]): void {
    const option = options.value.find((item) => item.id === keys[0]);
    if (option) {
        openForm(option);
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('Selection lists')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <Link
            href="/crm/settings"
            class="text-muted-foreground text-sm underline"
            >{{ t('Back to CRM settings') }}</Link
        >
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <p v-if="message" role="status" class="text-sm">{{ message }}</p>

        <div class="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
            <nav :aria-label="t('Selection lists')">
                <ul class="space-y-1">
                    <li v-for="item in SELECTION_LISTS" :key="item.key">
                        <button
                            type="button"
                            class="hover:bg-muted flex w-full items-center justify-between rounded-md px-3 py-2 text-start text-sm"
                            :class="
                                item.key === list ? 'bg-muted font-medium' : ''
                            "
                            :aria-current="
                                item.key === list ? 'true' : undefined
                            "
                            @click="list = item.key"
                        >
                            {{ t(item.label) }}
                            <Badge variant="outline">{{
                                optionsFor(item.key, options).length
                            }}</Badge>
                        </button>
                    </li>
                </ul>
            </nav>
            <CrmSettingsTable
                :title="listLabel(list)"
                :columns="columns"
                :rows="rows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                add-label="Add option"
                searchable
                can-edit
                @add="openForm()"
                @edit="editSelected"
                @delete="archive"
            />
        </div>
        <p
            v-if="!loading && !current.length && isCategory"
            class="text-muted-foreground text-xs"
        >
            {{
                t('Until you add a category, the built-in categories are used.')
            }}
        </p>

        <Dialog v-model:open="open">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? t('Edit') : t('Add option')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t(listLabel(list))
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="save">
                    <InputError :message="errors.form" />
                    <div class="space-y-1">
                        <Label for="opt-name">{{ t('Name') }}</Label>
                        <Input id="opt-name" v-model="form.name" />
                        <InputError :message="errors.name" />
                    </div>
                    <div v-if="isCategory && !editing" class="space-y-1">
                        <Label for="opt-code">{{ t('Code') }}</Label>
                        <Input
                            id="opt-code"
                            v-model="form.code"
                            :placeholder="codeFromName(form.name)"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{
                                t(
                                    'Lowercase letters, numbers and underscores. It cannot change later.',
                                )
                            }}
                        </p>
                        <InputError :message="errors.code" />
                    </div>
                    <div class="space-y-1">
                        <Label for="opt-pos">{{ t('Sorting') }}</Label>
                        <Input
                            id="opt-pos"
                            v-model.number="form.position"
                            type="number"
                            min="0"
                        />
                        <InputError :message="errors.position" />
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
