<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
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

const { t } = useLocale();

type Lease = {
    id: number;
    reference: string;
    ends_on: string;
    unit: { number: string } | null;
    tenant: { name: string } | null;
};
type Handover = {
    id: number;
    type: string;
    status: string;
    scheduled_on: string | null;
    completed_at: string | null;
    notes: string | null;
    lease: { reference: string } | null;
    inspectionItems: {
        id: number;
        area: string;
        condition: string;
        notes: string | null;
        created_at: string;
        recorder: string | null;
        documents: {
            id: number;
            name: string;
            mime_type: string;
            size: number;
        }[];
    }[];
};
type Vacancy = {
    id: number;
    vacant_from: string;
    target_ready_on: string | null;
    readiness_status: string;
    notes: string | null;
    age_days: number;
    unit: { id: number; number: string; property: string | null } | null;
    previous_lease: string | null;
    updated_by: string | null;
};
const props = defineProps<{
    leases: Lease[];
    handovers: Handover[];
    vacancies: Vacancy[];
    canManage: boolean;
}>();
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const tab = ref<'checklists' | 'vacancies'>('checklists');
const planOpen = ref(false);
const label = (value: string): string => value.replaceAll('_', ' ');
const inspectionHandover = computed(() =>
    props.handovers.find((item) => item.id === inspectionTarget.value),
);
const evidenceItem = computed(() =>
    props.handovers
        .flatMap((item) => item.inspectionItems)
        .find((item) => item.id === evidenceTarget.value),
);
const vacancyOf = computed(() =>
    props.vacancies.find((item) => item.id === vacancyTarget.value),
);

type VacancyRow = {
    id: number;
    unit: string;
    vacant: string;
    readiness: string;
    target: string;
    previous: string;
    actions: string;
};
const vacancyRows = computed<VacancyRow[]>(() =>
    props.vacancies.map((vacancy) => ({
        id: vacancy.id,
        unit: `${vacancy.unit?.property || t('Property')} · ${vacancy.unit?.number || t('Unknown')}`,
        vacant: `${vacancy.age_days} ${t('days')} · ${vacancy.vacant_from}`,
        readiness: vacancy.readiness_status,
        target: vacancy.target_ready_on ?? '',
        previous: vacancy.previous_lease ?? '',
        actions: '',
    })),
);
const vacancyColumns = computed<DataTableColumn<VacancyRow>[]>(() => [
    { key: 'unit', label: t('Unit'), sortable: true },
    { key: 'vacant', label: t('Vacant') },
    { key: 'readiness', label: t('Readiness') },
    { key: 'target', label: t('Target ready date'), sortable: true },
    { key: 'previous', label: t('Previous lease') },
    { key: 'actions', label: '' },
]);
const vacancyById = (id: number): Vacancy =>
    props.vacancies.find((item) => item.id === id)!;

const form = useForm({
    lease_id: '',
    type: 'move_in',
    scheduled_on: '',
    notes: '',
});
const inspectionTarget = ref<number | null>(null);
const inspectionForm = useForm({
    area: '',
    condition: 'good',
    notes: '',
});
const evidenceTarget = ref<number | null>(null);
const evidenceForm = useForm<{ file: File | null }>({ file: null });
function selectEvidence(event: Event): void {
    evidenceForm.file = (event.target as HTMLInputElement).files?.[0] || null;
}
function uploadEvidence(itemId: number): void {
    evidenceForm.post(`/handover-inspection-items/${itemId}/evidence`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            evidenceForm.reset();
            evidenceTarget.value = null;
        },
    });
}
const vacancyTarget = ref<number | null>(null);
const vacancyForm = useForm({
    readiness_status: 'inspection',
    target_ready_on: '',
    notes: '',
});
function editVacancy(vacancy: Vacancy): void {
    vacancyTarget.value = vacancy.id;
    vacancyForm.readiness_status = vacancy.readiness_status;
    vacancyForm.target_ready_on = vacancy.target_ready_on || '';
    vacancyForm.notes = vacancy.notes || '';
}
function updateVacancy(vacancy: Vacancy): void {
    vacancyForm.put(`/vacancies/${vacancy.id}`, {
        preserveScroll: true,
        onSuccess: () => (vacancyTarget.value = null),
    });
}
function addInspection(handover: Handover): void {
    inspectionForm.post(`/handovers/${handover.id}/inspection-items`, {
        preserveScroll: true,
        onSuccess: () => {
            inspectionForm.reset();
            inspectionTarget.value = null;
        },
    });
}
function create(): void {
    form.post('/handovers', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            planOpen.value = false;
        },
    });
}
function complete(handover: Handover): void {
    const warning = handover.inspectionItems.length
        ? ''
        : ' No inspection observations have been recorded.';
    if (
        confirm(
            `Complete this ${handover.type.replace('_', ' ')} handover?${warning}`,
        )
    ) {
        router.post(
            `/handovers/${handover.id}/complete`,
            {},
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <Head :title="t('Handovers')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Move-in and move-out"
            description="Operational handovers for active leases. Completing a move-out makes the unit available."
        >
            <template #actions>
                <Button
                    v-if="canManage && tab === 'checklists'"
                    type="button"
                    @click="planOpen = true"
                    >{{ t('Plan a handover') }}</Button
                >
            </template>
        </PageHeader>

        <nav :aria-label="t('Handover views')" class="flex gap-2">
            <button
                v-for="item in [
                    { key: 'checklists', label: 'Handover checklists' },
                    { key: 'vacancies', label: 'Active vacancies' },
                ] as const"
                :key="item.key"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="
                    item.key === tab
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted border'
                "
                :aria-current="item.key === tab ? 'page' : undefined"
                @click="tab = item.key"
            >
                {{ t(item.label)
                }}<span class="opacity-70">
                    ({{
                        item.key === 'checklists'
                            ? handovers.length
                            : vacancies.length
                    }})</span
                >
            </button>
        </nav>

        <template v-if="tab === 'checklists'">
            <p v-if="!handovers.length" class="text-muted-foreground text-sm">
                {{ t('No handovers planned.') }}
            </p>
            <Card v-for="handover in handovers" :key="handover.id">
                <CardContent class="space-y-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{
                                handover.lease?.reference
                            }}</span>
                            <Badge variant="outline" class="capitalize">{{
                                label(handover.type)
                            }}</Badge>
                            <span class="text-muted-foreground text-sm">{{
                                handover.scheduled_on || t('Unscheduled')
                            }}</span>
                            <Badge variant="secondary">{{
                                handover.status
                            }}</Badge>
                        </div>
                        <div
                            v-if="canManage && handover.status === 'planned'"
                            class="flex gap-2"
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="inspectionTarget = handover.id"
                                >{{ t('Add observation') }}</Button
                            >
                            <Button size="sm" @click="complete(handover)">{{
                                t('Complete')
                            }}</Button>
                        </div>
                    </div>
                    <p
                        v-if="handover.notes"
                        class="text-muted-foreground text-sm"
                    >
                        {{ handover.notes }}
                    </p>
                    <p
                        v-if="!handover.inspectionItems.length"
                        class="text-muted-foreground text-sm"
                    >
                        {{ t('No inspection observations recorded.') }}
                    </p>
                    <ul v-else class="space-y-2 text-sm">
                        <li
                            v-for="item in handover.inspectionItems"
                            :key="item.id"
                            class="space-y-2 rounded-md border p-3"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <p>
                                    <span class="font-medium">{{
                                        item.area
                                    }}</span>
                                    <Badge
                                        :variant="
                                            item.condition === 'good'
                                                ? 'secondary'
                                                : 'destructive'
                                        "
                                        class="ms-2 capitalize"
                                        >{{ label(item.condition) }}</Badge
                                    >
                                    <span
                                        v-if="item.notes"
                                        class="text-muted-foreground"
                                    >
                                        · {{ item.notes }}</span
                                    >
                                    <span class="text-muted-foreground">
                                        ·
                                        {{
                                            item.recorder || t('Former member')
                                        }}</span
                                    >
                                </p>
                                <Button
                                    v-if="
                                        canManage &&
                                        handover.status === 'planned'
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="evidenceTarget = item.id"
                                    >{{ t('Attach photo') }}</Button
                                >
                            </div>
                            <div
                                v-if="item.documents.length"
                                class="flex flex-wrap gap-2"
                            >
                                <a
                                    v-for="document in item.documents"
                                    :key="document.id"
                                    :href="`/documents/${document.id}/versions`"
                                    class="text-primary underline underline-offset-4"
                                    >{{ document.name }}</a
                                >
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </template>

        <CrmSettingsTable
            v-else
            :show-title="false"
            title="Active vacancies"
            add-label=""
            :columns="vacancyColumns"
            :rows="vacancyRows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.unit"
            :selectable="false"
            searchable
        >
            <template #cell-readiness="{ row }"
                ><Badge variant="secondary" class="capitalize">{{
                    label(row.readiness)
                }}</Badge></template
            >
            <template #cell-actions="{ row }">
                <div v-if="canManage" class="flex justify-end">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="editVacancy(vacancyById(row.id))"
                        >{{ t('Update readiness') }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="planOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Plan a handover')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'Operational handovers for active leases. Completing a move-out makes the unit available.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="handover-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="ho-lease">{{ t('Active lease') }}</Label>
                        <select
                            id="ho-lease"
                            v-model="form.lease_id"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="lease in leases"
                                :key="lease.id"
                                :value="String(lease.id)"
                            >
                                {{ lease.reference }} ·
                                {{ lease.unit?.number }} ·
                                {{ lease.tenant?.name || t('No tenant') }}
                            </option>
                        </select>
                        <InputError :message="form.errors.lease_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="ho-type">{{ t('Type') }}</Label>
                        <select
                            id="ho-type"
                            v-model="form.type"
                            :class="selectClass"
                        >
                            <option value="move_in">{{ t('Move in') }}</option>
                            <option value="move_out">
                                {{ t('Move out') }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="ho-date">{{ t('Scheduled on') }}</Label
                        ><Input
                            id="ho-date"
                            v-model="form.scheduled_on"
                            type="date"
                        /><InputError :message="form.errors.scheduled_on" />
                    </div>
                    <div class="space-y-1">
                        <Label for="ho-notes">{{ t('Handover notes') }}</Label
                        ><Input id="ho-notes" v-model="form.notes" /><InputError
                            :message="form.errors.notes"
                        />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="handover-form"
                        :disabled="form.processing"
                        >{{ t('Create checklist') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="planOpen = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <Dialog
            :open="inspectionTarget !== null"
            @update:open="(value) => !value && (inspectionTarget = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ t('Add observation') }}</DialogTitle>
                    <DialogDescription>{{
                        inspectionHandover?.lease?.reference
                    }}</DialogDescription>
                </DialogHeader>
                <form
                    class="space-y-3"
                    @submit.prevent="
                        inspectionHandover && addInspection(inspectionHandover)
                    "
                >
                    <div class="space-y-1">
                        <Label for="io-area">{{ t('Area or fixture') }}</Label
                        ><Input
                            id="io-area"
                            v-model="inspectionForm.area"
                            :placeholder="t('Kitchen walls')"
                            required
                        /><InputError :message="inspectionForm.errors.area" />
                    </div>
                    <div class="space-y-1">
                        <Label for="io-cond">{{ t('Condition') }}</Label>
                        <select
                            id="io-cond"
                            v-model="inspectionForm.condition"
                            :class="selectClass"
                        >
                            <option value="good">{{ t('Good') }}</option>
                            <option value="needs_attention">
                                {{ t('Needs attention') }}
                            </option>
                            <option value="damaged">{{ t('Damaged') }}</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="io-notes">{{
                            t('Observation notes')
                        }}</Label
                        ><Input
                            id="io-notes"
                            v-model="inspectionForm.notes"
                            :placeholder="t('Describe condition and evidence')"
                        /><InputError :message="inspectionForm.errors.notes" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="inspectionTarget = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button :disabled="inspectionForm.processing">{{
                            t('Save observation')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="evidenceTarget !== null"
            @update:open="(value) => !value && (evidenceTarget = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ t('Attach photo') }}</DialogTitle>
                    <DialogDescription>{{
                        evidenceItem?.area
                    }}</DialogDescription>
                </DialogHeader>
                <form
                    class="space-y-3"
                    @submit.prevent="
                        evidenceItem && uploadEvidence(evidenceItem.id)
                    "
                >
                    <div class="space-y-1">
                        <Label for="ev-file">{{ t('Inspection photo') }}</Label>
                        <input
                            id="ev-file"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            required
                            class="block w-full text-sm"
                            @change="selectEvidence"
                        />
                        <InputError
                            :message="
                                Object.values(evidenceForm.errors).join(' ')
                            "
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="evidenceTarget = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            :disabled="
                                evidenceForm.processing || !evidenceForm.file
                            "
                            >{{ t('Upload') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="vacancyTarget !== null"
            @update:open="(value) => !value && (vacancyTarget = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ t('Update readiness') }}</DialogTitle>
                    <DialogDescription
                        >{{ vacancyOf?.unit?.property }} ·
                        {{ vacancyOf?.unit?.number }}</DialogDescription
                    >
                </DialogHeader>
                <form
                    class="space-y-3"
                    @submit.prevent="vacancyOf && updateVacancy(vacancyOf)"
                >
                    <div class="space-y-1">
                        <Label for="vc-ready">{{ t('Readiness') }}</Label>
                        <select
                            id="vc-ready"
                            v-model="vacancyForm.readiness_status"
                            :class="selectClass"
                        >
                            <option value="inspection">
                                {{ t('Inspection') }}
                            </option>
                            <option value="maintenance">
                                {{ t('Maintenance') }}
                            </option>
                            <option value="ready_to_list">
                                {{ t('Ready to list') }}
                            </option>
                            <option value="listed">{{ t('Listed') }}</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="vc-target">{{
                            t('Target ready date')
                        }}</Label
                        ><Input
                            id="vc-target"
                            v-model="vacancyForm.target_ready_on"
                            type="date"
                        /><InputError
                            :message="vacancyForm.errors.target_ready_on"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="vc-notes">{{ t('Readiness notes') }}</Label
                        ><Input
                            id="vc-notes"
                            v-model="vacancyForm.notes"
                            :placeholder="
                                t('Repairs, cleaning, or listing notes')
                            "
                        /><InputError :message="vacancyForm.errors.notes" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="vacancyTarget = null"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button :disabled="vacancyForm.processing">{{
                            t('Save readiness')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
