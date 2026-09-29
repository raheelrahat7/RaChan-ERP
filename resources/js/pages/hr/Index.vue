<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
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

type Staff = {
    id: number;
    user_id: number;
    name: string;
    job_title: string | null;
    hired_on: string | null;
    status: string;
};
type StaffDocument = {
    id: number;
    staff_id: number;
    type: 'emirates_id' | 'visa' | 'rera_card' | 'passport' | 'other';
    expires_on: string;
    reference_suffix: string | null;
    notes: string | null;
};
type LeaveRequest = {
    id: number;
    staff_id: number;
    starts_on: string;
    ends_on: string;
    type: 'annual' | 'sick' | 'unpaid' | 'other';
    reason: string | null;
    status: 'submitted' | 'approved' | 'rejected';
    decision_reason: string | null;
};

const props = defineProps<{
    staff: Staff[];
    documents: StaffDocument[];
    leaveRequests: LeaveRequest[];
    members: { id: number; name: string }[];
    canManage: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'HR & Staff Services', href: '/hr' }] },
});

const { t } = useLocale();

function staffName(id: number): string {
    return props.staff.find((row) => row.id === id)?.name ?? String(id);
}

const staffColumns: DataTableColumn<Staff>[] = [
    { key: 'name', label: 'Name' },
    { key: 'job_title', label: 'Job title' },
    { key: 'hired_on', label: 'Hired' },
    { key: 'status', label: 'Status' },
];
const staffDialogOpen = ref(false);
const staffForm = useForm({
    user_id: null as number | null,
    job_title: '',
    hired_on: '',
});
function openStaff(): void {
    staffForm.reset();
    staffDialogOpen.value = true;
}
function submitStaff(): void {
    staffForm.post('/hr/staff', {
        preserveScroll: true,
        onSuccess: () => (staffDialogOpen.value = false),
    });
}

const documentColumns: DataTableColumn<StaffDocument>[] = [
    { key: 'staff_id', label: 'Staff' },
    { key: 'type', label: 'Type' },
    { key: 'expires_on', label: 'Expires' },
    { key: 'reference_suffix', label: 'Reference' },
];
const documentDialogStaff = ref<Staff | null>(null);
const documentForm = useForm({
    type: 'emirates_id' as StaffDocument['type'],
    expires_on: '',
    reference_suffix: '',
    notes: '',
});
function openDocument(staff: Staff): void {
    documentForm.reset();
    documentDialogStaff.value = staff;
}
function submitDocument(): void {
    if (!documentDialogStaff.value) {
        return;
    }
    documentForm.post(`/hr/staff/${documentDialogStaff.value.id}/documents`, {
        preserveScroll: true,
        onSuccess: () => (documentDialogStaff.value = null),
    });
}

const leaveColumns = computed<DataTableColumn<LeaveRequest>[]>(() => [
    ...(props.canManage
        ? ([
              { key: 'staff_id', label: 'Staff' },
          ] as DataTableColumn<LeaveRequest>[])
        : []),
    { key: 'type', label: 'Type' },
    { key: 'starts_on', label: 'Starts' },
    { key: 'ends_on', label: 'Ends' },
    { key: 'status', label: 'Status' },
    { key: 'id', label: '', align: 'end' },
]);
const leaveDialogStaff = ref<Staff | null>(null);
const leaveForm = useForm({
    starts_on: '',
    ends_on: '',
    type: 'annual' as LeaveRequest['type'],
    reason: '',
});
function openLeave(staff: Staff): void {
    leaveForm.reset();
    leaveDialogStaff.value = staff;
}
function submitLeave(): void {
    if (!leaveDialogStaff.value) {
        return;
    }
    leaveForm.post(`/hr/staff/${leaveDialogStaff.value.id}/leave`, {
        preserveScroll: true,
        onSuccess: () => (leaveDialogStaff.value = null),
    });
}

const decidingLeave = ref<LeaveRequest | null>(null);
const decideForm = useForm({
    decision: 'approve' as 'approve' | 'reject',
    reason: '',
});
function openDecide(leave: LeaveRequest, decision: 'approve' | 'reject'): void {
    decideForm.reset();
    decideForm.decision = decision;
    decidingLeave.value = leave;
}
function submitDecide(): void {
    if (!decidingLeave.value) {
        return;
    }
    decideForm.post(`/hr/leave/${decidingLeave.value.id}/decide`, {
        preserveScroll: true,
        onSuccess: () => (decidingLeave.value = null),
    });
}
</script>

<template>
    <Head :title="t('HR & Staff Services')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="HR" title="HR & Staff Services" />

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Staff') }}
                </h2>
                <Button v-if="canManage" size="sm" @click="openStaff">{{
                    t('Add staff')
                }}</Button>
            </div>
            <DataTable
                :columns="staffColumns"
                :rows="staff"
                :row-key="(row) => row.id"
                :row-label="(row) => row.name"
                empty-title="No staff records yet."
            >
                <template #cell-job_title="{ row }">{{
                    row.job_title ?? '—'
                }}</template>
                <template #cell-hired_on="{ row }">
                    <DateText v-if="row.hired_on" :value="row.hired_on" />
                    <span v-else class="text-faint">—</span>
                </template>
                <template #cell-status="{ row }"
                    ><StatusDot :status="row.status"
                /></template>
            </DataTable>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Documents') }}
                </h2>
            </div>
            <DataTable
                :columns="documentColumns"
                :rows="documents"
                :row-key="(row) => row.id"
                empty-title="No documents recorded yet."
            >
                <template #cell-staff_id="{ row }">{{
                    staffName(row.staff_id)
                }}</template>
                <template #cell-type="{ row }">{{ t(row.type) }}</template>
                <template #cell-expires_on="{ row }"
                    ><DateText :value="row.expires_on"
                /></template>
                <template #cell-reference_suffix="{ row }">{{
                    row.reference_suffix ?? '—'
                }}</template>
            </DataTable>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-medium">
                    {{ t('Leave requests') }}
                </h2>
            </div>
            <DataTable
                :columns="leaveColumns"
                :rows="leaveRequests"
                :row-key="(row) => row.id"
                empty-title="No leave requests yet."
            >
                <template #cell-staff_id="{ row }">{{
                    staffName(row.staff_id)
                }}</template>
                <template #cell-type="{ row }">{{ t(row.type) }}</template>
                <template #cell-starts_on="{ row }"
                    ><DateText :value="row.starts_on"
                /></template>
                <template #cell-ends_on="{ row }"
                    ><DateText :value="row.ends_on"
                /></template>
                <template #cell-status="{ row }"
                    ><StatusDot :status="row.status"
                /></template>
                <template #cell-id="{ row }">
                    <div
                        v-if="canManage && row.status === 'submitted'"
                        class="flex justify-end gap-2"
                    >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="openDecide(row, 'approve')"
                            >{{ t('Approve') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="destructive-outline"
                            @click="openDecide(row, 'reject')"
                            >{{ t('Reject') }}</Button
                        >
                    </div>
                </template>
            </DataTable>
        </section>

        <section v-if="!canManage" class="flex flex-col gap-3">
            <div
                v-for="row in staff"
                :key="row.id"
                class="flex items-center justify-between"
            >
                <Button size="sm" @click="openLeave(row)">{{
                    t('Request leave')
                }}</Button>
            </div>
        </section>
        <section v-else class="flex flex-col gap-3">
            <p class="text-muted-foreground text-xs">
                {{
                    t(
                        'Use a staff row’s actions to add documents or request leave on their behalf.',
                    )
                }}
            </p>
            <div class="flex flex-wrap gap-2">
                <template v-for="row in staff" :key="row.id">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="openDocument(row)"
                        >{{ t('Add document') }} · {{ row.name }}</Button
                    >
                    <Button size="sm" variant="outline" @click="openLeave(row)"
                        >{{ t('Request leave') }} · {{ row.name }}</Button
                    >
                </template>
            </div>
        </section>

        <Dialog v-model:open="staffDialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Add staff')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submitStaff">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Member') }}</Label>
                        <Select
                            :model-value="
                                staffForm.user_id
                                    ? String(staffForm.user_id)
                                    : ''
                            "
                            @update:model-value="
                                staffForm.user_id = Number($event)
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in members"
                                    :key="member.id"
                                    :value="String(member.id)"
                                    >{{ member.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="staffForm.errors.user_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="staff-title"
                            >{{ t('Job title') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input id="staff-title" v-model="staffForm.job_title" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="staff-hired"
                            >{{ t('Hired on') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="staff-hired"
                            v-model="staffForm.hired_on"
                            type="date"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="staffDialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="staffForm.processing"
                            >{{ t('Add') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="documentDialogStaff !== null"
            @update:open="(value) => !value && (documentDialogStaff = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Add document')
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitDocument"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Type') }}</Label>
                        <Select v-model="documentForm.type">
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="emirates_id">{{
                                    t('emirates_id')
                                }}</SelectItem>
                                <SelectItem value="visa">{{
                                    t('visa')
                                }}</SelectItem>
                                <SelectItem value="rera_card">{{
                                    t('rera_card')
                                }}</SelectItem>
                                <SelectItem value="passport">{{
                                    t('passport')
                                }}</SelectItem>
                                <SelectItem value="other">{{
                                    t('other')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="doc-expires">{{ t('Expires on') }}</Label>
                        <Input
                            id="doc-expires"
                            v-model="documentForm.expires_on"
                            type="date"
                        />
                        <InputError :message="documentForm.errors.expires_on" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="doc-reference"
                            >{{ t('Reference') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="doc-reference"
                            v-model="documentForm.reference_suffix"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="doc-notes"
                            >{{ t('Notes') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Textarea id="doc-notes" v-model="documentForm.notes" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="documentDialogStaff = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="documentForm.processing"
                            >{{ t('Add') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="leaveDialogStaff !== null"
            @update:open="(value) => !value && (leaveDialogStaff = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Request leave')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submitLeave">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Type') }}</Label>
                        <Select v-model="leaveForm.type">
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="annual">{{
                                    t('annual')
                                }}</SelectItem>
                                <SelectItem value="sick">{{
                                    t('sick')
                                }}</SelectItem>
                                <SelectItem value="unpaid">{{
                                    t('unpaid')
                                }}</SelectItem>
                                <SelectItem value="other">{{
                                    t('other')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="leave-starts">{{
                                t('Starts on')
                            }}</Label>
                            <Input
                                id="leave-starts"
                                v-model="leaveForm.starts_on"
                                type="date"
                            />
                            <InputError :message="leaveForm.errors.starts_on" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="leave-ends">{{ t('Ends on') }}</Label>
                            <Input
                                id="leave-ends"
                                v-model="leaveForm.ends_on"
                                type="date"
                            />
                            <InputError :message="leaveForm.errors.ends_on" />
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="leave-reason"
                            >{{ t('Reason') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Textarea
                            id="leave-reason"
                            v-model="leaveForm.reason"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="leaveDialogStaff = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="leaveForm.processing"
                            >{{ t('Submit') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="decidingLeave !== null"
            @update:open="(value) => !value && (decidingLeave = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t(
                            decideForm.decision === 'approve'
                                ? 'Approve leave'
                                : 'Reject leave',
                        )
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitDecide"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="decide-reason"
                            >{{ t('Reason') }}
                            <span
                                v-if="decideForm.decision === 'approve'"
                                class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Textarea
                            id="decide-reason"
                            v-model="decideForm.reason"
                        />
                        <InputError :message="decideForm.errors.reason" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="decidingLeave = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="decideForm.processing"
                            >{{ t('Confirm') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
