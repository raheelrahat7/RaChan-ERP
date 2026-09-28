<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { reactive, computed, onMounted, ref } from 'vue';
import CrmLeadDetailsEditor from '@/components/CrmLeadDetailsEditor.vue';
import CrmPipelineBoard from '@/components/CrmPipelineBoard.vue';
import CrmLeadStageEditor from '@/components/CrmLeadStageEditor.vue';
import CrmLeadTransfer from '@/components/CrmLeadTransfer.vue';
import InputError from '@/components/InputError.vue';
import type { Pipeline, Stage, TransitionOption } from '@/types/crm-pipeline';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Lead = {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    company: string | null;
    source: string | null;
    listing: { id: number; reference: string } | null;
    project_name: string | null;
    campaign_name: string | null;
    meta_form_id: string | null;
    meta_form_name: string | null;
    status: string;
    notes: string | null;
    transitionOptions: TransitionOption[];
    conversionBlockedReason: string | null;
    pipeline_id: number;
    current_stage_id: number;
    stage: Stage;
    history: {
        id: number;
        changed_at: string;
        snapshot: {
            from: string | null;
            from_pipeline?: string | null;
            pipeline?: string;
            to: string;
            lost_reason: string | null;
        };
        actor: string | null;
        notes: string | null;
    }[];
    converted: boolean;
    assigned_to: number | null;
    assignee: { id: number; name: string } | null;
};
type Activity = {
    id: number;
    type: string;
    notes: string | null;
    due_at: string | null;
    lead: Pick<Lead, 'id' | 'first_name' | 'last_name'> | null;
    creator: { id: number; name: string } | null;
};

const props = defineProps<{
    pipelines: Pipeline[];
    stageCounts: (Stage & { count: number })[];
    filters: {
        pipeline_id: number;
        stage_id: number | null;
        assignee_id: number | null;
    };
    canManagePipelines: boolean;
    canManageHierarchy: boolean;
    assigneeScoped: boolean;
    limitedVisibility: boolean;
    leads: Lead[];
    activities: Activity[];
    canManageCrm: boolean;
    members: { id: number; name: string }[];
    followUps: (Activity & { is_overdue: boolean })[];
}>();

const page = usePage();
const leadView = ref<'board' | 'list'>('board');
onMounted(() => {
    const saved = localStorage.getItem('crm-lead-view');
    if (saved === 'board' || saved === 'list') leadView.value = saved;
});
const filters = reactive({ ...props.filters });
const appliedPipeline = computed(() =>
    props.pipelines.find(
        (pipeline) => pipeline.id === props.filters.pipeline_id,
    ),
);
const selectedPipeline = computed(() =>
    props.pipelines.find(
        (pipeline) => pipeline.id === Number(filters.pipeline_id),
    ),
);
function filterLeads(resetStage = false): void {
    if (resetStage) filters.stage_id = null;
    router.get('/crm/leads', { ...filters }, { preserveScroll: true });
}
const form = useForm({
    pipeline_id:
        props.pipelines.find((pipeline) => pipeline.is_default)?.id ?? null,
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company: '',
    source: '',
    project_name: '',
    campaign_name: '',
    meta_form_id: '',
    meta_form_name: '',
    notes: '',
});
const activityForm = useForm({
    lead_id: '',
    type: 'call',
    notes: '',
    due_at: '',
});

function createLead(): void {
    form.post('/crm/leads', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function convertLead(lead: Lead): void {
    if (lead.conversionBlockedReason) return;
    if (
        confirm(`Convert ${lead.first_name} ${lead.last_name} into a contact?`)
    ) {
        router.post(
            `/crm/leads/${lead.id}/convert`,
            {},
            { preserveScroll: true },
        );
    }
}

function createActivity(): void {
    activityForm.post('/crm/activities', {
        preserveScroll: true,
        onSuccess: () => activityForm.reset('notes', 'due_at'),
    });
}
function assignLead(lead: Lead, event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    router.put(
        `/crm/leads/${lead.id}/assignment`,
        { assigned_to: value ? Number(value) : null },
        { preserveScroll: true },
    );
}
function completeFollowUp(id: number): void {
    router.post(`/crm/activities/${id}/complete`, {}, { preserveScroll: true });
}
function switchLeadView(view: 'board' | 'list'): void {
    leadView.value = view;
    localStorage.setItem('crm-lead-view', view);
    document.getElementById('crm-lead-results')?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
}
</script>

<template>
    <Head title="CRM leads" />

    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="CRM leads"
            description="Capture prospects and convert qualified leads into contacts and accounts."
        />

        <div aria-live="polite">
            <InputError
                v-for="(error, field) in page.props.errors"
                :key="field"
                :message="error"
            />
        </div>
        <Link href="/crm/pipeline-report" class="text-sm underline"
            >Pipeline reporting</Link
        >
        <Link
            v-if="canManageHierarchy"
            href="/crm/hierarchy"
            class="text-sm underline"
            >Departments and CRM access</Link
        >
        <Link
            v-if="canManagePipelines"
            href="/crm/pipelines"
            class="text-sm underline"
            >Manage CRM pipelines</Link
        >
        <Link href="/crm/assignment" class="text-sm underline"
            >Assignment routing and check-in</Link
        >
        <div
            class="flex flex-wrap items-center gap-2"
            role="group"
            aria-label="Lead view"
        >
            <span class="mr-1 text-sm font-medium">View leads:</span>
            <Button
                type="button"
                :variant="leadView === 'board' ? 'default' : 'outline'"
                :aria-pressed="leadView === 'board'"
                aria-controls="crm-lead-results"
                @click="switchLeadView('board')"
                >Kanban</Button
            >
            <Button
                type="button"
                :variant="leadView === 'list' ? 'default' : 'outline'"
                :aria-pressed="leadView === 'list'"
                aria-controls="crm-lead-results"
                @click="switchLeadView('list')"
                >List</Button
            >
        </div>
        <Card>
            <CardHeader><CardTitle>Pipeline overview</CardTitle></CardHeader>
            <CardContent class="space-y-4">
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="filterLeads()"
                >
                    <div>
                        <Label for="filter-pipeline">Pipeline</Label
                        ><select
                            id="filter-pipeline"
                            v-model="filters.pipeline_id"
                            class="h-9 rounded-md border px-3"
                            @change="filters.stage_id = null"
                        >
                            <option
                                v-for="pipeline in pipelines"
                                :key="pipeline.id"
                                :value="pipeline.id"
                            >
                                {{ pipeline.name }}
                                {{ !pipeline.active ? '(inactive)' : '' }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="filter-stage">Stage</Label
                        ><select
                            id="filter-stage"
                            v-model="filters.stage_id"
                            class="h-9 rounded-md border px-3"
                        >
                            <option :value="null">All stages</option>
                            <option
                                v-for="stage in selectedPipeline?.stages"
                                :key="stage.id"
                                :value="stage.id"
                            >
                                {{ stage.name }}
                            </option>
                        </select>
                    </div>
                    <div v-if="!assigneeScoped">
                        <Label for="filter-assignee">{{ t('Assignee') }}</Label
                        ><select
                            id="filter-assignee"
                            v-model="filters.assignee_id"
                            class="h-9 rounded-md border px-3"
                        >
                            <option :value="null">
                                {{
                                    limitedVisibility
                                        ? 'All accessible agents'
                                        : 'All agents'
                                }}
                            </option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select>
                    </div>
                    <p v-else class="text-muted-foreground self-end text-sm">
                        Showing leads within your access
                    </p>
                    <Button>{{ t('Apply filters') }}</Button>
                </form>
                <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                    <div
                        v-for="stage in stageCounts"
                        :key="stage.id"
                        class="rounded-md border p-3"
                        :style="{ borderTop: `4px solid ${stage.color}` }"
                    >
                        <p class="text-sm">
                            {{ stage.name }}
                            {{ !stage.active ? '(inactive)' : '' }}
                        </p>
                        <p class="text-xl font-semibold">{{ stage.count }}</p>
                    </div>
                </div>
            </CardContent>
        </Card>
        <Card v-if="canManageCrm">
            <CardHeader><CardTitle>Add lead</CardTitle></CardHeader>
            <CardContent>
                <form
                    class="grid gap-4 md:grid-cols-2"
                    @submit.prevent="createLead"
                >
                    <div class="space-y-2">
                        <Label for="lead-pipeline">Pipeline</Label
                        ><select
                            id="lead-pipeline"
                            v-model="form.pipeline_id"
                            class="h-9 w-full rounded-md border px-3"
                            required
                        >
                            <option
                                v-for="pipeline in pipelines.filter(
                                    (pipeline) => pipeline.active,
                                )"
                                :key="pipeline.id"
                                :value="pipeline.id"
                            >
                                {{ pipeline.name }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label for="first_name">First name</Label
                        ><Input
                            id="first_name"
                            v-model="form.first_name"
                            required
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="last_name">Last name</Label
                        ><Input
                            id="last_name"
                            v-model="form.last_name"
                            required
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="email">{{ t('Email') }}</Label
                        ><Input id="email" v-model="form.email" type="email" />
                    </div>
                    <div class="space-y-2">
                        <Label for="phone">{{ t('Phone') }}</Label
                        ><Input id="phone" v-model="form.phone" />
                    </div>
                    <div class="space-y-2">
                        <Label for="company">Company</Label
                        ><Input id="company" v-model="form.company" />
                    </div>
                    <div class="space-y-2">
                        <Label for="source">Source</Label
                        ><Input
                            id="source"
                            v-model="form.source"
                            placeholder="Referral, web, campaign…"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="project_name">Project</Label
                        ><Input id="project_name" v-model="form.project_name" />
                    </div>
                    <div class="space-y-2">
                        <Label for="campaign_name">Campaign</Label
                        ><Input
                            id="campaign_name"
                            v-model="form.campaign_name"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="meta_form_id">Meta form ID</Label
                        ><Input id="meta_form_id" v-model="form.meta_form_id" />
                    </div>
                    <div class="space-y-2">
                        <Label for="meta_form_name">Meta form name</Label
                        ><Input
                            id="meta_form_name"
                            v-model="form.meta_form_name"
                        />
                    </div>
                    <div class="md:col-span-2" aria-live="polite">
                        <InputError
                            v-for="(error, field) in form.errors"
                            :key="field"
                            :message="error"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="lead-notes">{{ t('Notes') }}</Label
                        ><Input
                            id="lead-notes"
                            v-model="form.notes"
                            maxlength="5000"
                        />
                    </div>
                    <Button class="w-fit" :disabled="form.processing"
                        >Create lead</Button
                    >
                </form>
            </CardContent>
        </Card>

        <Card v-if="canManageCrm && props.leads.length">
            <CardHeader><CardTitle>Log activity</CardTitle></CardHeader>
            <CardContent
                ><form
                    class="grid gap-4 md:grid-cols-4"
                    @submit.prevent="createActivity"
                >
                    <select
                        v-model="activityForm.lead_id"
                        class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                        required
                    >
                        <option disabled value="">Select lead</option>
                        <option
                            v-for="lead in props.leads.filter(
                                (lead) => !lead.converted,
                            )"
                            :key="lead.id"
                            :value="String(lead.id)"
                        >
                            {{ lead.first_name }} {{ lead.last_name }}
                        </option></select
                    ><select
                        v-model="activityForm.type"
                        class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option
                            v-for="type in [
                                'call',
                                'email',
                                'meeting',
                                'task',
                                'note',
                            ]"
                            :key="type"
                            :value="type"
                        >
                            {{ type }}
                        </option></select
                    ><Input
                        v-model="activityForm.notes"
                        placeholder="Outcome or next step"
                    /><Input
                        v-model="activityForm.due_at"
                        type="datetime-local"
                        aria-label="Follow-up due date and time (optional)"
                    /><Button :disabled="activityForm.processing"
                        >Log activity</Button
                    >
                </form></CardContent
            >
        </Card>

        <Card>
            <CardHeader><CardTitle>Open follow-ups</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="!followUps.length"
                    class="text-muted-foreground text-sm"
                >
                    No open follow-ups.
                </p>
                <div
                    v-for="followUp in followUps"
                    :key="followUp.id"
                    class="flex flex-wrap items-center gap-3 border-b pb-3"
                >
                    <div class="flex-1">
                        <p>
                            {{ followUp.lead?.first_name }}
                            {{ followUp.lead?.last_name }} · {{ followUp.type }}
                        </p>
                        <p class="text-muted-foreground text-sm">
                            {{ followUp.notes }} · due {{ followUp.due_at }}
                        </p>
                    </div>
                    <Badge v-if="followUp.is_overdue" variant="destructive"
                        >Overdue</Badge
                    >
                    <Button
                        v-if="canManageCrm"
                        size="sm"
                        @click="completeFollowUp(followUp.id)"
                        >{{ t('Complete') }}</Button
                    >
                </div>
            </CardContent>
        </Card>
        <div id="crm-lead-results" class="scroll-mt-4">
            <p class="mb-3 text-sm font-medium">
                {{ leadView === 'board' ? 'Kanban board' : 'Lead list' }} ·
                {{ leads.length }} shown
            </p>
            <CrmPipelineBoard
                v-if="leadView === 'board' && appliedPipeline"
                :key="props.filters.pipeline_id"
                :pipeline="appliedPipeline"
                :leads="leads"
                :can-manage="canManageCrm"
                :stage-counts="stageCounts"
            />
            <p
                v-if="leadView === 'board' && canManageCrm"
                class="text-muted-foreground text-sm"
            >
                To transfer a lead to another pipeline, switch to List.
            </p>
            <Card v-if="leadView === 'list'">
                <CardHeader><CardTitle>Leads</CardTitle></CardHeader>
                <CardContent class="space-y-3">
                    <p
                        v-if="!leads.length"
                        class="text-muted-foreground text-sm"
                    >
                        No leads yet.
                    </p>
                    <div
                        v-for="lead in leads"
                        :key="lead.id"
                        class="flex flex-wrap items-center gap-3 border-b pb-3 last:border-0 last:pb-0"
                    >
                        <div class="min-w-48 flex-1">
                            <p class="font-medium">
                                {{ lead.first_name }} {{ lead.last_name }}
                            </p>
                            <p class="text-muted-foreground text-sm">
                                {{
                                    lead.company ||
                                    lead.email ||
                                    lead.phone ||
                                    'No contact details'
                                }}
                            </p>
                            <p
                                v-if="lead.listing"
                                class="text-muted-foreground text-sm"
                            >
                                Listing {{ lead.listing.reference }}
                            </p>
                        </div>
                        <Badge
                            variant="secondary"
                            :style="{ borderColor: lead.stage.color }"
                            >{{ lead.stage.name }}</Badge
                        ><Badge v-if="lead.converted">Converted</Badge>
                        <select
                            v-if="canManageCrm && !lead.converted"
                            :value="lead.assigned_to ?? ''"
                            :aria-label="`Assign ${lead.first_name} ${lead.last_name}`"
                            class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                            @change="assignLead(lead, $event)"
                        >
                            <option value="">Unassigned</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select>
                        <span v-else class="text-muted-foreground text-sm">{{
                            lead.assignee?.name ?? 'Unassigned'
                        }}</span>
                        <Button
                            v-if="canManageCrm && !lead.converted"
                            size="sm"
                            variant="outline"
                            :disabled="!!lead.conversionBlockedReason"
                            :title="lead.conversionBlockedReason || undefined"
                            @click="convertLead(lead)"
                            >Convert</Button
                        >
                        <p
                            v-if="
                                canManageCrm &&
                                !lead.converted &&
                                lead.conversionBlockedReason
                            "
                            class="w-full text-sm"
                        >
                            Conversion blocked:
                            {{ lead.conversionBlockedReason }}
                        </p>
                        <details
                            v-if="canManageCrm && !lead.converted"
                            class="w-full"
                        >
                            <summary class="cursor-pointer text-sm">
                                Edit lead details
                            </summary>
                            <CrmLeadDetailsEditor :lead="lead" />
                        </details>
                        <CrmLeadStageEditor
                            v-if="canManageCrm && !lead.converted"
                            :key="`${lead.id}-${lead.current_stage_id}`"
                            :lead-id="lead.id"
                            :current-stage-id="lead.current_stage_id"
                            :transition-options="lead.transitionOptions"
                            :pipeline="
                                pipelines.find(
                                    (pipeline) =>
                                        pipeline.id === lead.pipeline_id,
                                )!
                            "
                        />
                        <details
                            v-if="canManageCrm && !lead.converted"
                            class="w-full"
                        >
                            <summary class="cursor-pointer text-sm">
                                Transfer to another pipeline
                            </summary>
                            <CrmLeadTransfer
                                :key="`${lead.id}-${lead.pipeline_id}-${lead.current_stage_id}`"
                                :lead-id="lead.id"
                                :pipeline-id="lead.pipeline_id"
                                :stage-id="lead.current_stage_id"
                                :stage-type="lead.stage.type"
                                :pipelines="pipelines"
                            />
                        </details>
                        <details
                            v-if="lead.history.length"
                            class="w-full text-sm"
                        >
                            <summary class="cursor-pointer">
                                Stage history
                            </summary>
                            <div
                                v-for="entry in lead.history"
                                :key="entry.id"
                                class="border-b py-2"
                            >
                                <p>
                                    {{
                                        entry.snapshot.from_pipeline &&
                                        entry.snapshot.from_pipeline !==
                                            entry.snapshot.pipeline
                                            ? `${entry.snapshot.from_pipeline} · `
                                            : ''
                                    }}{{
                                        entry.snapshot.from ?? 'Initial stage'
                                    }}
                                    →
                                    {{
                                        entry.snapshot.pipeline
                                            ? `${entry.snapshot.pipeline} · `
                                            : ''
                                    }}{{ entry.snapshot.to
                                    }}<span v-if="entry.snapshot.lost_reason">
                                        · {{ entry.snapshot.lost_reason }}</span
                                    >
                                </p>
                                <p class="text-muted-foreground">
                                    {{ entry.changed_at }} ·
                                    {{ entry.actor ?? 'System' }}
                                    {{ entry.notes ? `· ${entry.notes}` : '' }}
                                </p>
                            </div>
                        </details>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card v-if="activities.length"
            ><CardHeader><CardTitle>Recent activity</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><div
                    v-for="activity in activities"
                    :key="activity.id"
                    class="border-b pb-3 last:border-0 last:pb-0"
                >
                    <p class="font-medium">
                        {{ activity.type }} ·
                        {{
                            activity.lead
                                ? `${activity.lead.first_name} ${activity.lead.last_name}`
                                : 'Lead'
                        }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        {{ activity.notes || 'No note provided'
                        }}<span v-if="activity.creator">
                            · {{ activity.creator.name }}</span
                        >
                    </p>
                </div></CardContent
            ></Card
        >
    </div>
</template>
