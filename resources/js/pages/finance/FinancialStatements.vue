<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    code: string;
    name: string;
    type: string;
    amount: string;
};
const props = defineProps<{
    filters: { as_of: string; from: string; to: string };
    balanceSheet: null | {
        period: { name: string; status: string };
        assets: Row[];
        liabilities: Row[];
        equity: Row[];
        prior_results: string;
        current_profit: string;
        total_assets: string;
        total_liabilities: string;
        total_equity: string;
        total_liabilities_and_equity: string;
    };
    profitAndLoss: {
        income: Row[];
        expenses: Row[];
        total_income: string;
        total_expenses: string;
        profit: string;
    };
}>();
const filters = reactive({ ...props.filters });
function applyFilters(): void {
    router.get('/accounting/statements', filters);
}
</script>

<template>
    <Head title="Financial statements" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Financial statements"
            description="Draft statements from detailed AED journals. Historical summary-only entries are excluded."
        />
        <Link href="/accounting" class="text-sm underline"
            >Back to accounting</Link
        >
        <Card
            ><CardHeader><CardTitle>Reporting dates</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap items-center gap-2"
                    @submit.prevent="applyFilters"
                >
                    <label class="text-sm"
                        >Balance sheet as of
                        <Input
                            v-model="filters.as_of"
                            type="date"
                            required /></label
                    ><label class="text-sm"
                        >Profit and loss from
                        <Input
                            v-model="filters.from"
                            type="date"
                            required /></label
                    ><label class="text-sm"
                        >to
                        <Input
                            v-model="filters.to"
                            type="date"
                            required /></label
                    ><Button>Apply dates</Button>
                    <a
                        v-if="balanceSheet"
                        :href="`/accounting/statements/balance-sheet.csv?as_of=${encodeURIComponent(filters.as_of)}`"
                        class="text-sm underline"
                        >Balance sheet CSV</a
                    >
                    <a
                        :href="`/accounting/statements/profit-and-loss.csv?from=${encodeURIComponent(filters.from)}&to=${encodeURIComponent(filters.to)}`"
                        class="text-sm underline"
                        >Profit and loss CSV</a
                    >
                </form></CardContent
            ></Card
        >
        <div class="grid gap-6 lg:grid-cols-2">
            <Card
                ><CardHeader
                    ><CardTitle
                        >Balance sheet · {{ filters.as_of }}</CardTitle
                    ></CardHeader
                ><CardContent v-if="balanceSheet" class="space-y-4">
                    <p class="text-muted-foreground text-sm">
                        Period: {{ balanceSheet.period.name }} ·
                        {{ balanceSheet.period.status }}
                    </p>
                    <section>
                        <h3 class="font-semibold">Assets</h3>
                        <p
                            v-for="row in balanceSheet.assets"
                            :key="row.id"
                            class="flex justify-between text-sm"
                        >
                            <span>{{ row.code }} · {{ row.name }}</span
                            ><span>{{ row.amount }}</span>
                        </p>
                        <p class="flex justify-between border-t font-semibold">
                            <span>Total assets</span
                            ><span>AED {{ balanceSheet.total_assets }}</span>
                        </p>
                    </section>
                    <section>
                        <h3 class="font-semibold">Liabilities</h3>
                        <p
                            v-for="row in balanceSheet.liabilities"
                            :key="row.id"
                            class="flex justify-between text-sm"
                        >
                            <span>{{ row.code }} · {{ row.name }}</span
                            ><span>{{ row.amount }}</span>
                        </p>
                        <p class="flex justify-between border-t font-semibold">
                            <span>Total liabilities</span
                            ><span
                                >AED {{ balanceSheet.total_liabilities }}</span
                            >
                        </p>
                    </section>
                    <section>
                        <h3 class="font-semibold">Equity</h3>
                        <p
                            v-for="row in balanceSheet.equity"
                            :key="row.id"
                            class="flex justify-between text-sm"
                        >
                            <span>{{ row.code }} · {{ row.name }}</span
                            ><span>{{ row.amount }}</span>
                        </p>
                        <p class="flex justify-between text-sm">
                            <span>Prior accumulated results</span
                            ><span>{{ balanceSheet.prior_results }}</span>
                        </p>
                        <p class="flex justify-between text-sm">
                            <span>Current-period profit/(loss)</span
                            ><span>{{ balanceSheet.current_profit }}</span>
                        </p>
                        <p class="flex justify-between border-t font-semibold">
                            <span>Total equity</span
                            ><span>AED {{ balanceSheet.total_equity }}</span>
                        </p>
                    </section>
                    <p class="flex justify-between border-t pt-2 font-semibold">
                        <span>Total liabilities and equity</span
                        ><span
                            >AED
                            {{
                                balanceSheet.total_liabilities_and_equity
                            }}</span
                        >
                    </p> </CardContent
                ><CardContent v-else
                    ><p class="text-destructive text-sm">
                        Create an accounting period containing the as-of date to
                        separate prior results from current-period profit.
                    </p></CardContent
                ></Card
            >
            <Card
                ><CardHeader
                    ><CardTitle
                        >Profit and loss · {{ filters.from }}–{{
                            filters.to
                        }}</CardTitle
                    ></CardHeader
                ><CardContent class="space-y-4">
                    <section>
                        <h3 class="font-semibold">{{ t('Income') }}</h3>
                        <p
                            v-for="row in profitAndLoss.income"
                            :key="row.id"
                            class="flex justify-between text-sm"
                        >
                            <span>{{ row.code }} · {{ row.name }}</span
                            ><span>{{ row.amount }}</span>
                        </p>
                        <p class="flex justify-between border-t font-semibold">
                            <span>Total income</span
                            ><span>AED {{ profitAndLoss.total_income }}</span>
                        </p>
                    </section>
                    <section>
                        <h3 class="font-semibold">{{ t('Expenses') }}</h3>
                        <p
                            v-for="row in profitAndLoss.expenses"
                            :key="row.id"
                            class="flex justify-between text-sm"
                        >
                            <span>{{ row.code }} · {{ row.name }}</span
                            ><span>{{ row.amount }}</span>
                        </p>
                        <p class="flex justify-between border-t font-semibold">
                            <span>Total expenses</span
                            ><span>AED {{ profitAndLoss.total_expenses }}</span>
                        </p>
                    </section>
                    <p class="flex justify-between border-t pt-2 font-semibold">
                        <span>Profit/(loss)</span
                        ><span>AED {{ profitAndLoss.profit }}</span>
                    </p>
                </CardContent></Card
            >
        </div>
    </div>
</template>
