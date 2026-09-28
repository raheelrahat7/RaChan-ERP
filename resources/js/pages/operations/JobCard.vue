<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t, status } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import JobSla from '@/components/JobSla.vue';
import type { SlaCycle } from '@/types/sla';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Note = {
    id: number;
    note: string;
    created_at: string;
    author: { name: string } | null;
};
type Task = {
    id: number;
    label: string;
    is_required: boolean;
    completed_at: string | null;
};
type Cost = {
    id: number;
    category: string;
    description: string;
    quantity: string;
    unit_rate: string;
    amount: string;
    voided_at: string | null;
    void_reason: string | null;
};
type Evidence = { id: number; name: string; size: number };
const props = defineProps<{
    job: {
        id: number;
        reference: string;
        title: string;
        description: string | null;
        status: string;
        requires_manager_confirmation: boolean;
        submitted_at: string | null;
        confirmed_at: string | null;
        completed_at: string | null;
        priority: string;
        due_at: string | null;
        currency: string;
        estimated_cost: string | null;
        actual_cost: string | null;
        job_notes: Note[];
        job_tasks: Task[];
        job_cost_lines: Cost[];
        documents: Evidence[];
    };
    property: { name: string } | null;
    unit: { number: string } | null;
    vendor: { name: string } | null;
    assignee: { name: string } | null;
    submitter: { name: string } | null;
    confirmer: { name: string } | null;
    completer: { name: string } | null;
    stockMovements: {
        id: number;
        type: string;
        quantity_display: string;
        delta_display: string;
        reference: string;
        reason: string | null;
        related_movement_id: number | null;
        reversed_by_movement_id: number | null;
        part: { code: string; name: string; unit: string } | null;
        store: { name: string } | null;
    }[];
    canManage: boolean;
    slaCycles: SlaCycle[];
    canEdit: boolean;
    totals: { labor: string; material: string; total: string };
    history: {
        id: number;
        event: string;
        created_at: string;
        actor: { name: string } | null;
        properties: Record<string, unknown>;
    }[];
}>();
const lifecycleForm = useForm({ reason: '' });
function lifecycle(action: 'finish' | 'confirm' | 'reopen'): void {
    lifecycleForm.post(`${base}/${action}`, {
        preserveScroll: true,
        onSuccess: () => lifecycleForm.reset(),
    });
}
const base = `/maintenance/${props.job.id}/job-card`;
const noteForm = useForm({ note: '' });
const taskForm = useForm({ label: '', is_required: true });
const costForm = useForm({
    category: 'labor',
    description: '',
    quantity: '1',
    unit_rate: '0',
});
const evidenceForm = useForm<{ file: File | null }>({ file: null });
const voidForm = useForm({ reason: '' });
const selectedLine = ref('');
const taskPending = ref(false);
const taskError = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
function addNote(): void {
    noteForm.post(`${base}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}
function addTask(): void {
    taskForm.post(`${base}/tasks`, {
        preserveScroll: true,
        onSuccess: () => taskForm.reset(),
    });
}
function addCost(): void {
    costForm.post(`${base}/costs`, {
        preserveScroll: true,
        onSuccess: () => costForm.reset(),
    });
}
function attachEvidence(): void {
    evidenceForm.post(`${base}/evidence`, {
        preserveScroll: true,
        onSuccess: () => {
            evidenceForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}
function checkTask(task: Task): void {
    if (taskPending.value) return;
    taskPending.value = true;
    taskError.value = '';
    router.put(
        `${base}/tasks/${task.id}`,
        { complete: !task.completed_at },
        {
            preserveScroll: true,
            onError: (errors) => {
                taskError.value =
                    Object.values(errors)[0] ??
                    t('Unable to update this task.');
            },
            onFinish: () => {
                taskPending.value = false;
            },
        },
    );
}
function voidCost(): void {
    if (!selectedLine.value) return;
    voidForm.post(`${base}/costs/${selectedLine.value}/void`, {
        preserveScroll: true,
        onSuccess: () => {
            voidForm.reset();
            selectedLine.value = '';
        },
    });
}
const historyLabels: Record<string, string> = {
    'operations.maintenance.created': 'Job created',
    'operations.maintenance.work_order_updated': 'Work order updated',
    'operations.maintenance.status_updated': 'Status updated',
    'operations.customer_service.requested': 'Customer service requested',
    'operations.job.submitted': 'Submitted for confirmation',
    'operations.job.completed': 'Job completed',
    'operations.job.confirmed': 'Completion confirmed',
    'operations.job.reopened': 'Job reopened',
    'operations.job.cancelled': 'Job cancelled',
    'operations.job.note_added': 'Service note added',
    'operations.job.task_added': 'Checklist item added',
    'operations.job.task_updated': 'Checklist item updated',
    'operations.job.cost_added': 'Cost entry added',
    'operations.job.cost_voided': 'Cost entry voided',
    'operations.job.evidence_added': 'Evidence attached',
    'operations.job.parts_updated': 'Job parts updated',
    'operations.sla.enabled': 'SLA tracking enabled',
    'operations.sla.acknowledged': 'Job acknowledged',
};
function historyLabel(event: string): string {
    return t(
        historyLabels[event] ?? event.replaceAll('.', ' ').replaceAll('_', ' '),
    );
}
function chooseFile(event: Event): void {
    evidenceForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
</script>

<template>
    <Head :title="`${job.reference} · Job card`" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Link
            href="/maintenance"
            class="text-sm underline underline-offset-4"
            >{{ t('Back to maintenance') }}</Link
        >
        <Heading
            :translate-text="false"
            :title="`${job.reference} · ${job.title}`"
            :description="
                t('Service work, checklist, operational costs and evidence.')
            "
        />
        <JobSla
            :job-id="job.id"
            :cycles="slaCycles"
            :can-manage="canManage"
            :can-enable="canEdit && job.status !== 'on_hold'"
        />
        <Card>
            <CardHeader
                ><CardTitle>{{ t('Job details') }}</CardTitle></CardHeader
            >
            <CardContent class="grid gap-3 text-sm sm:grid-cols-2">
                <p>
                    {{ t('Property') }}: {{ property?.name ?? t('Unavailable')
                    }}<span v-if="unit">
                        · {{ t('Unit') }} {{ unit.number }}</span
                    >
                </p>
                <p>
                    {{ t('Assigned to') }}:
                    {{ assignee?.name ?? t('Unassigned') }}
                </p>
                <p>
                    {{ t('Status') }}: {{ status(job.status) }} ·
                    {{ t('Priority') }}: {{ status(job.priority) }}
                </p>
                <p>{{ t('Vendor') }}: {{ vendor?.name ?? t('No vendor') }}</p>
                <p>{{ t('Due') }}: {{ job.due_at ?? t('No due date') }}</p>
                <p>
                    {{ job.currency }} {{ t('estimate') }}:
                    {{ job.estimated_cost ?? '—' }} · {{ t('Actual') }}:
                    {{ job.actual_cost ?? '—' }}
                </p>
                <p
                    v-if="job.description"
                    class="whitespace-pre-wrap sm:col-span-2"
                >
                    {{ job.description }}
                </p>
                <p v-if="!canEdit" class="text-muted-foreground sm:col-span-2">
                    {{ t('This job card is read-only.') }}
                </p>
                <p v-if="canManage" class="sm:col-span-2">
                    <Link
                        href="/maintenance"
                        class="underline underline-offset-4"
                        >{{ t('Manage assignment, vendor and status') }}</Link
                    >
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>{{ t('Completion') }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p class="text-sm">
                    {{
                        job.requires_manager_confirmation
                            ? t('Manager confirmation required')
                            : t('Assigned technician can complete this job')
                    }}
                </p>
                <p v-if="job.submitted_at" class="text-sm">
                    {{ t('Submitted by') }}
                    {{ submitter?.name ?? t('Unavailable member') }} ·
                    {{ job.submitted_at }}
                </p>
                <p v-if="job.confirmed_at" class="text-sm">
                    {{ t('Confirmed by') }}
                    {{ confirmer?.name ?? t('Unavailable member') }} ·
                    {{ job.confirmed_at }}
                </p>
                <p v-if="job.completed_at" class="text-sm">
                    {{ t('Completed by') }}
                    {{ completer?.name ?? t('Unavailable member') }} ·
                    {{ job.completed_at }}
                </p>
                <p
                    v-if="job.submitted_at && job.status !== 'completed'"
                    class="text-sm"
                >
                    {{
                        t(
                            'Awaiting manager confirmation. A manager can reopen the job if changes are needed.',
                        )
                    }}
                </p>
                <Button
                    v-if="canEdit"
                    :disabled="lifecycleForm.processing"
                    @click="lifecycle('finish')"
                    >{{
                        job.requires_manager_confirmation
                            ? t('Submit for confirmation')
                            : t('Complete job')
                    }}</Button
                >
                <Button
                    v-if="
                        canManage &&
                        job.submitted_at &&
                        job.status !== 'completed' &&
                        job.status !== 'cancelled'
                    "
                    :disabled="lifecycleForm.processing"
                    @click="lifecycle('confirm')"
                    >{{ t('Confirm completion') }}</Button
                >
                <form
                    v-if="
                        canManage &&
                        (job.submitted_at ||
                            ['completed', 'cancelled'].includes(job.status))
                    "
                    class="space-y-2"
                    @submit.prevent="lifecycle('reopen')"
                >
                    <label for="reopen-reason" class="text-sm font-medium">{{
                        t('Reason for reopening / changes needed')
                    }}</label>
                    <textarea
                        id="reopen-reason"
                        v-model="lifecycleForm.reason"
                        required
                        maxlength="2000"
                        rows="3"
                        class="border-input w-full rounded-md border p-3"
                    />
                    <Button
                        variant="outline"
                        :disabled="lifecycleForm.processing"
                        >{{ t('Reopen job') }}</Button
                    >
                </form>
                <p
                    v-for="(message, field) in lifecycleForm.errors"
                    :key="field"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ message }}
                </p>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>{{ t('Service notes') }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-4">
                <p
                    v-if="!job.job_notes.length"
                    class="text-muted-foreground text-sm"
                >
                    {{ t('No service notes recorded.') }}
                </p>
                <article
                    v-for="note in job.job_notes"
                    :key="note.id"
                    class="space-y-1 border-b pb-3"
                >
                    <p class="text-sm whitespace-pre-wrap">{{ note.note }}</p>
                    <p class="text-muted-foreground text-xs">
                        {{ note.author?.name ?? t('Unavailable member') }} ·
                        {{ note.created_at }}
                    </p>
                </article>
                <form
                    v-if="canEdit"
                    class="space-y-2"
                    @submit.prevent="addNote"
                >
                    <label for="service-note" class="text-sm font-medium">{{
                        t('New service note')
                    }}</label>
                    <textarea
                        id="service-note"
                        v-model="noteForm.note"
                        required
                        maxlength="5000"
                        rows="3"
                        class="border-input w-full rounded-md border p-3"
                    />
                    <p
                        v-if="noteForm.errors.note"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ noteForm.errors.note }}
                    </p>
                    <Button :disabled="noteForm.processing">{{
                        t('Add note')
                    }}</Button>
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>{{ t('Work checklist') }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p
                    v-if="!job.job_tasks.length"
                    class="text-muted-foreground text-sm"
                >
                    {{ t('No checklist items defined.') }}
                </p>
                <label
                    v-for="task in job.job_tasks"
                    :key="task.id"
                    class="flex items-start gap-3 text-sm"
                >
                    <input
                        type="checkbox"
                        :checked="!!task.completed_at"
                        :disabled="!canEdit || taskPending"
                        @change="checkTask(task)"
                    />
                    <span
                        >{{ task.label }}
                        <span
                            v-if="task.is_required"
                            class="text-muted-foreground"
                            >{{ t('(required)') }}</span
                        ><span v-if="task.completed_at" class="block text-xs"
                            >{{ t('Completed') }} {{ task.completed_at }}</span
                        ></span
                    >
                </label>
                <p
                    v-if="taskError"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ taskError }}
                </p>
                <form
                    v-if="canManage && canEdit"
                    class="space-y-2 border-t pt-3"
                    @submit.prevent="addTask"
                >
                    <label for="task-label" class="text-sm font-medium">{{
                        t('New checklist item')
                    }}</label>
                    <Input
                        id="task-label"
                        v-model="taskForm.label"
                        required
                        maxlength="255"
                    />
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="taskForm.is_required"
                            type="checkbox"
                        />{{ t('Required for completion') }}</label
                    >
                    <p
                        v-for="(error, field) in taskForm.errors"
                        :key="field"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                    <Button :disabled="taskForm.processing">{{
                        t('Add checklist item')
                    }}</Button>
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>{{
                    t('Labor and materials')
                }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-4">
                <p class="text-sm">
                    {{ t('Labor') }} AED {{ totals.labor }} ·
                    {{ t('Materials') }} AED {{ totals.material }} ·
                    {{ t('Total') }} AED {{ totals.total }}
                </p>
                <p class="text-muted-foreground text-sm">
                    {{
                        t(
                            'These operational entries are separate from the actual cost recorded by the manager.',
                        )
                    }}
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm">
                        <thead>
                            <tr>
                                <th class="p-2">
                                    {{ t('Category / description') }}
                                </th>
                                <th class="p-2">{{ t('Quantity') }}</th>
                                <th class="p-2">{{ t('Rate AED') }}</th>
                                <th class="p-2">{{ t('Amount AED') }}</th>
                                <th class="p-2">{{ t('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in job.job_cost_lines"
                                :key="line.id"
                                class="border-t"
                            >
                                <td class="p-2">
                                    {{ status(line.category) }} ·
                                    {{ line.description }}
                                </td>
                                <td class="p-2">{{ line.quantity }}</td>
                                <td class="p-2">{{ line.unit_rate }}</td>
                                <td class="p-2">{{ line.amount }}</td>
                                <td class="p-2">
                                    {{
                                        line.voided_at
                                            ? `${t('Voided')}: ${line.void_reason}`
                                            : t('Active')
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <form
                    v-if="canEdit && job.currency === 'AED'"
                    class="grid gap-3 sm:grid-cols-2"
                    @submit.prevent="addCost"
                >
                    <div class="space-y-1">
                        <label
                            for="cost-category"
                            class="text-sm font-medium"
                            >{{ t('Category') }}</label
                        ><select
                            id="cost-category"
                            v-model="costForm.category"
                            class="border-input h-9 w-full rounded-md border px-3"
                        >
                            <option value="labor">{{ t('Labor') }}</option>
                            <option value="material">
                                {{ t('Material') }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label
                            for="cost-description"
                            class="text-sm font-medium"
                            >{{ t('Description') }}</label
                        ><Input
                            id="cost-description"
                            v-model="costForm.description"
                            required
                            maxlength="255"
                        />
                    </div>
                    <div class="space-y-1">
                        <label
                            for="cost-quantity"
                            class="text-sm font-medium"
                            >{{ t('Quantity / labor hours') }}</label
                        ><Input
                            id="cost-quantity"
                            v-model="costForm.quantity"
                            type="number"
                            min="0.01"
                            max="999999.99"
                            step="0.01"
                            required
                        />
                    </div>
                    <div class="space-y-1">
                        <label for="cost-rate" class="text-sm font-medium">{{
                            t('Unit rate AED')
                        }}</label
                        ><Input
                            id="cost-rate"
                            v-model="costForm.unit_rate"
                            type="number"
                            min="0"
                            max="999999.99"
                            step="0.01"
                            required
                        />
                    </div>
                    <p
                        v-for="(error, field) in costForm.errors"
                        :key="field"
                        role="alert"
                        class="text-destructive text-sm sm:col-span-2"
                    >
                        {{ error }}
                    </p>
                    <Button
                        :disabled="costForm.processing"
                        class="justify-self-start"
                        >{{ t('Add cost entry') }}</Button
                    >
                </form>
                <form
                    v-if="
                        canManage &&
                        canEdit &&
                        job.job_cost_lines.some((line) => !line.voided_at)
                    "
                    class="space-y-2 border-t pt-3"
                    @submit.prevent="voidCost"
                >
                    <label for="void-line" class="text-sm font-medium">{{
                        t('Correct an entry by voiding it')
                    }}</label>
                    <select
                        id="void-line"
                        v-model="selectedLine"
                        class="border-input h-9 w-full rounded-md border px-3"
                        required
                    >
                        <option disabled value="">
                            {{ t('Select an active entry') }}
                        </option>
                        <option
                            v-for="line in job.job_cost_lines.filter(
                                (line) => !line.voided_at,
                            )"
                            :key="line.id"
                            :value="String(line.id)"
                        >
                            {{ line.description }} · AED {{ line.amount }}
                        </option>
                    </select>
                    <label for="void-reason" class="text-sm font-medium">{{
                        t('Reason')
                    }}</label
                    ><Input
                        id="void-reason"
                        v-model="voidForm.reason"
                        required
                        maxlength="2000"
                    />
                    <p
                        v-if="voidForm.errors.reason"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ voidForm.errors.reason }}
                    </p>
                    <Button variant="outline" :disabled="voidForm.processing">{{
                        t('Void entry')
                    }}</Button>
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>{{ t('Stock parts') }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p class="text-muted-foreground text-sm">
                    {{
                        t(
                            'Physical parts issued and returned for this job. Quantities are separate from the operational cost entries above.',
                        )
                    }}
                </p>
                <Link
                    v-if="canManage"
                    :href="`/operations/spare-parts?maintenance_request_id=${job.id}`"
                    class="text-sm underline underline-offset-4"
                    >{{ t('Manage job stock') }}</Link
                >
                <p v-if="!stockMovements.length" class="text-sm">
                    {{ t('No stock movements for this job.') }}
                </p>
                <article
                    v-for="movement in stockMovements"
                    :key="movement.id"
                    class="border-b pb-2 text-sm"
                >
                    <p>
                        #{{ movement.id }} · {{ status(movement.type) }} ·
                        {{ movement.part?.code }} {{ movement.part?.name }} ·
                        {{ movement.quantity_display }}
                        {{ movement.part?.unit }} · {{ movement.store?.name }}
                    </p>
                    <p>
                        {{ movement.reference
                        }}<span v-if="movement.reversed_by_movement_id">
                            · {{ t('Reversed by') }} #{{
                                movement.reversed_by_movement_id
                            }}</span
                        ><span v-if="movement.related_movement_id">
                            · {{ t('Original') }} #{{
                                movement.related_movement_id
                            }}</span
                        >
                    </p>
                    <p v-if="movement.reason" class="whitespace-pre-wrap">
                        {{ movement.reason }}
                    </p>
                </article>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>{{ t('Service evidence') }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p
                    v-if="!job.documents.length"
                    class="text-muted-foreground text-sm"
                >
                    {{ t('No evidence attached.') }}
                </p>
                <a
                    v-for="document in job.documents"
                    :key="document.id"
                    :href="`/documents/${document.id}/versions`"
                    class="block text-sm underline underline-offset-4"
                    >{{ document.name }} · {{ Math.ceil(document.size / 1024) }}
                    {{ t('KB') }}</a
                >
                <form
                    v-if="canEdit"
                    class="space-y-2"
                    @submit.prevent="attachEvidence"
                >
                    <label for="job-evidence" class="text-sm font-medium">{{
                        t('Photo or PDF evidence (up to 10 MB)')
                    }}</label>
                    <input
                        id="job-evidence"
                        ref="fileInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        required
                        class="block w-full text-sm"
                        @change="chooseFile"
                    />
                    <p
                        v-if="evidenceForm.errors.file"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ evidenceForm.errors.file }}
                    </p>
                    <Button
                        :disabled="
                            evidenceForm.processing || !evidenceForm.file
                        "
                        >{{ t('Attach evidence') }}</Button
                    >
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>{{
                    t('Recent job history')
                }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p v-if="!history.length" class="text-muted-foreground text-sm">
                    {{ t('No job history recorded.') }}
                </p>
                <article
                    v-for="entry in history"
                    :key="entry.id"
                    class="border-b pb-2 text-sm"
                >
                    <p>
                        {{ historyLabel(entry.event) }}
                    </p>
                    <p
                        v-if="entry.properties.reason"
                        class="whitespace-pre-wrap"
                    >
                        {{ entry.properties.reason }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ entry.actor?.name ?? t('Unavailable member') }} ·
                        {{ entry.created_at }}
                    </p>
                </article>
            </CardContent>
        </Card>
    </div>
</template>
