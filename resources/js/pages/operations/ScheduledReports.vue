<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

const props = defineProps<{
    timezone: string;
    schedules: {
        id: number;
        name: string;
        format: string;
        frequency: string;
        local_time: string;
        weekday: number;
        enabled: boolean;
        next_run_at: string;
    }[];
    filters: {
        id: number;
        name: string;
        filters: Record<string, string | number>;
    }[];
    deliveries: {
        data: {
            id: number;
            format: string;
            status: string;
            scheduled_for: string;
            generated_at: string | null;
            failure_reason: string | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
const form = useForm({
    name: '',
    format: 'pdf',
    frequency: 'daily',
    local_time: '08:00',
    weekday: 1,
    filters: {} as Record<string, string | number>,
});
function selectFilter(event: Event): void {
    const id = Number((event.target as HTMLSelectElement).value);
    form.filters = props.filters.find((f) => f.id === id)?.filters ?? {};
}
function create(): void {
    form.post('/operations/scheduled-reports', {
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
function toggle(id: number, enabled: boolean): void {
    router.put(`/operations/scheduled-reports/${id}`, { enabled });
}
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const WEEKDAYS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];
const open = ref(false);
const tab = ref<'schedules' | 'deliveries'>('schedules');

type ScheduleRow = {
    id: number;
    name: string;
    format: string;
    when: string;
    next: string;
    status: string;
    actions: string;
};
type DeliveryRow = {
    id: number;
    format: string;
    scheduled: string;
    status: string;
    note: string;
    actions: string;
};
const scheduleRows = computed<ScheduleRow[]>(() =>
    props.schedules.map((schedule) => ({
        id: schedule.id,
        name: schedule.name,
        format: schedule.format.toUpperCase(),
        when: `${t(schedule.frequency)} · ${schedule.local_time}`,
        next: schedule.next_run_at,
        status: schedule.enabled ? t('Enabled') : t('Paused'),
        actions: '',
    })),
);
const deliveryRows = computed<DeliveryRow[]>(() =>
    props.deliveries.data.map((delivery) => ({
        id: delivery.id,
        format: delivery.format.toUpperCase(),
        scheduled: delivery.scheduled_for,
        status: delivery.status,
        note: delivery.failure_reason ?? '',
        actions: '',
    })),
);
const scheduleColumns = computed<DataTableColumn<ScheduleRow>[]>(() => [
    { key: 'name', label: t('Name'), sortable: true },
    { key: 'format', label: t('Format') },
    { key: 'when', label: t('Frequency') },
    { key: 'next', label: t('Next run'), sortable: true },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);
const deliveryColumns = computed<DataTableColumn<DeliveryRow>[]>(() => [
    { key: 'scheduled', label: t('Scheduled'), sortable: true },
    { key: 'format', label: t('Format') },
    { key: 'status', label: t('Status') },
    { key: 'note', label: t('Note') },
    { key: 'actions', label: '' },
]);
const scheduleOf = (id: number) =>
    props.schedules.find((schedule) => schedule.id === id);
</script>

<template>
    <Head :title="t('Private scheduled reports')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Private scheduled reports"
            description="PDF and XLSX operations reports delivered inside the app to you."
        >
            <template #actions>
                <Link href="/operations/reports" class="text-sm underline">{{
                    t('Operations reports and saved filters')
                }}</Link>
            </template>
        </PageHeader>
        <p class="text-muted-foreground text-sm">
            {{ t('Schedule times use') }} {{ props.timezone }}.
            {{
                t(
                    'A delayed scheduler generates one current report, then moves to the next scheduled time. Reports include at most 1000 jobs. Permissions are checked during generation and download.',
                )
            }}
        </p>

        <nav :aria-label="t('Report views')" class="flex gap-2">
            <button
                v-for="item in [
                    { key: 'schedules', label: 'Your schedules' },
                    { key: 'deliveries', label: 'Private deliveries' },
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
                {{ t(item.label) }}
            </button>
        </nav>

        <CrmSettingsTable
            v-if="tab === 'schedules'"
            :show-title="false"
            title="Your schedules"
            :columns="scheduleColumns"
            :rows="scheduleRows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            add-label="Create schedule"
            :selectable="false"
            searchable
            can-edit
            @add="open = true"
        >
            <template #cell-status="{ row }"
                ><Badge
                    :variant="
                        row.status === t('Enabled') ? 'secondary' : 'outline'
                    "
                    >{{ row.status }}</Badge
                ></template
            >
            <template #cell-actions="{ row }">
                <div class="flex justify-end">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="toggle(row.id, !scheduleOf(row.id)?.enabled)"
                        >{{
                            scheduleOf(row.id)?.enabled
                                ? t('Pause')
                                : t('Resume')
                        }}</Button
                    >
                </div>
            </template>
        </CrmSettingsTable>
        <template v-else>
            <CrmSettingsTable
                :show-title="false"
                title="Private deliveries"
                add-label=""
                :columns="deliveryColumns"
                :rows="deliveryRows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.scheduled"
                :selectable="false"
            >
                <template #cell-status="{ row }"
                    ><Badge variant="secondary">{{
                        row.status
                    }}</Badge></template
                >
                <template #cell-actions="{ row }">
                    <div class="flex justify-end">
                        <Button
                            v-if="row.status === 'ready'"
                            as-child
                            size="sm"
                            variant="outline"
                            ><a
                                :href="`/operations/scheduled-reports/deliveries/${row.id}`"
                                >{{ t('Download report') }}</a
                            ></Button
                        >
                    </div>
                </template>
            </CrmSettingsTable>
            <Pagination :links="props.deliveries.links" />
        </template>

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Create schedule')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t(
                            'PDF and XLSX operations reports delivered inside the app to you.',
                        )
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="schedule-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="sr-name">{{ t('Name') }}</Label
                        ><Input
                            id="sr-name"
                            v-model="form.name"
                            required
                            maxlength="100"
                        /><InputError :message="form.errors.name" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="sr-format">{{ t('Format') }}</Label>
                            <select
                                id="sr-format"
                                v-model="form.format"
                                :class="selectClass"
                            >
                                <option value="pdf">PDF</option>
                                <option value="xlsx">XLSX</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="sr-freq">{{ t('Frequency') }}</Label>
                            <select
                                id="sr-freq"
                                v-model="form.frequency"
                                :class="selectClass"
                            >
                                <option value="daily">{{ t('Daily') }}</option>
                                <option value="weekly">
                                    {{ t('Weekly') }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div v-if="form.frequency === 'weekly'" class="space-y-1">
                        <Label for="sr-day">{{ t('Weekday') }}</Label>
                        <select
                            id="sr-day"
                            v-model="form.weekday"
                            :class="selectClass"
                        >
                            <option
                                v-for="(day, index) in WEEKDAYS"
                                :key="day"
                                :value="index + 1"
                            >
                                {{ t(day) }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="sr-time">{{ t('Local time') }}</Label
                        ><Input
                            id="sr-time"
                            v-model="form.local_time"
                            type="time"
                            required
                        /><InputError :message="form.errors.local_time" />
                    </div>
                    <div class="space-y-1">
                        <Label for="sr-filter">{{ t('Saved filters') }}</Label>
                        <select
                            id="sr-filter"
                            :class="selectClass"
                            @change="selectFilter"
                        >
                            <option value="0">
                                {{ t('All jobs I can access') }}
                            </option>
                            <option
                                v-for="filter in props.filters"
                                :key="filter.id"
                                :value="filter.id"
                            >
                                {{ filter.name }}
                            </option>
                        </select>
                    </div>
                    <InputError
                        v-for="(error, key) in form.errors"
                        :key="key"
                        :message="error"
                    />
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="schedule-form"
                        :disabled="form.processing"
                        >{{ t('Create schedule') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>
    </div>
</template>
