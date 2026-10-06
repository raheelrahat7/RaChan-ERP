<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { uuid } from '@/lib/uuid';

const { t } = useLocale();
const props = defineProps<{
    root: {
        id: number;
        name: string;
        archived_at: string | null;
        archive_reason: string | null;
    };
    canEdit: boolean;
    versions: {
        id: number;
        name: string;
        mime_type: string;
        size: number;
        version_number: number;
        version_reason: string | null;
        created_at: string;
    }[];
}>();
const upload = useForm({
    file: null as File | null,
    reason: '',
    version_key: uuid(),
});
const lifecycle = useForm({ reason: '' });
function select(event: Event): void {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function save(): void {
    upload.post(`/documents/${props.root.id}/versions`, {
        preserveScroll: true,
        onSuccess: () => {
            upload.reset();
            upload.version_key = uuid();
            addOpen.value = false;
        },
    });
}
function archive(): void {
    lifecycle.post(
        `/documents/${props.root.id}/${props.root.archived_at ? 'restore' : 'archive'}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                lifecycle.reset();
                archiveOpen.value = false;
            },
        },
    );
}
const addOpen = ref(false);
const archiveOpen = ref(false);
const PREVIEWABLE = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
];
type Row = {
    id: number;
    version: string;
    name: string;
    size: string;
    created: string;
    reason: string;
    actions: string;
};
const rows = computed<Row[]>(() =>
    props.versions.map((version) => ({
        id: version.id,
        version: `${t('Version')} ${version.version_number}`,
        name: version.name,
        size: `${Math.ceil(version.size / 1024)} ${t('KB')}`,
        created: version.created_at,
        reason: version.version_reason ?? '',
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'version', label: t('Version'), sortable: true },
    { key: 'name', label: t('Name') },
    { key: 'size', label: t('Size'), align: 'end' },
    { key: 'created', label: t('Created'), sortable: true },
    { key: 'reason', label: t('Version reason') },
    { key: 'actions', label: '' },
]);
const versionById = (id: number) =>
    props.versions.find((item) => item.id === id)!;
</script>

<template>
    <Head :title="t('Document versions')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :translate="false"
            :title="root.name"
            :description="t('Private document versions and lifecycle history.')"
        >
            <template #actions>
                <Button
                    v-if="canEdit"
                    type="button"
                    variant="outline"
                    @click="archiveOpen = true"
                    >{{
                        root.archived_at
                            ? t('Restore document')
                            : t('Archive document')
                    }}</Button
                >
                <Link href="/dashboard" class="text-sm underline">{{
                    t('Dashboard')
                }}</Link>
            </template>
        </PageHeader>
        <Card v-if="root.archived_at">
            <CardContent class="flex flex-wrap items-center gap-2 text-sm">
                <Badge variant="outline">{{ t('Archived') }}</Badge
                >{{ root.archived_at }} · {{ root.archive_reason }}
            </CardContent>
        </Card>

        <CrmSettingsTable
            :show-title="false"
            title="Preserved versions"
            add-label="Add a version"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.version"
            :selectable="false"
            :can-edit="canEdit && !root.archived_at"
            @add="addOpen = true"
        >
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-2">
                    <Button as-child size="sm" variant="outline"
                        ><a :href="`/documents/${row.id}/download`">{{
                            t('Download')
                        }}</a></Button
                    >
                    <Button
                        v-if="
                            PREVIEWABLE.includes(versionById(row.id).mime_type)
                        "
                        as-child
                        size="sm"
                        variant="ghost"
                        ><a
                            :href="`/documents/${row.id}/preview`"
                            target="_blank"
                            rel="noopener noreferrer"
                            >{{ t('Preview') }}</a
                        ></Button
                    >
                </div>
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="addOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Add a version')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t('Private document versions and lifecycle history.')
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="version-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="save"
                >
                    <div class="space-y-1">
                        <Label for="dv-file">{{ t('File') }}</Label
                        ><input
                            id="dv-file"
                            type="file"
                            required
                            class="block w-full text-sm"
                            @change="select"
                        /><InputError :message="upload.errors.file" />
                    </div>
                    <div class="space-y-1">
                        <Label for="dv-reason">{{ t('Version reason') }}</Label
                        ><Input
                            id="dv-reason"
                            v-model="upload.reason"
                            required
                            maxlength="2000"
                        /><InputError :message="upload.errors.reason" />
                    </div>
                    <InputError :message="upload.errors.version_key" />
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="version-form"
                        :disabled="upload.processing"
                        >{{ t('Preserve new version') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="addOpen = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <Dialog v-model:open="archiveOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        root.archived_at
                            ? t('Restore document')
                            : t('Archive document')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t(
                            'Archiving retains every version and file. Permanent deletion is disabled.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="archive">
                    <div class="space-y-1">
                        <Label for="da-reason">{{ t('Reason') }}</Label
                        ><Input
                            id="da-reason"
                            v-model="lifecycle.reason"
                            required
                            maxlength="2000"
                        /><InputError :message="lifecycle.errors.reason" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="archiveOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button :disabled="lifecycle.processing">{{
                            root.archived_at ? t('Restore') : t('Archive')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
