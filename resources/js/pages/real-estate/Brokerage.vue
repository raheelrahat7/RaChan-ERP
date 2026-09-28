<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Plan = { id: number; name: string; basis: string; rate: string };
type Transaction = {
    id: number;
    base_amount: string;
    commission_amount: string;
    status: string;
    broker: { name: string };
};
defineProps<{
    plans: Plan[];
    transactions: Transaction[];
    canManage: boolean;
}>();

const form = useForm({ name: '', basis: 'percentage', rate: '' });
function createPlan(): void {
    form.post('/real-estate/brokerage/commission-plans', {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'rate'),
    });
}
</script>

<template>
    <Head title="Brokerage" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Brokerage"
            description="Commission plans and calculated broker commissions."
        />
        <Card v-if="canManage">
            <CardHeader
                ><CardTitle>Create commission plan</CardTitle></CardHeader
            >
            <CardContent>
                <form class="flex flex-wrap gap-3" @submit.prevent="createPlan">
                    <Input
                        v-model="form.name"
                        placeholder="Plan name"
                        required
                    />
                    <select
                        v-model="form.basis"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="percentage">Percentage</option>
                        <option value="fixed">Fixed AED</option>
                    </select>
                    <Input
                        v-model="form.rate"
                        type="number"
                        min="0"
                        step="0.01"
                        :placeholder="
                            form.basis === 'fixed' ? 'AED amount' : 'Percent'
                        "
                        required
                    />
                    <Button :disabled="form.processing">Create plan</Button>
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Commission plans</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p v-if="!plans.length" class="text-muted-foreground text-sm">
                    No commission plans yet.
                </p>
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    class="flex justify-between border-b pb-3 last:border-0"
                >
                    <span>{{ plan.name }}</span
                    ><span
                        >AED
                        {{
                            plan.basis === 'fixed' ? plan.rate : `${plan.rate}%`
                        }}</span
                    >
                </div>
            </CardContent>
        </Card>
        <Card>
            <CardHeader
                ><CardTitle>Commission transactions</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p
                    v-if="!transactions.length"
                    class="text-muted-foreground text-sm"
                >
                    No calculated commissions yet.
                </p>
                <div
                    v-for="transaction in transactions"
                    :key="transaction.id"
                    class="flex justify-between border-b pb-3 last:border-0"
                >
                    <span
                        >{{ transaction.broker.name }} · base AED
                        {{ transaction.base_amount }}</span
                    ><span
                        >AED {{ transaction.commission_amount }} ·
                        {{ transaction.status }}</span
                    >
                </div>
            </CardContent>
        </Card>
    </div>
</template>
