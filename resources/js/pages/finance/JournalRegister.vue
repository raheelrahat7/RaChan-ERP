<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Entry = {
    id: number;
    reference: string;
    source_reference: string | null;
    event: string;
    posted_on: string;
    debit_total: string;
    reversal_of_id: number | null;
    lines: {
        id: number;
        debit: string;
        credit: string;
        description: string | null;
        account: { code: string; name: string } | null;
    }[];
};
const props = defineProps<{
    filters: { from: string; to: string; event: string };
    events: string[];
    entries: {
        data: Entry[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const filters = reactive({ ...props.filters });
const query = computed(() => {
    const params = new URLSearchParams();
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.event) params.set('event', filters.event);
    return params.toString();
});
function applyFilters(): void {
    router.get(
        '/accounting/journal-register',
        Object.fromEntries(new URLSearchParams(query.value)),
    );
}
function dateOnly(value: string): string {
    return value.slice(0, 10);
}
</script>

<template>
    <Head title="Journal register" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Journal register"
            description="Detailed AED postings for the current organization. Legacy summary-only entries are excluded."
        />
        <Link href="/accounting" class="text-sm underline"
            >Back to accounting</Link
        >
        <Card>
            <CardHeader
                ><CardTitle>{{ t('Filters') }}</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="flex flex-wrap items-center gap-2"
                    @submit.prevent="applyFilters"
                >
                    <Input
                        v-model="filters.from"
                        aria-label="From date"
                        type="date"
                        class="w-auto"
                    />
                    <Input
                        v-model="filters.to"
                        aria-label="To date"
                        type="date"
                        class="w-auto"
                    />
                    <select
                        v-model="filters.event"
                        aria-label="Journal event"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">All events</option>
                        <option
                            v-for="event in events"
                            :key="event"
                            :value="event"
                        >
                            {{ event }}
                        </option>
                    </select>
                    <Button>{{ t('Apply filters') }}</Button>
                    <a
                        :href="`/accounting/journal-register.csv${query ? `?${query}` : ''}`"
                        class="text-sm underline"
                        >Download CSV</a
                    >
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Detailed journals</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="!entries.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No detailed journals in this range.
                </p>
                <div
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="border-b pb-3 last:border-0"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <strong
                            >{{ dateOnly(entry.posted_on) }} ·
                            {{ entry.reference }} · {{ entry.event
                            }}<span v-if="entry.reversal_of_id">
                                · reversal</span
                            ></strong
                        ><span>AED {{ entry.debit_total }}</span>
                    </div>
                    <p
                        v-if="entry.source_reference"
                        class="text-muted-foreground text-sm"
                    >
                        Source: {{ entry.source_reference }}
                    </p>
                    <p
                        v-for="line in entry.lines"
                        :key="line.id"
                        class="text-muted-foreground text-sm"
                    >
                        {{ line.account?.code }} {{ line.account?.name }} ·
                        {{ line.description }} · debit {{ line.debit }} · credit
                        {{ line.credit }}
                    </p>
                </div>
                <div class="flex justify-between text-sm">
                    <Link
                        v-if="entries.prev_page_url"
                        :href="entries.prev_page_url"
                        class="underline"
                        >{{ t('Previous') }}</Link
                    ><span v-else /><Link
                        v-if="entries.next_page_url"
                        :href="entries.next_page_url"
                        class="underline"
                        >{{ t('Next') }}</Link
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
