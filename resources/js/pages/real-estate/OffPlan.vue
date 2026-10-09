<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import OffPlanProjectSheet from '@/components/OffPlanProjectSheet.vue';
import WorkflowStatusesSheet from '@/components/WorkflowStatusesSheet.vue';
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
import InputError from '@/components/InputError.vue';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
import {
    countFor,
    moneyText,
    projectQuery,
    soldPercent,
    statusChoices,
    statusLabel,
} from '@/lib/offplan-projects';
import type { DataTableColumn } from '@/lib/data-table';
import type { ProjectRow, ProjectStatus } from '@/lib/offplan-projects';

type Paged = {
    data: ProjectRow[];
    current_page: number;
    last_page: number;
    total: number;
};
type Row = ProjectRow & {
    workflow: string;
    units: string;
    startingText: string;
    commission: string;
    broker: string;
    soldText: string;
    actions: string;
};

const props = defineProps<{
    developers: { id: number; name: string }[];
    brokers?: { id: number; name: string }[];
    workflowStatuses?: ProjectStatus[];
    canManage: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Off-Plan Projects', href: '/real-estate/off-plan' },
        ],
    },
});

const { t } = useLocale();
const dialogOpen = ref(false);
const statusesOpen = ref(false);
const editOpen = ref(false);
const editing = ref<ProjectRow | null>(null);

const projects = ref<Paged>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const statuses = ref<ProjectStatus[]>(props.workflowStatuses ?? []);
const counts = ref<{ code: string; total: number }[]>([]);
const tab = ref('');
const query = ref('');
const loading = ref(true);
const loadError = ref('');
let timer: number | null = null;

const form = useForm({
    developer_id: null as number | null,
    cost_centre_id: '',
    code: '',
    name: '',
    emirate: '',
    location: '',
    completion_on: '',
    commission_rate: '',
    workflow_status: '',
    launch_on: '',
    handover_on: '',
    assigned_broker_id: '',
});

const strip = computed(() => statusChoices(statuses.value, tab.value));
const rows = computed<Row[]>(() =>
    projects.value.data.map((project) => ({
        ...project,
        workflow: statusLabel(statuses.value, project.workflow_status),
        units: `${project.units_available} / ${project.units_total}`,
        soldText: `${project.units_sold} (${soldPercent(project)}%)`,
        startingText: moneyText(project.starting_price_aed),
        commission: project.commission_rate
            ? `${project.commission_rate}%`
            : '—',
        broker: project.assigned_broker_name ?? '—',
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'code', label: t('Code') },
    { key: 'name', label: t('Project') },
    { key: 'developer_name', label: t('Developer') },
    { key: 'emirate', label: t('Emirate') },
    { key: 'workflow', label: t('Workflow') },
    { key: 'units', label: t('Available / total') },
    { key: 'soldText', label: t('Sold') },
    { key: 'startingText', label: t('Starting price'), align: 'end' },
    { key: 'commission', label: t('Commission'), align: 'end' },
    { key: 'launch_on', label: t('Launch') },
    { key: 'handover_on', label: t('Handover') },
    { key: 'broker', label: t('Broker') },
    { key: 'actions', label: '' },
]);

async function load(page = 1): Promise<void> {
    loading.value = true;
    try {
        const data = await apiJson<{
            projects: Paged;
            workflowStatuses: ProjectStatus[];
            statusCounts: { code: string; total: number }[];
        }>(
            `/real-estate/off-plan/projects/data?${projectQuery(query.value, tab.value, page)}`,
        );
        projects.value = data.projects;
        statuses.value = data.workflowStatuses;
        counts.value = data.statusCounts;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load projects.');
    } finally {
        loading.value = false;
    }
}
watch(tab, () => void load(1));
watch(query, () => {
    if (timer) {
        clearTimeout(timer);
    }
    timer = window.setTimeout(() => void load(1), 300);
});
onMounted(() => void load());
onBeforeUnmount(() => timer && clearTimeout(timer));

function openCreate(): void {
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}
function startEdit(project: ProjectRow): void {
    editing.value = project;
    editOpen.value = true;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        cost_centre_id:
            data.cost_centre_id === '' ? null : Number(data.cost_centre_id),
        assigned_broker_id:
            data.assigned_broker_id === ''
                ? null
                : Number(data.assigned_broker_id),
        workflow_status:
            data.workflow_status === '' ? null : data.workflow_status,
    })).post('/real-estate/off-plan/projects', {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            void load(projects.value.current_page);
        },
    });
}
</script>

<template>
    <Head :title="t('Off-Plan Projects')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader eyebrow="Real Estate" title="Off-Plan Projects">
            <template #actions>
                <Button
                    v-if="canManage"
                    variant="outline"
                    @click="statusesOpen = true"
                    >{{ t('Project statuses') }}</Button
                >
                <Button v-if="canManage" @click="openCreate">{{
                    t('New project')
                }}</Button>
            </template>
        </PageHeader>

        <nav :aria-label="t('Project status')" class="flex flex-wrap gap-1.5">
            <button
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-medium"
                :class="
                    tab === ''
                        ? 'bg-primary text-primary-foreground border-primary'
                        : 'hover:bg-muted'
                "
                @click="tab = ''"
            >
                {{ t('All') }}
            </button>
            <button
                v-for="status in strip"
                :key="status.code"
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-medium"
                :class="
                    tab === status.code
                        ? 'bg-primary text-primary-foreground border-primary'
                        : 'hover:bg-muted'
                "
                @click="tab = status.code"
            >
                {{ status.name }} ({{ countFor(counts, status.code) }})
            </button>
        </nav>
        <Input
            v-model="query"
            type="search"
            class="w-full sm:w-72"
            :placeholder="t('Name, code or location')"
            :aria-label="t('Search projects')"
        />
        <DataTable
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            :loading="loading"
            :error="loadError || null"
            :empty-title="t('No off-plan projects yet.')"
            max-height=""
            @retry="load(projects.current_page)"
        >
            <template #cell-code="{ row }">
                <Link
                    :href="`/real-estate/off-plan/${row.id}`"
                    class="text-accent-text font-medium hover:underline"
                    >{{ row.code }}</Link
                >
            </template>
            <template #cell-workflow="{ row }">
                <Badge variant="secondary">{{ row.workflow }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <Button
                    v-if="row.permissions.edit"
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="startEdit(row)"
                    >{{ t('Edit') }}</Button
                >
            </template>
        </DataTable>
        <div
            v-if="projects.last_page > 1"
            class="flex items-center justify-center gap-3 text-sm"
        >
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="projects.current_page <= 1"
                @click="load(projects.current_page - 1)"
                >{{ t('Previous') }}</Button
            >
            <span>{{ projects.current_page }} / {{ projects.last_page }}</span>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="projects.current_page >= projects.last_page"
                @click="load(projects.current_page + 1)"
                >{{ t('Next') }}</Button
            >
        </div>
        <OffPlanProjectSheet
            v-model:open="editOpen"
            :project="editing"
            :statuses="statuses"
            :brokers="brokers ?? []"
            @saved="load(projects.current_page)"
        />
        <WorkflowStatusesSheet
            v-model:open="statusesOpen"
            :statuses="statuses"
            base-url="/real-estate/off-plan/project-statuses"
            title="Project workflow statuses"
            description="Labels for the project status tabs. Archive a status to stop new use."
            :edit-defaults="false"
            @changed="load(projects.current_page)"
        />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>{{
                        t('New project')
                    }}</DialogTitle></DialogHeader
                >
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-1.5">
                        <Label>{{ t('Developer') }}</Label>
                        <Select
                            :model-value="
                                form.developer_id
                                    ? String(form.developer_id)
                                    : ''
                            "
                            @update:model-value="
                                form.developer_id = Number($event)
                            "
                        >
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="developer in developers"
                                    :key="developer.id"
                                    :value="String(developer.id)"
                                    >{{ developer.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.developer_id" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="project-cost-centre"
                            >{{ t('Cost centre ID') }}
                            <span class="text-muted-foreground font-normal"
                                >({{ t('optional') }})</span
                            ></Label
                        >
                        <Input
                            id="project-cost-centre"
                            v-model="form.cost_centre_id"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.cost_centre_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-code">{{ t('Code') }}</Label>
                            <Input id="project-code" v-model="form.code" />
                            <InputError :message="form.errors.code" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-name">{{ t('Name') }}</Label>
                            <Input id="project-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-emirate">{{
                                t('Emirate')
                            }}</Label>
                            <Input
                                id="project-emirate"
                                v-model="form.emirate"
                            />
                            <InputError :message="form.errors.emirate" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-location"
                                >{{ t('Location') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="project-location"
                                v-model="form.location"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-completion"
                                >{{ t('Completion date') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="project-completion"
                                v-model="form.completion_on"
                                type="date"
                            />
                            <InputError :message="form.errors.completion_on" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-commission"
                                >{{ t('Commission rate %') }}
                                <span class="text-muted-foreground font-normal"
                                    >({{ t('optional') }})</span
                                ></Label
                            >
                            <Input
                                id="project-commission"
                                v-model="form.commission_rate"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                            />
                            <InputError
                                :message="form.errors.commission_rate"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-launch">{{
                                t('Launch date')
                            }}</Label>
                            <Input
                                id="project-launch"
                                v-model="form.launch_on"
                                type="date"
                            />
                            <InputError :message="form.errors.launch_on" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-handover">{{
                                t('Handover date')
                            }}</Label>
                            <Input
                                id="project-handover"
                                v-model="form.handover_on"
                                type="date"
                            />
                            <InputError :message="form.errors.handover_on" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="project-wf">{{
                                t('Workflow status')
                            }}</Label>
                            <select
                                id="project-wf"
                                v-model="form.workflow_status"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="status in statusChoices(
                                        statuses,
                                        '',
                                    )"
                                    :key="status.code"
                                    :value="status.code"
                                >
                                    {{ status.name }}
                                </option>
                            </select>
                            <InputError
                                :message="form.errors.workflow_status"
                            />
                        </div>
                        <div
                            v-if="brokers?.length"
                            class="flex flex-col gap-1.5"
                        >
                            <Label for="project-broker">{{
                                t('Assigned broker')
                            }}</Label>
                            <select
                                id="project-broker"
                                v-model="form.assigned_broker_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="broker in brokers"
                                    :key="broker.id"
                                    :value="String(broker.id)"
                                >
                                    {{ broker.name }}
                                </option>
                            </select>
                            <InputError
                                :message="form.errors.assigned_broker_id"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            t('Create project')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
