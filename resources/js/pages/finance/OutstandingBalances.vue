<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    reference: string;
    party: string | null;
    document_date: string | null;
    due_on: string | null;
    total: string;
    paid: string;
    credited: string;
    balance: string;
    currency: string;
    days_overdue: number;
};
const props = defineProps<{
    filters: { as_of: string; scope: 'all' | 'overdue' | 'not_overdue' };
    report: {
        receivables: Row[];
        payables: Row[];
        total_receivables: string;
        total_payables: string;
        overdue_receivables_count: number;
        overdue_payables_count: number;
        overdue_receivables: string;
        overdue_payables: string;
    };
}>();
const selectedDate = ref(props.filters.as_of);
const selectedScope = ref(props.filters.scope);
function apply(): void {
    router.get('/accounting/outstanding-balances', {
        as_of: selectedDate.value,
        scope: selectedScope.value,
    });
}
</script>

<template>
    <Head title="Outstanding balances" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Outstanding balances"
            description="Open invoice and vendor bill balances reconstructed from payments and credit notes recorded through the selected date."
        >
            <template #actions>
                <Link href="/accounting" class="text-sm underline">{{
                    t('Back to accounting')
                }}</Link>
            </template>
        </PageHeader>
        <Card
            ><CardContent class="pt-6"
                ><form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="apply"
                >
                    <label class="text-sm"
                        >As of<Input
                            v-model="selectedDate"
                            type="date"
                            required /></label
                    ><label class="text-sm"
                        >Due status<select
                            v-model="selectedScope"
                            class="border-input bg-background block h-9 rounded-md border px-3"
                        >
                            <option value="all">All open items</option>
                            <option value="overdue">Overdue only</option>
                            <option value="not_overdue">Not overdue</option>
                        </select></label
                    ><Button>{{ t('Apply filters') }}</Button
                    ><a
                        :href="`/accounting/outstanding-balances.csv?as_of=${encodeURIComponent(selectedDate)}&scope=${encodeURIComponent(selectedScope)}`"
                        class="text-sm underline"
                        >Download CSV</a
                    >
                </form></CardContent
            ></Card
        >
        <div class="grid gap-4 sm:grid-cols-2">
            <Card
                ><CardHeader
                    ><CardTitle>Overdue receivables</CardTitle></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        AED {{ report.overdue_receivables }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        {{ report.overdue_receivables_count }} invoice(s)
                        require collection follow-up
                    </p></CardContent
                ></Card
            >
            <Card
                ><CardHeader><CardTitle>Overdue payables</CardTitle></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        AED {{ report.overdue_payables }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        {{ report.overdue_payables_count }} bill(s) require
                        payment review
                    </p></CardContent
                ></Card
            >
        </div>
        <Card
            v-for="section in [
                {
                    key: 'receivables' as const,
                    title: 'Receivables',
                    total: report.total_receivables,
                },
                {
                    key: 'payables' as const,
                    title: 'Payables',
                    total: report.total_payables,
                },
            ]"
            :key="section.key"
        >
            <CardHeader
                ><CardTitle
                    >{{ section.title }} · AED {{ section.total }}</CardTitle
                ></CardHeader
            >
            <CardContent class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2">{{ t('Reference') }}</th>
                            <th>Party</th>
                            <th>Document date</th>
                            <th>{{ t('Due date') }}</th>
                            <th class="text-right">Original</th>
                            <th class="text-right">{{ t('Paid') }}</th>
                            <th class="text-right">Credited</th>
                            <th class="text-right">{{ t('Outstanding') }}</th>
                            <th class="text-right">Days overdue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in report[section.key]"
                            :key="row.id"
                            class="border-b"
                        >
                            <td class="py-2 font-medium">
                                {{ row.reference }}
                            </td>
                            <td>{{ row.party ?? '—' }}</td>
                            <td>{{ row.document_date ?? '—' }}</td>
                            <td>{{ row.due_on ?? '—' }}</td>
                            <td class="text-right">{{ row.total }}</td>
                            <td class="text-right">{{ row.paid }}</td>
                            <td class="text-right">{{ row.credited }}</td>
                            <td class="text-right font-medium">
                                {{ row.balance }}
                            </td>
                            <td class="text-right">{{ row.days_overdue }}</td>
                        </tr>
                        <tr v-if="report[section.key].length === 0">
                            <td
                                colspan="9"
                                class="text-muted-foreground py-6 text-center"
                            >
                                No matching outstanding balances as of
                                {{ filters.as_of }}.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
