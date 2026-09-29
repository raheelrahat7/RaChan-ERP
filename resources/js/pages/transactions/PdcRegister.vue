<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useLocale } from '@/composables/useLocale';
import type { DataTableColumn } from '@/lib/data-table';

type ChequeStatus =
    | 'scheduled'
    | 'deposited'
    | 'cleared'
    | 'bounced'
    | 'replaced';
type Cheque = {
    id: number;
    lease_id: number;
    lease_reference: string;
    cheque_number: string;
    bank_name: string;
    payer_name: string;
    amount: string;
    due_on: string;
    status: ChequeStatus;
    deposited_on: string | null;
    cleared_on: string | null;
    bounced_on: string | null;
    bounce_reason: string | null;
};

const props = defineProps<{
    cheques: {
        data: Cheque[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    filters: {
        status: ChequeStatus | null;
        from: string | null;
        to: string | null;
    };
    canManage: boolean;
    actionRoutePattern: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Cheques (PDC)', href: '/transactions/pdc' }],
    },
});

const { t } = useLocale();

const filterForm = reactive({
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
function applyFilters(): void {
    router.get(
        '/transactions/pdc',
        {
            status: filterForm.status || undefined,
            from: filterForm.from || undefined,
            to: filterForm.to || undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}

const columns: DataTableColumn<Cheque>[] = [
    { key: 'cheque_number', label: 'Cheque #' },
    { key: 'lease_reference', label: 'Lease' },
    { key: 'payer_name', label: 'Payer' },
    { key: 'bank_name', label: 'Bank' },
    { key: 'amount', label: 'Amount', align: 'end' },
    { key: 'due_on', label: 'Due' },
    { key: 'status', label: 'Status' },
    { key: 'id', label: '', align: 'end' },
];

function actionUrl(
    cheque: Cheque,
    action: 'deposit' | 'clear' | 'bounce',
): string {
    return props.actionRoutePattern
        .replace('{id}', String(cheque.id))
        .replace('{action}', action);
}

const actioning = ref<{
    cheque: Cheque;
    action: 'deposit' | 'clear' | 'bounce';
} | null>(null);
const today = new Date().toISOString().slice(0, 10);
const actionForm = useForm({ occurred_on: today, reason: '' });

function openAction(
    cheque: Cheque,
    action: 'deposit' | 'clear' | 'bounce',
): void {
    actionForm.reset();
    actionForm.occurred_on = today;
    actioning.value = { cheque, action };
}

function submitAction(): void {
    if (!actioning.value) {
        return;
    }
    actionForm
        .transform((data) =>
            actioning.value?.action === 'bounce'
                ? data
                : { occurred_on: data.occurred_on },
        )
        .post(actionUrl(actioning.value.cheque, actioning.value.action), {
            preserveScroll: true,
            onSuccess: () => (actioning.value = null),
        });
}

function actionLabel(action: 'deposit' | 'clear' | 'bounce'): string {
    return action === 'deposit'
        ? 'Deposit'
        : action === 'clear'
          ? 'Clear'
          : 'Bounce';
}
</script>

<template>
    <Head :title="t('Cheques (PDC)')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Finance" title="Cheques (PDC)" />

        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyFilters"
        >
            <div class="flex flex-col gap-1.5">
                <Label>{{ t('Status') }}</Label>
                <Select
                    :model-value="filterForm.status"
                    @update:model-value="
                        (value) => {
                            filterForm.status = (value as string) ?? '';
                            applyFilters();
                        }
                    "
                >
                    <SelectTrigger class="w-40"
                        ><SelectValue :placeholder="t('All statuses')"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="">{{
                            t('All statuses')
                        }}</SelectItem>
                        <SelectItem value="scheduled">{{
                            t('scheduled')
                        }}</SelectItem>
                        <SelectItem value="deposited">{{
                            t('deposited')
                        }}</SelectItem>
                        <SelectItem value="cleared">{{
                            t('cleared')
                        }}</SelectItem>
                        <SelectItem value="bounced">{{
                            t('bounced')
                        }}</SelectItem>
                        <SelectItem value="replaced">{{
                            t('replaced')
                        }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="pdc-from">{{ t('From') }}</Label>
                <Input
                    id="pdc-from"
                    v-model="filterForm.from"
                    type="date"
                    @change="applyFilters"
                />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="pdc-to">{{ t('To') }}</Label>
                <Input
                    id="pdc-to"
                    v-model="filterForm.to"
                    type="date"
                    @change="applyFilters"
                />
            </div>
        </form>

        <DataTable
            :columns="columns"
            :rows="cheques.data"
            :row-key="(row) => row.id"
            :row-label="(row) => row.cheque_number"
            empty-title="No cheques match these filters."
        >
            <template #cell-amount="{ row }"
                ><Money :value="row.amount"
            /></template>
            <template #cell-due_on="{ row }"
                ><DateText :value="row.due_on"
            /></template>
            <template #cell-status="{ row }"
                ><StatusDot :status="row.status"
            /></template>
            <template #cell-id="{ row }">
                <div
                    v-if="canManage && row.status === 'scheduled'"
                    class="flex justify-end gap-2"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="openAction(row, 'deposit')"
                        >{{ t('Deposit') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="destructive-outline"
                        @click="openAction(row, 'bounce')"
                        >{{ t('Bounce') }}</Button
                    >
                </div>
                <div
                    v-else-if="canManage && row.status === 'deposited'"
                    class="flex justify-end gap-2"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="openAction(row, 'clear')"
                        >{{ t('Clear') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="destructive-outline"
                        @click="openAction(row, 'bounce')"
                        >{{ t('Bounce') }}</Button
                    >
                </div>
            </template>
        </DataTable>
        <Pagination :links="cheques.links" />

        <Dialog
            :open="actioning !== null"
            @update:open="(value) => !value && (actioning = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t(
                            actioning
                                ? actionLabel(actioning.action) + ' cheque'
                                : '',
                        )
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitAction"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="action-date">{{ t('Occurred on') }}</Label>
                        <Input
                            id="action-date"
                            v-model="actionForm.occurred_on"
                            type="date"
                        />
                        <InputError :message="actionForm.errors.occurred_on" />
                    </div>
                    <div
                        v-if="actioning?.action === 'bounce'"
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="action-reason">{{ t('Reason') }}</Label>
                        <Textarea
                            id="action-reason"
                            v-model="actionForm.reason"
                        />
                        <InputError :message="actionForm.errors.reason" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="actioning = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="actionForm.processing"
                            >{{ t('Confirm') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
