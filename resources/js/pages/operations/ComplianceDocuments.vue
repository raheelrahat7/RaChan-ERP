<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

type Entity = { id: number; name?: string; reference?: string };
type Row = {
    id: number;
    name: string;
    category: string;
    expires_on: string;
};

const props = defineProps<{
    documents: {
        id: number;
        name: string;
        category: string;
        expires_on: string | null;
        is_expiring: boolean;
    }[];
    properties: Entity[];
    leases: Entity[];
    tenants: Entity[];
    vendors: Entity[];
    canManage: boolean;
}>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const open = ref(false);
const subjectType = ref('property');
const entities = computed(
    () =>
        ({
            property: props.properties,
            lease: props.leases,
            tenant: props.tenants,
            vendor: props.vendors,
        })[subjectType.value] ?? [],
);
const form = useForm({
    subject_type: 'property',
    subject_id: '',
    category: '',
    expires_on: '',
    file: null as File | null,
});
const rows = computed<Row[]>(() =>
    props.documents.map((document) => ({
        id: document.id,
        name: document.name,
        category: document.category,
        expires_on: document.expires_on ?? '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'name', label: t('Document'), sortable: true },
    { key: 'category', label: t('Category'), sortable: true },
    { key: 'expires_on', label: t('Expires'), sortable: true },
]);
const expiring = (id: number): boolean =>
    props.documents.find((document) => document.id === id)?.is_expiring ===
    true;

function upload(): void {
    form.subject_type = subjectType.value;
    form.post('/compliance-documents', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('Document compliance')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Document compliance"
            description="Secure documents and expiry alerts for property, leases, tenants, and vendors."
        />
        <CrmSettingsTable
            :show-title="false"
            title="Documents"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            add-label="Upload compliance document"
            :selectable="false"
            searchable
            :can-edit="canManage"
            @add="open = true"
        >
            <template #cell-name="{ row }">
                <a
                    :href="`/compliance-documents/${row.id}/download`"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    >{{ row.name }}</a
                >
            </template>
            <template #cell-expires_on="{ row }">
                <Badge v-if="expiring(row.id)" variant="outline" class="me-2">{{
                    t('Expiring')
                }}</Badge>
                <span
                    :class="
                        expiring(row.id)
                            ? 'text-amber-600'
                            : 'text-muted-foreground'
                    "
                    >{{ row.expires_on || t('No expiry') }}</span
                >
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Upload compliance document')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Secure documents and expiry alerts for property, leases, tenants, and vendors.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="compliance-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="upload"
                >
                    <div class="space-y-1">
                        <Label for="cd-type">{{ t('Applies to') }}</Label>
                        <select
                            id="cd-type"
                            v-model="subjectType"
                            :class="selectClass"
                            @change="form.subject_id = ''"
                        >
                            <option value="property">
                                {{ t('Property') }}
                            </option>
                            <option value="lease">{{ t('Lease') }}</option>
                            <option value="tenant">{{ t('Tenant') }}</option>
                            <option value="vendor">{{ t('Vendor') }}</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="cd-record">{{ t('Record') }}</Label>
                        <select
                            id="cd-record"
                            v-model="form.subject_id"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="entity in entities"
                                :key="entity.id"
                                :value="String(entity.id)"
                            >
                                {{ entity.name || entity.reference }}
                            </option>
                        </select>
                        <InputError :message="form.errors.subject_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="cd-category">{{ t('Category') }}</Label
                        ><Input
                            id="cd-category"
                            v-model="form.category"
                            required
                        /><InputError :message="form.errors.category" />
                    </div>
                    <div class="space-y-1">
                        <Label for="cd-expires">{{ t('Expires') }}</Label
                        ><Input
                            id="cd-expires"
                            v-model="form.expires_on"
                            type="date"
                        /><InputError :message="form.errors.expires_on" />
                    </div>
                    <div class="space-y-1">
                        <Label for="cd-file">{{ t('File') }}</Label>
                        <input
                            id="cd-file"
                            type="file"
                            required
                            class="block w-full text-sm"
                            @change="
                                form.file =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] || null
                            "
                        />
                        <InputError :message="form.errors.file" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="compliance-form"
                        :disabled="form.processing"
                        >{{ t('Upload') }}</Button
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
