<script setup lang="ts">
import type { FollowUp, ServerActivityBoard } from '@/lib/crm-activity-views';
import type { FilterPreset } from '@/lib/crm-filter-presets';
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { reactive, computed, onMounted, ref, watch } from 'vue';
import CrmLeadDetailsEditor from '@/components/CrmLeadDetailsEditor.vue';
import CustomLeadFields from '@/components/CustomLeadFields.vue';
import CrmPipelineBoard from '@/components/CrmPipelineBoard.vue';
import CrmLeadStageEditor from '@/components/CrmLeadStageEditor.vue';
import CrmLeadTransfer from '@/components/CrmLeadTransfer.vue';
import InputError from '@/components/InputError.vue';
import type { Pipeline, Stage, TransitionOption } from '@/types/crm-pipeline';
import CrmLeadCreateSheet from '@/components/CrmLeadCreateSheet.vue';
import CrmLeadActivitiesBoard from '@/components/CrmLeadActivitiesBoard.vue';
import CrmLeadCalendar from '@/components/CrmLeadCalendar.vue';
import CrmLeadFilterPanel from '@/components/CrmLeadFilterPanel.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ChevronDown, Plus } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Lead = {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    company: string | null;
    city: string | null;
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
    custom_fields: Record<string, string | number | boolean | string[] | null>;
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
type FilterClause = {
    field: string;
    operator: string;
    value: string;
    to?: string;
};
type FilterField = {
    key: string;
    label: string;
    type: string;
    group: string;
    options: string[];
};

const props = defineProps<{
    pipelines: Pipeline[];
    stageCounts: (Stage & { count: number; amount: number | null })[];
    amountField: { key: string; name: string } | null;
    filters: {
        pipeline_id: number;
        stage_id: number | null;
        assignee_id: number | null;
        q: string;
        filters: FilterClause[];
        page: number;
    };
    filterCatalog: FilterField[];
    activityBoard: ServerActivityBoard;
    leadPreferences: {
        selected_field_keys: string[] | null;
        presets: FilterPreset[];
    };
    filteredTotal: number;
    canManagePipelines: boolean;
    canManageHierarchy: boolean;
    assigneeScoped: boolean;
    limitedVisibility: boolean;
    leads: Lead[];
    activities: Activity[];
    canManageCrm: boolean;
    canExportLeads: boolean;
    members: { id: number; name: string }[];
    customFields: {
        id: number;
        key: string;
        name: string;
        type: string;
        options: string[] | null;
        required: boolean;
    }[];
    editableCustomFieldKeys: string[];
    followUps: (Activity & { is_overdue: boolean })[];
}>();

const page = usePage();
const viewLabels = computed<Record<LeadView, string>>(() => ({
    board: t('Kanban'),
    list: t('List'),
    activities: t('Activities'),
    calendar: t('Calendar'),
}));
const tools = computed(() =>
    [
        {
            href: '/crm/pipeline-report',
            label: 'Pipeline reporting',
            show: true,
        },
        {
            href: '/crm/hierarchy',
            label: 'Departments and CRM access',
            show: props.canManageHierarchy,
        },
        {
            href: '/crm/pipelines',
            label: 'Manage CRM pipelines',
            show: props.canManagePipelines,
        },
        {
            href: '/crm/custom-fields',
            label: 'CRM field settings',
            show: props.canManageHierarchy,
        },
        {
            href: '/crm/automation',
            label: 'Automation rules',
            show: props.canManageHierarchy,
        },
        {
            href: '/crm/assignment',
            label: 'Assignment routing and check-in',
            show: true,
        },
        {
            href: '/crm/leads/import',
            label: 'Import leads',
            show: props.canManageCrm,
        },
        {
            href: '/crm/leads/export',
            label: 'Export leads',
            show: props.canExportLeads,
        },
    ].filter((tool) => tool.show),
);
const addLeadOpen = ref(false);
type LeadView = 'board' | 'list' | 'activities' | 'calendar';
const leadViews: LeadView[] = ['board', 'list', 'activities', 'calendar'];
const leadView = ref<LeadView>('board');
const calendarActivities = ref<FollowUp[]>([]);
const calendarLoading = ref(false);
const calendarError = ref('');
watch(
    [leadView, () => props.filters, () => props.followUps],
    async (_, __, onCleanup) => {
        if (leadView.value !== 'calendar') return;
        const controller = new AbortController();
        onCleanup(() => controller.abort());
        calendarLoading.value = true;
        calendarError.value = '';
        calendarActivities.value = [];
        const items: FollowUp[] = [];
        try {
            let activityPage = 1;
            let lastPage = 1;
            do {
                const query = new URLSearchParams();
                for (const key of [
                    'pipeline_id',
                    'stage_id',
                    'assignee_id',
                    'q',
                ] as const) {
                    const value = props.filters[key];
                    if (value != null) query.set(key, String(value));
                }
                props.filters.filters.forEach((clause, index) => {
                    Object.entries(clause).forEach(([key, value]) =>
                        query.set(`filters[${index}][${key}]`, value),
                    );
                });
                query.set('activity_page', String(activityPage));
                const response = await fetch(`/crm/activities?${query}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok)
                    throw new Error('Unable to load calendar activities.');
                const result = await response.json();
                lastPage = result.activities.last_page;
                for (const activity of result.activities.data) {
                    if (
                        activity.completed_at === null &&
                        activity.due_at &&
                        activity.subject
                    )
                        items.push({
                            ...activity,
                            lead: activity.subject,
                            is_overdue: false,
                        });
                }
                activityPage++;
            } while (activityPage <= lastPage);
            calendarActivities.value = items;
        } catch (error) {
            if (!controller.signal.aborted)
                calendarError.value =
                    error instanceof Error
                        ? error.message
                        : 'Unable to load calendar activities.';
        } finally {
            if (!controller.signal.aborted) calendarLoading.value = false;
        }
    },
);
onMounted(() => {
    const saved = localStorage.getItem('crm-lead-view');
    if (leadViews.includes(saved as LeadView))
        leadView.value = saved as LeadView;
});
const filters = reactive({
    ...props.filters,
    filters: [...props.filters.filters],
});
const userId = computed(() => {
    const id = (page.props.auth as { user?: { id?: number } } | undefined)?.user
        ?.id;

    return typeof id === 'number' ? id : null;
});
const appliedPipeline = computed(() =>
    props.pipelines.find(
        (pipeline) => pipeline.id === props.filters.pipeline_id,
    ),
);
function filterLeads(resetStage = false): void {
    if (resetStage) filters.stage_id = null;
    filters.page = 1;
    router.get('/crm/leads', { ...filters }, { preserveScroll: true });
}
function changeActivityPage(activity_page: number): void {
    router.get(
        '/crm/leads',
        { ...props.filters, activity_page },
        { preserveScroll: true, preserveState: true },
    );
}
function changePage(page: number): void {
    router.get(
        '/crm/leads',
        { ...props.filters, page },
        { preserveScroll: true },
    );
}
function resetFilters(): void {
    filters.assignee_id = null;
    filterLeads(true);
}
const activityForm = useForm({
    lead_id: '',
    type: 'call',
    notes: '',
    due_at: '',
});

function openAddLead(): void {
    addLeadOpen.value = true;
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
function switchLeadView(view: LeadView): void {
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

    <div class="flex w-full flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-display text-3xl leading-tight font-medium">
                {{ t('CRM leads') }}
            </h1>
            <Button
                v-if="canManageCrm"
                type="button"
                @click="addLeadOpen = true"
            >
                <Plus class="size-4" aria-hidden="true" />{{ t('Create') }}
            </Button>
            <CrmLeadFilterPanel
                :model-value="filters"
                class="min-w-64"
                :catalog="filterCatalog"
                :preferences="leadPreferences"
                :pipelines="pipelines"
                :members="members"
                :assignee-scoped="assigneeScoped"
                :limited-visibility="limitedVisibility"
                :user-id="userId"
                @apply="filterLeads"
                @reset="resetFilters"
            />
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button type="button" variant="outline">
                        {{ t('Extensions') }}
                        <ChevronDown class="size-4" aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-60">
                    <DropdownMenuItem
                        v-for="tool in tools"
                        :key="tool.href"
                        as-child
                    >
                        <Link :href="tool.href">{{ t(tool.label) }}</Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <div aria-live="polite">
            <InputError
                v-for="(error, field) in page.props.errors"
                :key="field"
                :message="error"
            />
        </div>
        <div
            class="flex flex-wrap items-center gap-2"
            role="group"
            :aria-label="t('Lead view')"
        >
            <Button
                v-for="view in leadViews"
                :key="view"
                type="button"
                size="sm"
                :variant="leadView === view ? 'default' : 'outline'"
                :aria-pressed="leadView === view"
                aria-controls="crm-lead-results"
                @click="switchLeadView(view)"
                >{{ viewLabels[view] }}</Button
            >
            <span class="text-muted-foreground ms-2 text-sm">
                {{ leads.length }} {{ t('of') }} {{ filteredTotal }}
                {{ t('shown') }}
            </span>
        </div>
        <CrmLeadCreateSheet
            v-if="canManageCrm"
            v-model:open="addLeadOpen"
            :pipelines="pipelines"
            :pipeline-id="filters.pipeline_id"
            :members="members"
            :custom-fields="
                customFields.filter((field) =>
                    editableCustomFieldKeys.includes(field.key),
                )
            "
            :user-id="userId"
        />

        <details v-if="canManageCrm && props.leads.length" class="group">
            <summary class="cursor-pointer text-sm font-medium">
                {{ t('Log activity') }}
            </summary>
            <Card class="mt-2">
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
        </details>

        <details class="group">
            <summary class="cursor-pointer text-sm font-medium">
                {{ t('Open follow-ups') }} ({{ followUps.length }})
            </summary>
            <Card class="mt-2">
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
                                {{ followUp.lead?.last_name }} ·
                                {{ followUp.type }}
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
        </details>
        <div id="crm-lead-results" class="scroll-mt-4">
            <CrmPipelineBoard
                v-if="leadView === 'board' && appliedPipeline"
                :key="props.filters.pipeline_id"
                :pipeline="appliedPipeline"
                :leads="leads"
                :can-manage="canManageCrm"
                :stage-counts="stageCounts"
                :amount-field="amountField"
                :can-add="canManageCrm"
                :members="members"
                @add="openAddLead"
            />
            <CrmLeadActivitiesBoard
                v-if="leadView === 'activities'"
                :board="activityBoard"
                @page="changeActivityPage"
            />
            <p v-if="leadView === 'calendar' && calendarLoading" role="status">
                {{ t('Loading activities…') }}
            </p>
            <p
                v-if="leadView === 'calendar' && calendarError"
                role="alert"
                class="text-destructive"
            >
                {{ calendarError }}
            </p>
            <CrmLeadCalendar
                v-if="
                    leadView === 'calendar' &&
                    !calendarLoading &&
                    !calendarError
                "
                :follow-ups="calendarActivities"
                :timezone="activityBoard.timezone"
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
                                <Link
                                    :href="`/crm/leads/${lead.id}`"
                                    class="hover:underline"
                                    >{{ lead.first_name }}
                                    {{ lead.last_name }}</Link
                                >
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
                            <CrmLeadDetailsEditor
                                :lead="lead"
                                :custom-fields="
                                    customFields.filter((field) =>
                                        editableCustomFieldKeys.includes(
                                            field.key,
                                        ),
                                    )
                                "
                                :members="members"
                            />
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
        <div
            v-if="filteredTotal > 50"
            class="flex items-center justify-end gap-3"
        >
            <Button
                variant="outline"
                :disabled="props.filters.page <= 1"
                @click="changePage(props.filters.page - 1)"
                >Previous</Button
            ><span class="text-sm"
                >Page {{ props.filters.page }} of
                {{ Math.ceil(filteredTotal / 50) }}</span
            ><Button
                variant="outline"
                :disabled="props.filters.page * 50 >= filteredTotal"
                @click="changePage(props.filters.page + 1)"
                >Next</Button
            >
        </div>

        <details v-if="activities.length" class="group">
            <summary class="cursor-pointer text-sm font-medium">
                {{ t('Recent activity') }}
            </summary>
            <Card class="mt-2"
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
        </details>
    </div>
</template>
