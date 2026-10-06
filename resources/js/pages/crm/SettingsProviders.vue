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
    capabilityLabel,
    providerBody,
    providerForm,
    settingsSummary,
} from '@/lib/crm-providers';
import type { ProviderForm, ProviderRecord } from '@/lib/crm-providers';
import type { DataTableColumn } from '@/lib/data-table';

type Row = {
    id: number;
    name: string;
    capability: string;
    provider: string;
    details: string;
    status: string;
};

const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const records = ref<ProviderRecord[]>([]);
const capabilities = ref<string[]>([]);
const loading = ref(true);
const loadError = ref('');
const message = ref('');
const open = ref(false);
const editing = ref<ProviderRecord | null>(null);
const form = ref<ProviderForm>(providerForm([]));
const errors = ref<Record<string, string>>({});
const busy = ref(false);

const rows = computed<Row[]>(() =>
    records.value.map((record) => ({
        id: record.id,
        name: record.name,
        capability: t(capabilityLabel(record.capability)),
        provider: record.provider ?? '',
        details: settingsSummary(record.settings),
        status: t('Not connected'),
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'capability', label: t('Type'), sortable: true },
    { key: 'provider', label: t('Provider') },
    { key: 'details', label: t('Details') },
    { key: 'status', label: t('Status') },
]);

async function load(): Promise<void> {
    try {
        const data = await apiJson<{
            records: ProviderRecord[];
            providerCapabilities: string[];
        }>('/organization/reference-settings/providers');
        records.value = data.records;
        capabilities.value = data.providerCapabilities;
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

function openForm(record?: ProviderRecord): void {
    editing.value = record ?? null;
    form.value = providerForm(capabilities.value, record);
    errors.value = {};
    open.value = true;
}
function editSelected(keys: (string | number)[]): void {
    const record = records.value.find((item) => item.id === keys[0]);
    if (record) {
        openForm(record);
    }
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        const body = providerBody(form.value, editing.value?.version);
        const base = '/organization/reference-settings/providers';
        await apiJson(
            editing.value ? `${base}/${editing.value.id}` : base,
            editing.value ? 'PUT' : 'POST',
            body,
        );
        open.value = false;
        message.value = t('Saved.');
        await load();
    } catch (failure) {
        if (failure instanceof ApiError) {
            const found = failure.fieldErrors();
            errors.value = Object.keys(found).length
                ? found
                : { form: failure.message };
        } else {
            errors.value = { form: t('Could not save.') };
        }
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Head :title="t('Payment systems')" />
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
            :show-title="true"
            title="Payment systems"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            add-label="Add profile"
            searchable
            can-edit
            @add="openForm()"
            @edit="editSelected"
        >
            <template #cell-status="{ row }"
                ><Badge variant="outline">{{ row.status }}</Badge></template
            >
        </CrmSettingsTable>
        <p v-if="loading" class="text-muted-foreground text-sm">
            {{ t('Loading…') }}
        </p>
        <p class="text-muted-foreground text-xs">
            {{
                t(
                    'Profiles hold sender and print details only. No provider is connected and nothing is sent or charged from here.',
                )
            }}
        </p>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? t('Edit') : t('Add profile')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('Payment systems')
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="save">
                    <InputError :message="errors.form" />
                    <InputError :message="errors.expected_version" />
                    <InputError :message="errors.active" />
                    <div class="space-y-1">
                        <Label for="pr-name">{{ t('Name') }}</Label
                        ><Input
                            id="pr-name"
                            v-model="form.name"
                            required
                            maxlength="100"
                        /><InputError :message="errors.name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="pr-cap">{{ t('Type') }}</Label>
                        <select
                            id="pr-cap"
                            v-model="form.capability"
                            :class="selectClass"
                            :disabled="!!editing"
                        >
                            <option
                                v-for="capability in capabilities"
                                :key="capability"
                                :value="capability"
                            >
                                {{ t(capabilityLabel(capability)) }}
                            </option>
                        </select>
                        <InputError :message="errors.capability" />
                    </div>
                    <div class="space-y-1">
                        <Label for="pr-provider">{{
                            t('Provider (optional)')
                        }}</Label
                        ><Input
                            id="pr-provider"
                            v-model="form.provider"
                            maxlength="100"
                        /><InputError :message="errors.provider" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="pr-sender">{{ t('Sender') }}</Label
                            ><Input
                                id="pr-sender"
                                v-model="form.sender"
                                maxlength="100"
                            /><InputError
                                :message="errors['settings.sender']"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label for="pr-label">{{ t('Label') }}</Label
                            ><Input
                                id="pr-label"
                                v-model="form.label"
                                maxlength="100"
                            /><InputError :message="errors['settings.label']" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="pr-title">{{ t('Print title') }}</Label
                        ><Input
                            id="pr-title"
                            v-model="form.print_title"
                            maxlength="255"
                        /><InputError
                            :message="errors['settings.print_title']"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="pr-terms">{{ t('Terms') }}</Label>
                        <textarea
                            id="pr-terms"
                            v-model="form.terms"
                            rows="3"
                            maxlength="2000"
                            class="border-input bg-background w-full rounded-md border p-2 text-sm"
                        />
                        <InputError :message="errors['settings.terms']" />
                    </div>
                    <div class="space-y-1">
                        <Label for="pr-limit">{{ t('Daily limit') }}</Label
                        ><Input
                            id="pr-limit"
                            v-model="form.daily_limit"
                            type="number"
                            min="0"
                            max="100000"
                        /><InputError
                            :message="errors['settings.daily_limit']"
                        />
                    </div>
                    <p class="text-muted-foreground text-xs">
                        {{
                            t(
                                'Profiles stay inactive until a provider is selected.',
                            )
                        }}
                    </p>
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
