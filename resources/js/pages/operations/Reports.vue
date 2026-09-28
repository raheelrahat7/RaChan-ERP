<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type {
    ReportCost,
    ReportFilters,
    ReportJob,
} from '@/types/operations-report';

const props = defineProps<{
    filters: ReportFilters;
    savedFilters: { id: number; name: string; filters: ReportFilters }[];
    generatedAt: string;
    timezone: string;
    summary: {
        statuses: Record<string, number>;
        aging: Record<string, number>;
        workload: {
            assignee_id: number | null;
            assignee: string;
            active: number;
            completed: number;
            on_hold: number;
        }[];
        costs: ReportCost[];
        preventive: {
            due_today: number;
            outstanding_overdue: number;
            completed_on_time: number;
            completed_late: number;
            completion_date_unknown: number;
            cancelled: number;
            completed_on_time_percent: number | null;
        };
        sla: Record<string, number>;
    };
    jobs: {
        data: ReportJob[];
        total: number;
        links: { label: string; url: string | null; active: boolean }[];
    };
    properties: { id: number; name: string }[];
    vendors: { id: number; name: string }[];
    members: { id: number; name: string }[];
}>();
const form = useForm({
    property_id: props.filters.property_id?.toString() ?? '',
    vendor_id: props.filters.vendor_id?.toString() ?? '',
    assigned_to: props.filters.assigned_to?.toString() ?? '',
    status: props.filters.status ?? '',
    priority: props.filters.priority ?? '',
    created_from: props.filters.created_from ?? '',
    created_to: props.filters.created_to ?? '',
});
const saved = useForm({ name: '', filters: {} as Record<string, string> });
function saveFilter(): void {
    saved.filters = form.data();
    saved.post('/operations/reports/filters', {
        preserveScroll: true,
        onSuccess: () => saved.reset('name'),
    });
}
function filterUrl(filters: ReportFilters): string {
    const parameters = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value !== null && value !== '') parameters.set(key, String(value));
    }
    return `/operations/reports?${parameters.toString()}`;
}
function removeFilter(id: number): void {
    router.delete(`/operations/reports/filters/${id}`, {
        preserveScroll: true,
    });
}
const exportUrl = computed(() => {
    const parameters = new URLSearchParams();
    for (const [key, value] of Object.entries(props.filters)) {
        if (value !== null && value !== '') parameters.set(key, String(value));
    }
    return `/operations/reports.csv?${parameters.toString()}`;
});
const total = computed(() =>
    Object.values(props.summary.statuses).reduce(
        (sum, count) => sum + count,
        0,
    ),
);
const preventiveMetrics = computed(() => [
    {
        label: 'Due today, unfinished',
        value: props.summary.preventive.due_today,
    },
    {
        label: 'Outstanding overdue',
        value: props.summary.preventive.outstanding_overdue,
    },
    {
        label: 'Completed on time',
        value: props.summary.preventive.completed_on_time,
    },
    { label: 'Completed late', value: props.summary.preventive.completed_late },
    {
        label: 'Completion date unknown',
        value: props.summary.preventive.completion_date_unknown,
    },
    { label: 'Cancelled', value: props.summary.preventive.cancelled },
]);
function label(value: string): string {
    return value.replaceAll('_', ' ');
}
function apply(): void {
    form.get('/operations/reports', { preserveState: false });
}
</script>

<template>
    <Link
        href="/operations/scheduled-reports"
        class="m-4 inline-block text-sm underline"
        >Private scheduled PDF/XLSX reports</Link
    >

    <Head title="Operations reports" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Card
            ><CardHeader><CardTitle>Your saved filters</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <p class="text-muted-foreground text-sm">
                    Saved filters use your current access when applied. Saving
                    an existing name replaces your saved values.
                </p>
                <div class="flex flex-wrap gap-3">
                    <div
                        v-for="item in savedFilters"
                        :key="item.id"
                        class="flex items-center gap-2"
                    >
                        <Link
                            :href="filterUrl(item.filters)"
                            class="text-sm underline"
                            >{{ item.name }}</Link
                        ><Button
                            variant="ghost"
                            size="sm"
                            :aria-label="`Remove saved filter ${item.name}`"
                            @click="removeFilter(item.id)"
                            >{{ t('Remove') }}</Button
                        >
                    </div>
                </div>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="saveFilter"
                >
                    <label class="text-sm"
                        >Save current form values as<Input
                            v-model="saved.name"
                            required
                            maxlength="100" /></label
                    ><Button :disabled="saved.processing">{{
                        t('Save filter')
                    }}</Button>
                    <p
                        v-for="(message, field) in saved.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                </form>
            </CardContent></Card
        >
        <div class="flex flex-wrap justify-between gap-4">
            <Heading
                title="Operations reports"
                description="Job status, aging, workload, costs, preventive results and SLA cycles."
            />
            <Link href="/operations" class="text-sm underline">{{
                t('Operations overview')
            }}</Link>
        </div>
        <Card
            ><CardContent class="space-y-4 pt-6">
                <form
                    class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    @submit.prevent="apply"
                >
                    <label class="space-y-1"
                        >{{ t('Property')
                        }}<select
                            v-model="form.property_id"
                            class="bg-background block w-full rounded-md border p-2"
                        >
                            <option value="">{{ t('All properties') }}</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select></label
                    >
                    <label class="space-y-1"
                        >{{ t('Vendor')
                        }}<select
                            v-model="form.vendor_id"
                            class="bg-background block w-full rounded-md border p-2"
                        >
                            <option value="">{{ t('All vendors') }}</option>
                            <option
                                v-for="vendor in vendors"
                                :key="vendor.id"
                                :value="String(vendor.id)"
                            >
                                {{ vendor.name }}
                            </option>
                        </select></label
                    >
                    <label class="space-y-1"
                        >{{ t('Assignee')
                        }}<select
                            v-model="form.assigned_to"
                            class="bg-background block w-full rounded-md border p-2"
                        >
                            <option value="">All visible assignees</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="String(member.id)"
                            >
                                {{ member.name }}
                            </option>
                        </select></label
                    >
                    <label class="space-y-1"
                        >{{ t('Status')
                        }}<select
                            v-model="form.status"
                            class="bg-background block w-full rounded-md border p-2"
                        >
                            <option value="">{{ t('All statuses') }}</option>
                            <option
                                v-for="status in [
                                    'open',
                                    'in_progress',
                                    'on_hold',
                                    'completed',
                                    'cancelled',
                                ]"
                                :key="status"
                                :value="status"
                            >
                                {{ label(status) }}
                            </option>
                        </select></label
                    >
                    <label class="space-y-1"
                        >{{ t('Priority')
                        }}<select
                            v-model="form.priority"
                            class="bg-background block w-full rounded-md border p-2"
                        >
                            <option value="">{{ t('All priorities') }}</option>
                            <option
                                v-for="priority in [
                                    'low',
                                    'medium',
                                    'high',
                                    'urgent',
                                ]"
                                :key="priority"
                                :value="priority"
                            >
                                {{ priority }}
                            </option>
                        </select></label
                    >
                    <label class="space-y-1"
                        >Jobs created from<Input
                            v-model="form.created_from"
                            type="date"
                    /></label>
                    <label class="space-y-1"
                        >Jobs created through<Input
                            v-model="form.created_to"
                            type="date"
                    /></label>
                    <Button :disabled="form.processing">{{
                        t('Apply filters')
                    }}</Button>
                </form>
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ error }}
                </p>
                <div class="flex flex-wrap gap-4 text-sm">
                    <a :href="exportUrl" class="underline"
                        >Export matching report as CSV</a
                    ><Link href="/operations/reports" class="underline">{{
                        t('Clear filters')
                    }}</Link>
                </div>
                <p class="text-muted-foreground text-sm">
                    Creation dates select jobs in {{ timezone }}. Status and
                    aging reflect current records; aging uses whole elapsed days
                    since job creation. Generated
                    {{ new Date(generatedAt).toLocaleString() }}.
                </p>
            </CardContent></Card
        >
        <div class="grid gap-4 md:grid-cols-2">
            <Card
                ><CardHeader
                    ><CardTitle
                        >Job status · {{ total }} jobs</CardTitle
                    ></CardHeader
                ><CardContent class="space-y-3">
                    <div
                        v-for="(count, status) in summary.statuses"
                        :key="status"
                    >
                        <p class="flex justify-between text-sm">
                            <span>{{ label(String(status)) }}</span
                            ><span>{{ count }}</span>
                        </p>
                        <div
                            class="bg-muted mt-1 h-2 rounded"
                            aria-hidden="true"
                        >
                            <div
                                class="bg-primary h-full rounded"
                                :style="{
                                    width: `${total ? (count / total) * 100 : 0}%`,
                                }"
                            />
                        </div>
                    </div> </CardContent
            ></Card>
            <Card
                ><CardHeader><CardTitle>Active job aging</CardTitle></CardHeader
                ><CardContent class="space-y-3"
                    ><p
                        v-for="(count, bucket) in summary.aging"
                        :key="bucket"
                        class="flex justify-between text-sm"
                    >
                        <span>{{ bucket }}</span
                        ><span>{{ count }}</span>
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Includes jobs on hold. Completed and cancelled jobs are
                        excluded.
                    </p></CardContent
                ></Card
            >
        </div>
        <Card
            ><CardHeader><CardTitle>Technician workload</CardTitle></CardHeader
            ><CardContent
                ><div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th class="p-2">{{ t('Assignee') }}</th>
                                <th class="p-2">{{ t('Active') }}</th>
                                <th class="p-2">{{ t('Completed') }}</th>
                                <th class="p-2">{{ t('On hold') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in summary.workload"
                                :key="row.assignee_id ?? 'unassigned'"
                                class="border-t"
                            >
                                <td class="p-2">{{ row.assignee }}</td>
                                <td class="p-2">{{ row.active }}</td>
                                <td class="p-2">{{ row.completed }}</td>
                                <td class="p-2">{{ row.on_hold }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!summary.workload.length" class="text-sm">
                    No matching jobs.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle
                    >Operational costs by currency</CardTitle
                ></CardHeader
            ><CardContent class="space-y-3"
                ><div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th class="p-2">{{ t('Currency') }}</th>
                                <th class="p-2">Estimated</th>
                                <th class="p-2">Manual actual</th>
                                <th class="p-2">Labor</th>
                                <th class="p-2">Material</th>
                                <th class="p-2">Job-card total</th>
                                <th class="p-2">Missing estimate / actual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in summary.costs"
                                :key="row.currency"
                                class="border-t"
                            >
                                <td class="p-2">{{ row.currency }}</td>
                                <td class="p-2">{{ row.estimated_cost }}</td>
                                <td class="p-2">{{ row.actual_cost }}</td>
                                <td class="p-2">{{ row.labor_cost }}</td>
                                <td class="p-2">{{ row.material_cost }}</td>
                                <td class="p-2">{{ row.recorded_cost }}</td>
                                <td class="p-2">
                                    {{ row.missing_estimates }} /
                                    {{ row.missing_actuals }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted-foreground text-sm">
                    Job-card totals include active labor/material lines and
                    exclude voided lines. Manual actual costs are separate. No
                    currency conversion or financial posting is implied.
                </p></CardContent
            ></Card
        >
        <div class="grid gap-4 md:grid-cols-2">
            <Card
                ><CardHeader
                    ><CardTitle
                        >Generated preventive-job results</CardTitle
                    ></CardHeader
                ><CardContent class="space-y-3"
                    ><p
                        v-for="metric in preventiveMetrics"
                        :key="metric.label"
                        class="flex justify-between text-sm"
                    >
                        <span>{{ metric.label }}</span
                        ><span>{{ metric.value }}</span>
                    </p>
                    <p class="text-sm">
                        On-time rate (known completion dates):
                        {{
                            summary.preventive.completed_on_time_percent ===
                            null
                                ? 'No dated completions'
                                : `${summary.preventive.completed_on_time_percent}%`
                        }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Generated jobs due by today, using the latest completion
                        and the organization's local due date. Future
                        occurrences and occurrences without generated jobs are
                        outside this count. Reopened jobs are outstanding again.
                    </p></CardContent
                ></Card
            >
            <Card
                ><CardHeader
                    ><CardTitle>SLA cycle results</CardTitle></CardHeader
                ><CardContent class="space-y-3"
                    ><p
                        v-for="(count, metric) in summary.sla"
                        :key="metric"
                        class="flex justify-between text-sm"
                    >
                        <span>{{ label(String(metric)) }}</span
                        ><span>{{ count }}</span>
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Includes preserved previous cycles for matching jobs.
                        Cancelled cycles are excluded from breaches. Jobs
                        without configured service targets have no SLA results.
                    </p></CardContent
                ></Card
            >
        </div>
        <Card
            ><CardHeader
                ><CardTitle
                    >Matching jobs · {{ jobs.total }}</CardTitle
                ></CardHeader
            ><CardContent class="space-y-4">
                <div
                    v-for="job in jobs.data"
                    :key="job.id"
                    class="space-y-2 rounded-md border p-3"
                >
                    <Link
                        :href="`/maintenance/${job.id}/job-card`"
                        class="font-medium underline"
                        >{{ job.reference }} · {{ job.title }}</Link
                    >
                    <p class="text-sm">
                        {{ job.property }} · {{ job.assignee }} ·
                        {{ label(job.status) }} · {{ job.priority
                        }}<span v-if="job.age_days !== null">
                            · {{ job.age_days }} days active</span
                        >
                    </p>
                    <p class="text-sm">
                        {{ job.currency }} · Estimate
                        {{ job.estimated_cost ?? 'Not recorded' }} · Manual
                        actual {{ job.actual_cost ?? 'Not recorded' }} ·
                        Job-card costs {{ job.recorded_cost }}
                    </p>
                    <p v-if="job.preventive_result" class="text-sm">
                        Preventive result: {{ label(job.preventive_result) }}
                    </p>
                    <details v-if="job.sla_cycles.length" class="text-sm">
                        <summary class="cursor-pointer">
                            SLA cycles ({{ job.sla_cycles.length }})
                        </summary>
                        <p
                            v-for="cycle in job.sla_cycles"
                            :key="cycle.id"
                            class="mt-2"
                        >
                            Cycle {{ cycle.cycle_number }} ·
                            {{ cycle.outcome ?? 'active' }} · Response
                            {{
                                cycle.response_breached
                                    ? 'breached'
                                    : 'within target'
                            }}
                            · Resolution
                            {{
                                cycle.resolution_breached
                                    ? 'breached'
                                    : 'within target'
                            }}
                        </p>
                    </details>
                </div>
                <p v-if="!jobs.data.length" class="text-sm">
                    No matching jobs.
                </p>
                <Pagination :links="jobs.links" /> </CardContent
        ></Card>
    </div>
</template>
