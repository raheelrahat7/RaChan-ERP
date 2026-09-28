<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
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
        onSuccess: () => form.reset(),
    });
}
function toggle(id: number, enabled: boolean): void {
    router.put(`/operations/scheduled-reports/${id}`, { enabled });
}
</script>
<template>
    <Head title="Private scheduled reports" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <Heading
            title="Private scheduled reports"
            description="PDF and XLSX operations reports delivered inside the app to you."
        />
        <Link href="/operations/reports" class="underline"
            >Operations reports and saved filters</Link
        >
        <p>
            Schedule times use {{ props.timezone }}. A delayed scheduler
            generates one current report, then moves to the next scheduled time.
            Reports include at most 1000 jobs. Permissions are checked during
            generation and download.
        </p>
        <form class="space-y-4 rounded border p-4" @submit.prevent="create">
            <label class="block"
                >{{ t('Name')
                }}<input
                    v-model="form.name"
                    required
                    maxlength="100"
                    class="ml-3 rounded border p-2"
            /></label>
            <label class="block"
                >{{ t('Format')
                }}<select v-model="form.format" class="ml-3 rounded border p-2">
                    <option value="pdf">PDF</option>
                    <option value="xlsx">XLSX</option>
                </select></label
            >
            <label class="block"
                >{{ t('Frequency')
                }}<select
                    v-model="form.frequency"
                    class="ml-3 rounded border p-2"
                >
                    <option value="daily">{{ t('Daily') }}</option>
                    <option value="weekly">{{ t('Weekly') }}</option>
                </select></label
            >
            <label v-if="form.frequency === 'weekly'" class="block"
                >{{ t('Weekday')
                }}<select
                    v-model="form.weekday"
                    class="ml-3 rounded border p-2"
                >
                    <option
                        v-for="(day, index) in [
                            'Monday',
                            'Tuesday',
                            'Wednesday',
                            'Thursday',
                            'Friday',
                            'Saturday',
                            'Sunday',
                        ]"
                        :key="day"
                        :value="index + 1"
                    >
                        {{ day }}
                    </option>
                </select></label
            >
            <label class="block"
                >{{ t('Local time')
                }}<input
                    v-model="form.local_time"
                    type="time"
                    required
                    class="ml-3 rounded border p-2"
            /></label>
            <label class="block"
                >{{ t('Saved filters')
                }}<select
                    class="ml-3 rounded border p-2"
                    @change="selectFilter"
                >
                    <option value="0">All jobs I can access</option>
                    <option
                        v-for="filter in props.filters"
                        :key="filter.id"
                        :value="filter.id"
                    >
                        {{ filter.name }}
                    </option>
                </select></label
            >
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                role="alert"
                class="text-destructive"
            >
                {{ error }}
            </p>
            <Button :disabled="form.processing">{{
                t('Create schedule')
            }}</Button>
        </form>
        <h2 class="font-semibold">{{ t('Your schedules') }}</h2>
        <ul class="space-y-3">
            <li
                v-for="schedule in props.schedules"
                :key="schedule.id"
                class="rounded border p-4"
            >
                <strong>{{ schedule.name }}</strong>
                <p>
                    {{ schedule.format.toUpperCase() }} ·
                    {{ schedule.frequency }} at {{ schedule.local_time }} ·
                    {{ schedule.enabled ? 'Enabled' : 'Paused' }}
                </p>
                <p>Next run: {{ schedule.next_run_at }}</p>
                <Button
                    variant="outline"
                    @click="toggle(schedule.id, !schedule.enabled)"
                    >{{ schedule.enabled ? 'Pause' : 'Resume' }}</Button
                >
            </li>
        </ul>
        <h2 class="font-semibold">{{ t('Private deliveries') }}</h2>
        <ul class="space-y-3">
            <li
                v-for="delivery in props.deliveries.data"
                :key="delivery.id"
                class="rounded border p-4"
            >
                <p>
                    {{ delivery.format.toUpperCase() }} ·
                    {{ delivery.status }} · Scheduled
                    {{ delivery.scheduled_for }}
                </p>
                <p v-if="delivery.failure_reason">
                    {{ delivery.failure_reason }}
                </p>
                <a
                    v-if="delivery.status === 'ready'"
                    :href="`/operations/scheduled-reports/deliveries/${delivery.id}`"
                    class="underline"
                    >{{ t('Download report') }}</a
                >
            </li>
        </ul>
        <Pagination :links="props.deliveries.links" />
    </div>
</template>
