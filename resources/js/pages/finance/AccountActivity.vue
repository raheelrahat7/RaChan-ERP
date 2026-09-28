<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Line = {
    id: number;
    description: string | null;
    debit: string;
    credit: string;
    entry: {
        reference: string;
        source_reference: string | null;
        event: string;
        posted_on: string;
        reversal_of_id: number | null;
    };
};
const props = defineProps<{
    accounts: { id: number; code: string; name: string }[];
    selectedAccount: {
        id: number;
        code: string;
        name: string;
        type: string;
    } | null;
    filters: { account_id: string; from: string; to: string };
    activity: {
        opening_net: string;
        period_debit: string;
        period_credit: string;
        closing_net: string;
        lines: {
            data: Line[];
            prev_page_url: string | null;
            next_page_url: string | null;
        };
    } | null;
}>();
const filters = reactive({ ...props.filters });
const exportUrl = computed(() => {
    const query = new URLSearchParams({ account_id: filters.account_id });
    if (filters.from) query.set('from', filters.from);
    if (filters.to) query.set('to', filters.to);
    return `/accounting/activity.csv?${query.toString()}`;
});
function applyFilters(): void {
    router.get('/accounting/activity', {
        account_id: filters.account_id || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
    });
}
</script>

<template>
    <Head title="Account activity" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Account activity"
            description="Posted AED journal lines in your current organization. Legacy summary-only entries have no account allocation and are excluded."
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
                    <select
                        v-model="filters.account_id"
                        aria-label="Ledger account"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option value="">Choose account</option>
                        <option
                            v-for="account in accounts"
                            :key="account.id"
                            :value="String(account.id)"
                        >
                            {{ account.code }} · {{ account.name }}
                        </option>
                    </select>
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
                    <Button>View activity</Button>
                    <a
                        v-if="filters.account_id"
                        :href="exportUrl"
                        class="text-sm underline"
                        >Download CSV</a
                    >
                </form>
            </CardContent>
        </Card>
        <Card v-if="activity && selectedAccount">
            <CardHeader
                ><CardTitle
                    >{{ selectedAccount.code }} ·
                    {{ selectedAccount.name }}</CardTitle
                ></CardHeader
            >
            <CardContent class="space-y-4">
                <p class="text-muted-foreground text-sm">
                    Balances are debit minus credit; a negative balance is
                    credit-sided. The opening balance includes entries before
                    the From date.
                </p>
                <div class="grid gap-3 text-sm sm:grid-cols-4">
                    <div>
                        Opening net<br /><strong
                            >AED {{ activity.opening_net }}</strong
                        >
                    </div>
                    <div>
                        Period debits<br /><strong
                            >AED {{ activity.period_debit }}</strong
                        >
                    </div>
                    <div>
                        Period credits<br /><strong
                            >AED {{ activity.period_credit }}</strong
                        >
                    </div>
                    <div>
                        Closing net<br /><strong
                            >AED {{ activity.closing_net }}</strong
                        >
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="p-2">{{ t('Date') }}</th>
                                <th class="p-2">Journal</th>
                                <th class="p-2">{{ t('Description') }}</th>
                                <th class="p-2 text-right">Debit</th>
                                <th class="p-2 text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in activity.lines.data"
                                :key="line.id"
                                class="border-b"
                            >
                                <td class="p-2">{{ line.entry.posted_on }}</td>
                                <td class="p-2">
                                    {{ line.entry.reference
                                    }}<span v-if="line.entry.reversal_of_id">
                                        · reversal</span
                                    ><span
                                        v-if="line.entry.source_reference"
                                        class="text-muted-foreground block"
                                        >Source:
                                        {{ line.entry.source_reference }}</span
                                    >
                                </td>
                                <td class="p-2">
                                    {{ line.description || line.entry.event }}
                                </td>
                                <td class="p-2 text-right">{{ line.debit }}</td>
                                <td class="p-2 text-right">
                                    {{ line.credit }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="!activity.lines.data.length"
                        class="text-muted-foreground p-2 text-sm"
                    >
                        No posted lines in this date range.
                    </p>
                </div>
                <div class="flex justify-between text-sm">
                    <Link
                        v-if="activity.lines.prev_page_url"
                        :href="activity.lines.prev_page_url"
                        class="underline"
                        >{{ t('Previous') }}</Link
                    ><span v-else />
                    <Link
                        v-if="activity.lines.next_page_url"
                        :href="activity.lines.next_page_url"
                        class="underline"
                        >{{ t('Next') }}</Link
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
