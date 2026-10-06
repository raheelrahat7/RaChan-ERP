<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
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
    documents: { id: number; name: string; version_number: number }[];
    requests: {
        data: {
            id: number;
            document_id: number;
            version_number: number;
            content_hash: string;
            signers: string[];
            status: string;
            reason: string;
            cancellation_reason: string | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
const signerText = ref('');
const form = useForm({
    document_id: '',
    signers: [] as string[],
    reason: '',
    operation_key: uuid(),
});
const cancellation = useForm({ signature_id: '', reason: '' });
function prepare(): void {
    form.signers = signerText.value
        .split('\n')
        .map((value) => value.trim())
        .filter(Boolean);
    form.post('/documents/signatures', {
        onSuccess: () => {
            form.reset();
            form.operation_key = uuid();
            signerText.value = '';
            prepareOpen.value = false;
        },
    });
}
function cancel(): void {
    cancellation.post(
        `/documents/signatures/${cancellation.signature_id}/cancel`,
        {
            onSuccess: () => {
                cancellation.reset();
                cancelOpen.value = false;
            },
        },
    );
}
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const textareaClass =
    'border-input bg-background w-full rounded-md border p-2 text-sm';
const prepareOpen = ref(false);
const cancelOpen = ref(false);
type Row = {
    id: number;
    request: string;
    document: string;
    signers: string;
    status: string;
    reason: string;
    hash: string;
    actions: string;
};
const rows = computed<Row[]>(() =>
    props.requests.data.map((request) => ({
        id: request.id,
        request: `#${request.id}`,
        document: `#${request.document_id} · ${t('Version')} ${request.version_number}`,
        signers: request.signers.join(', '),
        status: request.status,
        reason: request.cancellation_reason
            ? `${t('Cancelled')}: ${request.cancellation_reason}`
            : request.reason,
        hash: request.content_hash,
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'request', label: t('Request'), sortable: true },
    { key: 'document', label: t('Document') },
    { key: 'signers', label: t('Signers') },
    { key: 'status', label: t('Status') },
    { key: 'reason', label: t('Reason') },
    { key: 'actions', label: '' },
]);
const requestById = (id: number) =>
    props.requests.data.find((item) => item.id === id)!;
function startCancel(id: number): void {
    cancellation.reset();
    cancellation.signature_id = String(id);
    cancelOpen.value = true;
}
</script>

<template>
    <Head :title="t('Signature requests')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Signature requests"
            description="Prepare a signing request for an exact PDF version and preserve its audit history."
        >
            <template #actions>
                <Link href="/organization" class="text-sm underline">{{
                    t('Organization settings')
                }}</Link>
            </template>
        </PageHeader>
        <Card>
            <CardContent class="text-muted-foreground text-sm" role="status">
                {{
                    t(
                        'No signature provider is selected. Requests are prepared and validated locally. No invitations are sent and no document is marked signed. An owner may cancel a request with a reason.',
                    )
                }}
            </CardContent>
        </Card>

        <CrmSettingsTable
            :show-title="false"
            title="Signature requests"
            add-label="Prepare request"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.request"
            :selectable="false"
            searchable
            can-edit
            @add="prepareOpen = true"
        >
            <template #cell-document="{ row }">
                <Link
                    :href="`/documents/${requestById(row.id).document_id}/versions`"
                    class="text-primary underline-offset-2 hover:underline"
                    >{{ row.document }}</Link
                >
            </template>
            <template #cell-status="{ row }"
                ><Badge variant="secondary">{{ row.status }}</Badge></template
            >
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-2">
                    <Button
                        v-if="row.status === 'local_prepared'"
                        as-child
                        size="sm"
                        variant="outline"
                        ><a :href="`/documents/signatures/${row.id}/packet`">{{
                            t('Download packet')
                        }}</a></Button
                    >
                    <Button
                        v-if="row.status === 'local_prepared'"
                        size="sm"
                        variant="ghost"
                        @click="startCancel(row.id)"
                        >{{ t('Cancel request') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>
        <Pagination :links="requests.links" />

        <Sheet v-model:open="prepareOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Prepare request')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Prepare a signing request for an exact PDF version and preserve its audit history.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="sign-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="prepare"
                >
                    <div class="space-y-1">
                        <Label for="sg-doc">{{
                            t('PDF version (latest 100 documents)')
                        }}</Label>
                        <select
                            id="sg-doc"
                            v-model="form.document_id"
                            required
                            :class="selectClass"
                        >
                            <option value="">
                                {{ t('Select a document') }}
                            </option>
                            <option
                                v-for="document in documents"
                                :key="document.id"
                                :value="document.id"
                            >
                                #{{ document.id }} · {{ document.name }} ·
                                {{ t('Version') }} {{ document.version_number }}
                            </option>
                        </select>
                        <InputError :message="form.errors.document_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="sg-signers">{{
                            t('Signer emails, one per line (up to 10)')
                        }}</Label>
                        <textarea
                            id="sg-signers"
                            v-model="signerText"
                            required
                            rows="4"
                            :class="textareaClass"
                        />
                        <InputError :message="form.errors.signers" />
                    </div>
                    <div class="space-y-1">
                        <Label for="sg-reason">{{ t('Reason') }}</Label>
                        <textarea
                            id="sg-reason"
                            v-model="form.reason"
                            required
                            maxlength="2000"
                            rows="3"
                            :class="textareaClass"
                        />
                        <InputError :message="form.errors.reason" />
                    </div>
                    <InputError :message="form.errors.operation_key" />
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="sign-form"
                        :disabled="form.processing"
                        >{{ t('Prepare request') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="prepareOpen = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <Dialog v-model:open="cancelOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        t('Cancel prepared request')
                    }}</DialogTitle>
                    <DialogDescription
                        >#{{ cancellation.signature_id }}</DialogDescription
                    >
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="cancel">
                    <div class="space-y-1">
                        <Label for="sc-reason">{{ t('Reason') }}</Label>
                        <textarea
                            id="sc-reason"
                            v-model="cancellation.reason"
                            required
                            maxlength="2000"
                            rows="3"
                            :class="textareaClass"
                        />
                        <InputError :message="cancellation.errors.reason" />
                        <InputError
                            :message="cancellation.errors.signature_id"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="cancelOpen = false"
                            >{{ t('Close') }}</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="cancellation.processing"
                            >{{ t('Cancel request') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
