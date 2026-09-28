<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

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
defineProps<{
    leases: Lease[];
    handovers: Handover[];
    vacancies: Vacancy[];
    canManage: boolean;
}>();

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
        onSuccess: () => form.reset(),
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
    <Head title="Handovers" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Move-in and move-out"
            description="Operational handovers for active leases. Completing a move-out makes the unit available."
        />
        <Card v-if="canManage">
            <CardHeader><CardTitle>Plan a handover</CardTitle></CardHeader>
            <CardContent>
                <form class="flex flex-wrap gap-3" @submit.prevent="create">
                    <select
                        v-model="form.lease_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">Active lease</option>
                        <option
                            v-for="lease in leases"
                            :key="lease.id"
                            :value="String(lease.id)"
                        >
                            {{ lease.reference }} · {{ lease.unit?.number }} ·
                            {{ lease.tenant?.name || 'No tenant' }}
                        </option>
                    </select>
                    <select
                        v-model="form.type"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="move_in">Move in</option>
                        <option value="move_out">Move out</option>
                    </select>
                    <Input v-model="form.scheduled_on" type="date" />
                    <Input v-model="form.notes" placeholder="Handover notes" />
                    <Button :disabled="form.processing"
                        >Create checklist</Button
                    >
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Handover checklists</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="!handovers.length"
                    class="text-muted-foreground text-sm"
                >
                    No handovers planned.
                </p>
                <div
                    v-for="handover in handovers"
                    :key="handover.id"
                    class="space-y-3 border-b pb-3 last:border-0"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <span
                            >{{ handover.lease?.reference }} ·
                            {{ handover.type.replace('_', ' ') }} ·
                            {{ handover.scheduled_on || 'Unscheduled' }}</span
                        >
                        <div class="flex items-center gap-3">
                            <span class="text-muted-foreground">{{
                                handover.status
                            }}</span
                            ><Button
                                v-if="
                                    canManage && handover.status === 'planned'
                                "
                                size="sm"
                                variant="outline"
                                @click="inspectionTarget = handover.id"
                                >Add observation</Button
                            ><Button
                                v-if="
                                    canManage && handover.status === 'planned'
                                "
                                size="sm"
                                @click="complete(handover)"
                                >{{ t('Complete') }}</Button
                            >
                        </div>
                    </div>
                    <p
                        v-if="!handover.inspectionItems.length"
                        class="text-muted-foreground text-sm"
                    >
                        No inspection observations recorded.
                    </p>
                    <ul v-else class="space-y-3 text-sm">
                        <li
                            v-for="item in handover.inspectionItems"
                            :key="item.id"
                            class="space-y-2 rounded-md border p-3"
                        >
                            <div class="flex flex-wrap justify-between gap-2">
                                <p>
                                    {{ item.area }} ·
                                    {{ item.condition.replace('_', ' ') }}
                                    <span v-if="item.notes"
                                        >· {{ item.notes }}</span
                                    >
                                    <span class="text-muted-foreground"
                                        >·
                                        {{
                                            item.recorder || 'Former member'
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
                                    >Attach photo</Button
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
                                >
                                    {{ document.name }}
                                </a>
                            </div>
                            <form
                                v-if="
                                    canManage &&
                                    handover.status === 'planned' &&
                                    evidenceTarget === item.id
                                "
                                class="flex flex-wrap items-end gap-2"
                                @submit.prevent="uploadEvidence(item.id)"
                            >
                                <label class="space-y-1 text-sm"
                                    >Inspection photo<input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        required
                                        class="block max-w-full text-sm"
                                        @change="selectEvidence"
                                /></label>
                                <Button
                                    size="sm"
                                    :disabled="
                                        evidenceForm.processing ||
                                        !evidenceForm.file
                                    "
                                    >{{ t('Upload') }}</Button
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="evidenceTarget = null"
                                    >{{ t('Cancel') }}</Button
                                >
                                <p
                                    v-if="
                                        Object.keys(evidenceForm.errors).length
                                    "
                                    role="alert"
                                    class="text-destructive w-full text-sm"
                                >
                                    {{
                                        Object.values(evidenceForm.errors).join(
                                            ' ',
                                        )
                                    }}
                                </p>
                            </form>
                        </li>
                    </ul>
                    <form
                        v-if="
                            canManage &&
                            handover.status === 'planned' &&
                            inspectionTarget === handover.id
                        "
                        class="grid gap-3 rounded-md border p-3 sm:grid-cols-2"
                        @submit.prevent="addInspection(handover)"
                    >
                        <label class="space-y-1 text-sm"
                            >Area or fixture<Input
                                v-model="inspectionForm.area"
                                placeholder="Kitchen walls"
                                required
                        /></label>
                        <label class="space-y-1 text-sm"
                            >Condition<select
                                v-model="inspectionForm.condition"
                                class="border-input h-9 w-full rounded-md border px-3"
                            >
                                <option value="good">Good</option>
                                <option value="needs_attention">
                                    Needs attention
                                </option>
                                <option value="damaged">Damaged</option>
                            </select></label
                        >
                        <label class="space-y-1 text-sm sm:col-span-2"
                            >Observation notes<Input
                                v-model="inspectionForm.notes"
                                placeholder="Describe condition and evidence"
                        /></label>
                        <p
                            v-if="Object.keys(inspectionForm.errors).length"
                            role="alert"
                            class="text-destructive text-sm sm:col-span-2"
                        >
                            {{ Object.values(inspectionForm.errors).join(' ') }}
                        </p>
                        <div class="flex gap-2 sm:col-span-2">
                            <Button :disabled="inspectionForm.processing"
                                >Save observation</Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                @click="inspectionTarget = null"
                                >{{ t('Cancel') }}</Button
                            >
                        </div>
                    </form>
                </div>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Active vacancies</CardTitle></CardHeader>
            <CardContent class="space-y-4">
                <p
                    v-if="!vacancies.length"
                    class="text-muted-foreground text-sm"
                >
                    No active vacancies.
                </p>
                <div
                    v-for="vacancy in vacancies"
                    :key="vacancy.id"
                    class="space-y-3 border-b pb-4 last:border-0"
                >
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            <p class="font-medium">
                                {{ vacancy.unit?.property || 'Property' }} ·
                                Unit
                                {{ vacancy.unit?.number || 'Unknown' }}
                            </p>
                            <p class="text-muted-foreground text-sm">
                                Vacant {{ vacancy.age_days }} day(s) · since
                                {{ vacancy.vacant_from }} · previous lease
                                {{ vacancy.previous_lease || 'Unknown' }}
                            </p>
                            <p class="text-sm">
                                {{
                                    vacancy.readiness_status.replaceAll(
                                        '_',
                                        ' ',
                                    )
                                }}
                                <span v-if="vacancy.target_ready_on">
                                    · target {{ vacancy.target_ready_on }}</span
                                >
                                <span v-if="vacancy.updated_by">
                                    · updated by {{ vacancy.updated_by }}</span
                                >
                            </p>
                            <p v-if="vacancy.notes" class="text-sm">
                                {{ vacancy.notes }}
                            </p>
                        </div>
                        <Button
                            v-if="canManage"
                            size="sm"
                            variant="outline"
                            @click="editVacancy(vacancy)"
                            >Update readiness</Button
                        >
                    </div>
                    <form
                        v-if="canManage && vacancyTarget === vacancy.id"
                        class="grid gap-3 rounded-md border p-3 sm:grid-cols-2"
                        @submit.prevent="updateVacancy(vacancy)"
                    >
                        <label class="space-y-1 text-sm"
                            >Readiness<select
                                v-model="vacancyForm.readiness_status"
                                class="border-input h-9 w-full rounded-md border px-3"
                            >
                                <option value="inspection">Inspection</option>
                                <option value="maintenance">
                                    {{ t('Maintenance') }}
                                </option>
                                <option value="ready_to_list">
                                    Ready to list
                                </option>
                                <option value="listed">Listed</option>
                            </select></label
                        >
                        <label class="space-y-1 text-sm"
                            >Target ready date<Input
                                v-model="vacancyForm.target_ready_on"
                                type="date"
                        /></label>
                        <label class="space-y-1 text-sm sm:col-span-2"
                            >Readiness notes<Input
                                v-model="vacancyForm.notes"
                                placeholder="Repairs, cleaning, or listing notes"
                        /></label>
                        <p
                            v-if="Object.keys(vacancyForm.errors).length"
                            role="alert"
                            class="text-destructive text-sm sm:col-span-2"
                        >
                            {{ Object.values(vacancyForm.errors).join(' ') }}
                        </p>
                        <div class="flex gap-2 sm:col-span-2">
                            <Button :disabled="vacancyForm.processing"
                                >Save readiness</Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                @click="vacancyTarget = null"
                                >{{ t('Cancel') }}</Button
                            >
                        </div>
                    </form>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
