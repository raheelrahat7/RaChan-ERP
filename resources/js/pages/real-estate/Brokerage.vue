<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BrokerageReporting from '@/components/BrokerageReporting.vue';
import CrmSettingsTable from '@/components/CrmSettingsTable.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
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

type Plan = { id: number; name: string; basis: string; rate: string };
type Transaction = {
    id: number;
    base_amount: string;
    commission_amount: string;
    status: string;
    broker: { name: string };
};
type PlanRow = { id: number; name: string; rate: string };
type TransactionRow = {
    id: number;
    broker: string;
    base: string;
    commission: string;
    status: string;
};

const props = defineProps<{
    plans: Plan[];
    transactions: Transaction[];
    canManage: boolean;
}>();
const { t } = useLocale();
const tab = ref<'plans' | 'transactions' | 'reporting' | 'teams'>('plans');
const open = ref(false);
const form = useForm({ name: '', basis: 'percentage', rate: '' });

const planRows = computed<PlanRow[]>(() =>
    props.plans.map((plan) => ({
        id: plan.id,
        name: plan.name,
        rate: plan.basis === 'fixed' ? `AED ${plan.rate}` : `${plan.rate}%`,
    })),
);
const transactionRows = computed<TransactionRow[]>(() =>
    props.transactions.map((transaction) => ({
        id: transaction.id,
        broker: transaction.broker.name,
        base: `AED ${transaction.base_amount}`,
        commission: `AED ${transaction.commission_amount}`,
        status: transaction.status,
    })),
);
const planColumns = computed<DataTableColumn<PlanRow>[]>(() => [
    { key: 'name', label: t('Plan'), sortable: true },
    { key: 'rate', label: t('Rate'), align: 'end' },
]);
const transactionColumns = computed<DataTableColumn<TransactionRow>[]>(() => [
    { key: 'broker', label: t('Broker'), sortable: true },
    { key: 'base', label: t('Base amount'), align: 'end' },
    { key: 'commission', label: t('Commission'), align: 'end' },
    { key: 'status', label: t('Status') },
]);

function createPlan(): void {
    form.post('/real-estate/brokerage/commission-plans', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('name', 'rate');
            open.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('Brokerage')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Brokerage"
            description="Commission plans and calculated broker commissions."
        />
        <nav :aria-label="t('Brokerage views')" class="flex gap-2">
            <button
                v-for="item in [
                    { key: 'plans', label: 'Commission plans' },
                    { key: 'transactions', label: 'Commission transactions' },
                    {
                        key: 'reporting',
                        label: 'Clawbacks and net contribution',
                    },
                    { key: 'teams', label: 'Team view' },
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
            v-if="tab === 'plans'"
            :show-title="false"
            title="Commission plans"
            :columns="planColumns"
            :rows="planRows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.name"
            add-label="Create commission plan"
            :selectable="false"
            searchable
            :can-edit="canManage"
            @add="open = true"
        />
        <BrokerageReporting
            v-else-if="tab === 'reporting'"
            view="transactions"
        />
        <BrokerageReporting v-else-if="tab === 'teams'" view="teams" />
        <CrmSettingsTable
            v-else
            :show-title="false"
            title="Commission transactions"
            add-label=""
            :columns="transactionColumns"
            :rows="transactionRows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.broker"
            :selectable="false"
            searchable
        >
            <template #cell-status="{ row }"
                ><Badge variant="secondary">{{ row.status }}</Badge></template
            >
        </CrmSettingsTable>

        <Sheet v-model:open="open">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Create commission plan')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t('Commission plans and calculated broker commissions.')
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="plan-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="createPlan"
                >
                    <div class="space-y-1">
                        <Label for="bp-name">{{ t('Plan name') }}</Label
                        ><Input
                            id="bp-name"
                            v-model="form.name"
                            required
                        /><InputError :message="form.errors.name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="bp-basis">{{ t('Basis') }}</Label>
                        <select
                            id="bp-basis"
                            v-model="form.basis"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option value="percentage">
                                {{ t('Percentage') }}
                            </option>
                            <option value="fixed">{{ t('Fixed AED') }}</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="bp-rate">{{
                            form.basis === 'fixed'
                                ? t('AED amount')
                                : t('Percent')
                        }}</Label>
                        <Input
                            id="bp-rate"
                            v-model="form.rate"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                        />
                        <InputError :message="form.errors.rate" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="plan-form"
                        :disabled="form.processing"
                        >{{ t('Create plan') }}</Button
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
