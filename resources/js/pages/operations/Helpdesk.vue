<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { SlaCycle } from '@/types/sla';

const props = defineProps<{
    jobs: {
        data: {
            id: number;
            reference: string;
            title: string;
            priority: string;
            status: string;
            sla: SlaCycle | null;
        }[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: { status?: string; search?: string };
}>();
const filters = useForm({
    status: props.filters.status ?? '',
    search: props.filters.search ?? '',
});
function search(): void {
    filters.get('/operations/helpdesk', { preserveState: true });
}
</script>

<template>
    <Head title="Service helpdesk" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <Heading
            title="Service helpdesk"
            description="Track jobs, acknowledgements and service targets."
        />
        <Link href="/maintenance" class="text-sm underline">{{
            t('Maintenance')
        }}</Link>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="search">
            <label
                >Search jobs<Input
                    v-model="filters.search"
                    placeholder="Reference or title"
            /></label>
            <label
                >{{ t('Status')
                }}<select
                    v-model="filters.status"
                    class="bg-background block rounded-md border p-2"
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
                        {{ status.replaceAll('_', ' ') }}
                    </option>
                </select></label
            >
            <Button :disabled="filters.processing">Filter</Button>
        </form>
        <p v-if="!jobs.data.length">No matching jobs.</p>
        <div
            v-for="job in jobs.data"
            :key="job.id"
            class="space-y-2 rounded-md border p-4"
        >
            <Link
                :href="`/maintenance/${job.id}/job-card`"
                class="font-medium underline"
                >{{ job.reference }} · {{ job.title }}</Link
            >
            <p class="text-sm">
                {{ job.priority }} · {{ job.status.replaceAll('_', ' ') }}
            </p>
            <p v-if="job.sla" class="text-sm">
                Cycle {{ job.sla.cycle_number }} ·
                {{ job.sla.held_at ? 'On hold · ' : '' }}Response
                {{ job.sla.response_breached ? 'breached' : 'within target' }} ·
                Resolution
                {{
                    job.sla.outcome === 'cancelled'
                        ? 'cancelled'
                        : job.sla.resolution_breached
                          ? 'breached'
                          : 'within target'
                }}
            </p>
            <p v-else class="text-muted-foreground text-sm">
                No service targets configured
            </p>
        </div>
        <nav aria-label="Helpdesk pagination" class="flex gap-4">
            <Link
                v-if="jobs.prev_page_url"
                :href="jobs.prev_page_url"
                class="underline"
                >{{ t('Previous') }}</Link
            ><Link
                v-if="jobs.next_page_url"
                :href="jobs.next_page_url"
                class="underline"
                >{{ t('Next') }}</Link
            >
        </nav>
    </div>
</template>
