<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    MaintenanceBacklogRow,
    OperationsPage,
    OperationsSummary,
    OperationsWorkloadRow,
    PreventivePlanRow,
} from '@/types/operations';

const props = defineProps<{
    filters: { property_id: number | null; vendor_id: number | null };
    generatedAt: string;
    timezone: string;
    today: string;
    throughDate: string;
    summary: OperationsSummary;
    workload: OperationsWorkloadRow[];
    backlog: OperationsPage<MaintenanceBacklogRow>;
    plans: OperationsPage<PreventivePlanRow>;
    properties: { id: number; name: string }[];
    vendors: { id: number; name: string }[];
}>();
const form = useForm({
    property_id: props.filters.property_id?.toString() ?? '',
    vendor_id: props.filters.vendor_id?.toString() ?? '',
});
const exportUrl = computed(() => {
    const parameters = new URLSearchParams();
    if (props.filters.property_id !== null) {
        parameters.set('property_id', String(props.filters.property_id));
    }
    if (props.filters.vendor_id !== null) {
        parameters.set('vendor_id', String(props.filters.vendor_id));
    }
    return `/operations/overview.csv?${parameters.toString()}`;
});
const cards: { key: keyof OperationsSummary; label: string }[] = [
    { key: 'active', label: 'Active requests' },
    { key: 'overdue', label: 'Overdue requests' },
    { key: 'unassigned', label: 'Unassigned requests' },
    { key: 'urgent', label: 'Urgent requests' },
    { key: 'on_hold', label: 'On hold' },
    { key: 'due_plans', label: 'Preventive plans due' },
];
function apply(): void {
    form.get('/operations', { preserveState: false });
}
function dateTime(value: string): string {
    const date = new Date(
        value.includes('T') ? value : `${value.replace(' ', 'T')}Z`,
    );
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: props.timezone,
    }).format(date);
}
function label(value: string): string {
    return value.replaceAll('_', ' ');
}
</script>

<template>
    <Head title="Operations overview" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Operations overview"
            description="Review maintenance workload and upcoming preventive work."
        >
            <template #actions>
                <Link href="/operations/reports" class="text-sm underline">{{
                    t('Reports and dashboards')
                }}</Link>
                <Link href="/maintenance" class="text-sm underline">{{
                    t('Manage requests')
                }}</Link>
                <Link
                    href="/preventive-maintenance"
                    class="text-sm underline"
                    >{{ t('Manage preventive plans') }}</Link
                >
            </template>
        </PageHeader>
        <Card>
            <CardContent class="space-y-3 pt-6">
                <form
                    class="flex flex-wrap items-end gap-4"
                    @submit.prevent="apply"
                >
                    <div class="space-y-1">
                        <label
                            for="overview-property"
                            class="text-sm font-medium"
                            >{{ t('Property') }}</label
                        >
                        <select
                            id="overview-property"
                            v-model="form.property_id"
                            class="border-input bg-background block h-9 max-w-full rounded-md border px-3"
                            :aria-invalid="Boolean(form.errors.property_id)"
                            aria-describedby="overview-property-error"
                        >
                            <option value="">{{ t('All properties') }}</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select>
                        <InputError
                            id="overview-property-error"
                            :message="form.errors.property_id"
                        />
                    </div>
                    <div class="space-y-1">
                        <label
                            for="overview-vendor"
                            class="text-sm font-medium"
                            >{{ t('Vendor') }}</label
                        >
                        <select
                            id="overview-vendor"
                            v-model="form.vendor_id"
                            class="border-input bg-background block h-9 max-w-full rounded-md border px-3"
                            :aria-invalid="Boolean(form.errors.vendor_id)"
                            aria-describedby="overview-vendor-error"
                        >
                            <option value="">{{ t('All vendors') }}</option>
                            <option
                                v-for="vendor in vendors"
                                :key="vendor.id"
                                :value="String(vendor.id)"
                            >
                                {{ vendor.name }}
                            </option>
                        </select>
                        <InputError
                            id="overview-vendor-error"
                            :message="form.errors.vendor_id"
                        />
                    </div>
                    <Button :disabled="form.processing">{{
                        t('Apply filters')
                    }}</Button>
                    <Link href="/operations" class="text-sm underline">{{
                        t('Reset')
                    }}</Link>
                    <a :href="exportUrl" class="text-sm underline"
                        >Export applied filters</a
                    >
                </form>
                <p class="text-muted-foreground text-sm">
                    Updated {{ dateTime(generatedAt) }} · {{ timezone }}. Counts
                    reflect the current state. Export includes every matching
                    row.
                </p>
            </CardContent>
        </Card>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card v-for="card in cards" :key="card.key">
                <CardHeader
                    ><CardTitle class="text-sm">{{
                        card.label
                    }}</CardTitle></CardHeader
                >
                <CardContent class="text-3xl font-semibold tabular-nums">{{
                    summary[card.key]
                }}</CardContent>
            </Card>
        </div>
        <p class="text-muted-foreground text-sm">
            {{ summary.total }} total requests ·
            {{ summary.completed }} completed ·
            {{ summary.cancelled }} cancelled. Active includes open, in
            progress, and on hold. Overdue includes open or in-progress requests
            whose due time has passed.
        </p>
        <Card>
            <CardHeader><CardTitle>Staff workload</CardTitle></CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full min-w-[400px] text-sm">
                    <caption class="sr-only">
                        Active maintenance requests by assignee
                    </caption>
                    <thead>
                        <tr class="border-b text-left">
                            <th scope="col" class="py-2">
                                {{ t('Assignee') }}
                            </th>
                            <th scope="col" class="text-right">
                                {{ t('Active') }}
                            </th>
                            <th scope="col" class="text-right">Overdue</th>
                            <th scope="col" class="text-right">
                                {{ t('On hold') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in workload"
                            :key="row.assignee_id ?? 'unassigned'"
                            class="border-b"
                        >
                            <th scope="row" class="py-3 text-left font-medium">
                                {{ row.assignee }}
                            </th>
                            <td class="text-right">{{ row.active }}</td>
                            <td class="text-right">{{ row.overdue }}</td>
                            <td class="text-right">{{ row.on_hold }}</td>
                        </tr>
                        <tr v-if="workload.length === 0">
                            <td
                                colspan="4"
                                class="text-muted-foreground py-6 text-center"
                            >
                                No active requests match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>Active maintenance · {{ backlog.total }}</CardTitle>
                <p class="text-muted-foreground text-sm">
                    Overdue work first, then priority and due time.
                </p></CardHeader
            >
            <CardContent class="space-y-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-sm">
                        <caption class="sr-only">
                            Active maintenance backlog
                        </caption>
                        <thead>
                            <tr class="border-b text-left">
                                <th scope="col" class="py-2">Request</th>
                                <th scope="col">Property / vendor</th>
                                <th scope="col">{{ t('Assignee') }}</th>
                                <th scope="col">Priority / status</th>
                                <th scope="col">{{ t('Due') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in backlog.data"
                                :key="row.id"
                                class="border-b"
                            >
                                <th
                                    scope="row"
                                    class="max-w-xs py-3 pr-3 text-left font-medium"
                                >
                                    <div>{{ row.reference }}</div>
                                    <div class="font-normal break-words">
                                        {{ row.title }}
                                    </div>
                                </th>
                                <td class="pr-3">
                                    <div>
                                        {{
                                            row.property ??
                                            'Unavailable property'
                                        }}
                                    </div>
                                    <div class="text-muted-foreground">
                                        {{ row.vendor ?? 'No vendor' }}
                                    </div>
                                </td>
                                <td class="pr-3">{{ row.assignee }}</td>
                                <td class="pr-3">
                                    <div class="capitalize">
                                        {{ row.priority }}
                                    </div>
                                    <div
                                        class="text-muted-foreground capitalize"
                                    >
                                        {{ label(row.status) }}
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        {{
                                            row.due_at
                                                ? dateTime(row.due_at)
                                                : 'No due date'
                                        }}
                                    </div>
                                    <Badge
                                        v-if="row.overdue"
                                        variant="destructive"
                                        class="mt-1"
                                        >Overdue</Badge
                                    >
                                </td>
                            </tr>
                            <tr v-if="backlog.data.length === 0">
                                <td
                                    colspan="5"
                                    class="text-muted-foreground py-6 text-center"
                                >
                                    No active requests on this page.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination
                    :links="backlog.links"
                    aria-label="Maintenance request pages"
                />
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>Preventive plans · {{ plans.total }}</CardTitle>
                <p class="text-muted-foreground text-sm">
                    Active plans due through {{ throughDate }}, including
                    earlier unfinished schedules. Today is {{ today }} in
                    {{ timezone }}.
                </p></CardHeader
            >
            <CardContent class="space-y-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[600px] text-sm">
                        <caption class="sr-only">
                            Due and upcoming preventive maintenance plans
                        </caption>
                        <thead>
                            <tr class="border-b text-left">
                                <th scope="col" class="py-2">Plan</th>
                                <th scope="col">{{ t('Property') }}</th>
                                <th scope="col">{{ t('Vendor') }}</th>
                                <th scope="col">Next due</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in plans.data"
                                :key="row.id"
                                class="border-b"
                            >
                                <th
                                    scope="row"
                                    class="py-3 pr-3 text-left font-medium"
                                >
                                    {{ row.title }}
                                </th>
                                <td class="pr-3">
                                    {{ row.property ?? 'Unavailable property' }}
                                </td>
                                <td class="pr-3">
                                    {{ row.vendor ?? 'No vendor' }}
                                </td>
                                <td>
                                    <div>{{ row.next_due_on }}</div>
                                    <Badge
                                        class="mt-1"
                                        :variant="
                                            row.next_due_on <= today
                                                ? 'destructive'
                                                : 'secondary'
                                        "
                                        >{{
                                            row.next_due_on <= today
                                                ? 'Due'
                                                : 'Upcoming'
                                        }}</Badge
                                    >
                                </td>
                            </tr>
                            <tr v-if="plans.data.length === 0">
                                <td
                                    colspan="4"
                                    class="text-muted-foreground py-6 text-center"
                                >
                                    No active plans due through
                                    {{ throughDate }} on this page.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination
                    :links="plans.links"
                    aria-label="Preventive plan pages"
                />
            </CardContent>
        </Card>
    </div>
</template>
