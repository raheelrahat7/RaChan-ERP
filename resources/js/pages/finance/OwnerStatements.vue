<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Owner = { id: number; name: string; reference: string | null };
type Statement = {
    owner: Owner;
    rows: {
        activity_date: string;
        type: 'income' | 'expense';
        reference: string;
        property_id: number;
        property_name: string;
        description: string;
        property_amount: string;
        ownership_share: string;
        owner_amount: number;
    }[];
    totals: { income: string; expenses: string; net: string };
};
const props = defineProps<{
    owners: Owner[];
    selectedOwnerId: number | null;
    from: string;
    to: string;
    statement: Statement | null;
}>();
const ownerId = ref(props.selectedOwnerId ? String(props.selectedOwnerId) : '');
const fromDate = ref(props.from);
const toDate = ref(props.to);
const query = computed(() => ({
    owner_id: ownerId.value,
    from: fromDate.value,
    to: toDate.value,
}));
function apply(): void {
    router.get('/reports/owner-statements', query.value, {
        preserveState: true,
        preserveScroll: true,
    });
}
const exportUrl = computed(
    () =>
        `/reports/owner-statements.csv?${new URLSearchParams(query.value).toString()}`,
);
</script>

<template>
    <Head title="Owner statements" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Owner statements"
            description="AED ownership-share view of posted property income and operating expenses."
        />
        <Card>
            <CardHeader><CardTitle>Statement period</CardTitle></CardHeader>
            <CardContent>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="apply"
                >
                    <label class="space-y-1 text-sm"
                        >{{ t('Owner')
                        }}<select
                            v-model="ownerId"
                            class="border-input h-9 min-w-56 rounded-md border px-3"
                            required
                        >
                            <option disabled value="">Select owner</option>
                            <option
                                v-for="owner in owners"
                                :key="owner.id"
                                :value="String(owner.id)"
                            >
                                {{ owner.name }}
                            </option>
                        </select></label
                    >
                    <label class="space-y-1 text-sm"
                        >{{ t('From')
                        }}<Input v-model="fromDate" type="date" required
                    /></label>
                    <label class="space-y-1 text-sm"
                        >{{ t('To')
                        }}<Input v-model="toDate" type="date" required
                    /></label>
                    <Button>{{ t('Apply') }}</Button>
                    <Button v-if="ownerId" as-child variant="outline">
                        <a :href="exportUrl">Download CSV</a>
                    </Button>
                </form>
            </CardContent>
        </Card>
        <Card v-if="statement">
            <CardHeader>
                <CardTitle>{{ statement.owner.name }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-md border p-3">
                        <p class="text-muted-foreground text-sm">
                            Attributable income
                        </p>
                        <p class="text-lg font-semibold">
                            AED {{ statement.totals.income }}
                        </p>
                    </div>
                    <div class="rounded-md border p-3">
                        <p class="text-muted-foreground text-sm">
                            Attributable expenses
                        </p>
                        <p class="text-lg font-semibold">
                            AED {{ statement.totals.expenses }}
                        </p>
                    </div>
                    <div class="rounded-md border p-3">
                        <p class="text-muted-foreground text-sm">
                            Net operating activity
                        </p>
                        <p class="text-lg font-semibold">
                            AED {{ statement.totals.net }}
                        </p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-4xl text-left text-sm">
                        <thead class="border-b">
                            <tr>
                                <th class="p-2">{{ t('Date') }}</th>
                                <th class="p-2">Type</th>
                                <th class="p-2">{{ t('Reference') }}</th>
                                <th class="p-2">{{ t('Property') }}</th>
                                <th class="p-2">{{ t('Description') }}</th>
                                <th class="p-2 text-right">Property AED</th>
                                <th class="p-2 text-right">Share</th>
                                <th class="p-2 text-right">Owner AED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in statement.rows"
                                :key="`${row.type}-${row.reference}`"
                                class="border-b"
                            >
                                <td class="p-2">{{ row.activity_date }}</td>
                                <td class="p-2 capitalize">{{ row.type }}</td>
                                <td class="p-2">{{ row.reference }}</td>
                                <td class="p-2">{{ row.property_name }}</td>
                                <td class="p-2 capitalize">
                                    {{ row.description }}
                                </td>
                                <td class="p-2 text-right">
                                    {{ row.property_amount }}
                                </td>
                                <td class="p-2 text-right">
                                    {{ row.ownership_share }}%
                                </td>
                                <td class="p-2 text-right">
                                    {{ row.owner_amount.toFixed(2) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="!statement.rows.length"
                        class="text-muted-foreground py-6 text-center text-sm"
                    >
                        No posted property activity in this period.
                    </p>
                </div>
            </CardContent>
        </Card>
        <p class="text-muted-foreground text-sm">
            This internal accrual statement includes posted lease service-charge
            income and property operating-expense bills. It excludes deposits,
            capital assets, payments, distributions, and unlinked invoices; it
            does not create an owner payable.
        </p>
    </div>
</template>
