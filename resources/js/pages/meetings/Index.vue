<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPicker from '@/components/RecordPicker.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Badge } from '@/components/ui/badge';
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
import DateText from '@/components/DateText.vue';
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import { appointmentTimesValid } from '@/lib/sales-crm-tools';
import type { DataTableColumn } from '@/lib/data-table';

type Appointment = {
    id: number;
    type: 'meeting' | 'viewing';
    title: string;
    assigned_to: number;
    lead_id: number | null;
    listing_id: number | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    status: 'scheduled' | 'completed';
    outcome: string | null;
};

const props = defineProps<{
    appointments: {
        data: Appointment[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    members: { id: number; name: string }[];
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Meetings & Viewings', href: '/meetings' }],
    },
});

const { t } = useLocale();
const page = usePage();

const dialogOpen = ref(false);
const leadId = ref<number | null>(null);
const listingId = ref<number | null>(null);
const timeError = ref<string | null>(null);

const form = useForm({
    type: 'meeting' as 'meeting' | 'viewing',
    title: '',
    assigned_to: page.props.auth.user.id as number,
    starts_at: '',
    ends_at: '',
    location: '',
    lead_id: null as number | null,
    listing_id: null as number | null,
});

function openCreate(): void {
    leadId.value = null;
    listingId.value = null;
    timeError.value = null;
    form.reset();
    form.assigned_to = page.props.auth.user.id;
    dialogOpen.value = true;
}

function submit(): void {
    if (!appointmentTimesValid(form.starts_at, form.ends_at)) {
        timeError.value = t('The end time must be after the start time.');

        return;
    }
    timeError.value = null;
    form.lead_id = leadId.value;
    form.listing_id = listingId.value;
    form.post('/meetings', {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

const outcoming = ref<Appointment | null>(null);
const outcomeForm = useForm({ outcome: '' });

function submitOutcome(): void {
    if (!outcoming.value) {
        return;
    }
    outcomeForm.post(`/meetings/${outcoming.value.id}/outcome`, {
        preserveScroll: true,
        onSuccess: () => {
            outcoming.value = null;
            outcomeForm.reset();
        },
    });
}

function canRecordOutcome(appointment: Appointment): boolean {
    return (
        appointment.status === 'scheduled' &&
        (props.canManage || appointment.assigned_to === page.props.auth.user.id)
    );
}

function timeRange(appointment: Appointment): string {
    const start = new Date(appointment.starts_at);
    const end = new Date(appointment.ends_at);
    const time = (date: Date) => date.toISOString().slice(11, 16);

    return `${time(start)}–${time(end)}`;
}

const columns = computed<DataTableColumn<Appointment>[]>(() => [
    { key: 'type', label: 'Type' },
    { key: 'title', label: 'Title' },
    { key: 'starts_at', label: 'When' },
    { key: 'location', label: 'Location' },
    ...(props.canManage
        ? ([
              { key: 'assigned_to', label: 'Assignee' },
          ] as DataTableColumn<Appointment>[])
        : []),
    { key: 'status', label: 'Status' },
    { key: 'id', label: '', align: 'end' },
]);
</script>

<template>
    <Head :title="t('Meetings & Viewings')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Workflow" title="Meetings & Viewings">
            <template #actions
                ><Button @click="openCreate">{{
                    t('New meeting')
                }}</Button></template
            >
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="appointments.data"
            :row-key="(row) => row.id"
            :row-label="(row) => row.title"
            empty-title="No meetings or viewings scheduled."
        >
            <template #cell-type="{ row }">
                <Badge variant="outline">{{
                    t(row.type === 'meeting' ? 'Meeting' : 'Viewing')
                }}</Badge>
            </template>
            <template #cell-title="{ row }">
                <span class="font-medium">{{ row.title }}</span>
            </template>
            <template #cell-starts_at="{ row }">
                <DateText :value="row.starts_at" /> · {{ timeRange(row) }}
            </template>
            <template #cell-location="{ row }">{{
                row.location ?? '—'
            }}</template>
            <template #cell-assigned_to="{ row }">{{
                members.find((m) => m.id === row.assigned_to)?.name ?? '—'
            }}</template>
            <template #cell-status="{ row }"
                ><StatusDot :status="row.status"
            /></template>
            <template #cell-id="{ row }">
                <Button
                    v-if="canRecordOutcome(row)"
                    size="sm"
                    variant="outline"
                    @click="outcoming = row"
                    >{{ t('Record outcome') }}</Button
                >
            </template>
        </DataTable>
        <Pagination :links="appointments.links" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('New meeting')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Type') }}</Label>
                        <Select v-model="form.type">
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="meeting">{{
                                    t('Meeting')
                                }}</SelectItem>
                                <SelectItem value="viewing">{{
                                    t('Viewing')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="meeting-title">{{ t('Title') }}</Label>
                        <Input id="meeting-title" v-model="form.title" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div v-if="canManage" class="flex flex-col gap-1.5">
                        <Label>{{ t('Assignee') }}</Label>
                        <Select
                            :model-value="String(form.assigned_to)"
                            @update:model-value="
                                form.assigned_to = Number($event)
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
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="meeting-start">{{ t('Starts') }}</Label>
                            <Input
                                id="meeting-start"
                                v-model="form.starts_at"
                                type="datetime-local"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="meeting-end">{{ t('Ends') }}</Label>
                            <Input
                                id="meeting-end"
                                v-model="form.ends_at"
                                type="datetime-local"
                            />
                        </div>
                    </div>
                    <p v-if="timeError" class="text-destructive text-xs">
                        {{ timeError }}
                    </p>
                    <InputError :message="form.errors.ends_at" />
                    <div class="flex flex-col gap-1.5">
                        <Label for="meeting-location"
                            >{{ t('Location') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input id="meeting-location" v-model="form.location" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label
                            >{{ t('Lead') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <RecordPicker
                            type="lead"
                            v-model="leadId"
                            label="Search leads…"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label
                            >{{ t('Listing') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <RecordPicker
                            type="listing"
                            v-model="listingId"
                            label="Search listings…"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            t('Create')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="outcoming !== null"
            @update:open="(value) => !value && (outcoming = null)"
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('Record outcome')
                    }}</DialogTitle></DialogHeader
                >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitOutcome"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="outcome-text">{{
                            t('What happened?')
                        }}</Label>
                        <Textarea
                            id="outcome-text"
                            v-model="outcomeForm.outcome"
                        />
                        <InputError :message="outcomeForm.errors.outcome" />
                    </div>
                    <p class="text-muted-foreground text-xs">
                        {{
                            t(
                                "To move this lead's stage, use CRM & Leads afterward.",
                            )
                        }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="outcoming = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="outcomeForm.processing"
                            >{{ t('Save outcome') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
