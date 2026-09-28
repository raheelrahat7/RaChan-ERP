<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Reservation = {
    id: number;
    reference: string;
    unit: { id: number; number: string } | null;
};
type Agreement = {
    id: number;
    reference: string;
    status: string;
    unit_id: number;
    broker_id: number | null;
    tenant: { id: number; name: string } | null;
    ends_on: string;
    rent_amount: string | null;
    is_expiring: boolean;
};
type Broker = { id: number; name: string };
type Tenant = { id: number; name: string };
type CommissionPlan = { id: number; name: string; basis: string; rate: string };

defineProps<{
    reservations: Reservation[];
    leases: Agreement[];
    salesContracts: Agreement[];
    brokers: Broker[];
    tenants: Tenant[];
    commissionPlans: CommissionPlan[];
    canManageTransactions: boolean;
}>();

const leaseForm = useForm({
    reservation_id: '',
    starts_on: '',
    ends_on: '',
    rent_amount: '',
    broker_id: '',
    tenant_id: '',
});
const salesForm = useForm({
    reservation_id: '',
    contracted_on: '',
    sale_price: '',
    broker_id: '',
});
const commissionPlanId = ref('');
const renewalEndsOn = ref('');
const renewalRentAmount = ref('');

function createLease(): void {
    leaseForm.post('/agreements/leases', {
        preserveScroll: true,
        onSuccess: () => leaseForm.reset(),
    });
}

function activateLease(lease: Agreement): void {
    if (confirm(`Activate ${lease.reference}?`)) {
        router.post(
            `/agreements/leases/${lease.id}/activate`,
            { commission_plan_id: commissionPlanId.value || null },
            { preserveScroll: true },
        );
    }
}

function renewLease(lease: Agreement): void {
    if (!renewalEndsOn.value) {
        return;
    }

    router.post(
        `/agreements/leases/${lease.id}/renew`,
        {
            ends_on: renewalEndsOn.value,
            rent_amount: renewalRentAmount.value || null,
        },
        { preserveScroll: true },
    );
}

function createSalesContract(): void {
    salesForm.post('/agreements/sales-contracts', {
        preserveScroll: true,
        onSuccess: () => salesForm.reset(),
    });
}

function activateSalesContract(contract: Agreement): void {
    if (confirm(`Activate ${contract.reference}?`)) {
        router.post(
            `/agreements/sales-contracts/${contract.id}/activate`,
            { commission_plan_id: commissionPlanId.value || null },
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <Head title="Agreements" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Agreements"
            description="Create and activate lease and sale agreements from active reservations."
        />
        <Link href="/lease-compliance" class="text-sm underline"
            >Manage lease compliance</Link
        >

        <Card v-if="canManageTransactions">
            <CardHeader
                ><CardTitle>Activation commission plan</CardTitle></CardHeader
            >
            <CardContent>
                <select
                    v-model="commissionPlanId"
                    class="border-input h-9 rounded-md border px-3"
                >
                    <option value="">No commission</option>
                    <option
                        v-for="plan in commissionPlans"
                        :key="plan.id"
                        :value="String(plan.id)"
                    >
                        {{ plan.name }} ·
                        {{
                            plan.basis === 'fixed'
                                ? `AED ${plan.rate}`
                                : `${plan.rate}%`
                        }}
                    </option>
                </select>
            </CardContent>
        </Card>

        <Card v-if="canManageTransactions">
            <CardHeader><CardTitle>Renewal terms</CardTitle></CardHeader>
            <CardContent class="flex flex-wrap gap-3">
                <Input v-model="renewalEndsOn" type="date" />
                <Input
                    v-model="renewalRentAmount"
                    type="number"
                    min="0"
                    placeholder="New rent (optional)"
                />
                <p class="text-muted-foreground self-center text-sm">
                    Set terms, then select Renew on an active lease.
                </p>
            </CardContent>
        </Card>

        <Card v-if="canManageTransactions">
            <CardHeader><CardTitle>Create draft lease</CardTitle></CardHeader>
            <CardContent>
                <form
                    class="flex flex-wrap gap-3"
                    @submit.prevent="createLease"
                >
                    <select
                        v-model="leaseForm.reservation_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">Reservation</option>
                        <option
                            v-for="reservation in reservations"
                            :key="reservation.id"
                            :value="String(reservation.id)"
                        >
                            {{ reservation.reference }} ·
                            {{ reservation.unit?.number }}
                        </option>
                    </select>
                    <Input v-model="leaseForm.starts_on" type="date" required />
                    <Input v-model="leaseForm.ends_on" type="date" required />
                    <Input
                        v-model="leaseForm.rent_amount"
                        type="number"
                        min="0"
                        placeholder="Rent amount"
                    />
                    <select
                        v-model="leaseForm.tenant_id"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">No tenant profile</option>
                        <option
                            v-for="tenant in tenants"
                            :key="tenant.id"
                            :value="String(tenant.id)"
                        >
                            {{ tenant.name }}
                        </option>
                    </select>
                    <select
                        v-model="leaseForm.broker_id"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">No broker</option>
                        <option
                            v-for="broker in brokers"
                            :key="broker.id"
                            :value="String(broker.id)"
                        >
                            {{ broker.name }}
                        </option>
                    </select>
                    <Button :disabled="leaseForm.processing"
                        >Create lease</Button
                    >
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle>{{ t('Leases') }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-3">
                <p v-if="!leases.length" class="text-muted-foreground text-sm">
                    No leases yet.
                </p>
                <div
                    v-for="lease in leases"
                    :key="lease.id"
                    class="flex items-center justify-between border-b pb-3 last:border-0"
                >
                    <span>
                        {{ lease.reference }}
                        <span v-if="lease.tenant" class="text-muted-foreground"
                            >· {{ lease.tenant.name }}</span
                        >
                        <span v-if="lease.is_expiring" class="text-amber-600"
                            >· expiring {{ lease.ends_on }}</span
                        >
                    </span>
                    <div class="flex items-center gap-3">
                        <span class="text-muted-foreground">{{
                            lease.status
                        }}</span>
                        <Button
                            v-if="
                                canManageTransactions &&
                                lease.status === 'draft'
                            "
                            size="sm"
                            @click="activateLease(lease)"
                            >Activate</Button
                        >
                        <Button
                            v-if="
                                canManageTransactions &&
                                lease.status === 'active'
                            "
                            size="sm"
                            variant="outline"
                            :disabled="!renewalEndsOn"
                            @click="renewLease(lease)"
                            >Renew</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card v-if="canManageTransactions">
            <CardHeader
                ><CardTitle>Create draft sales contract</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="flex flex-wrap gap-3"
                    @submit.prevent="createSalesContract"
                >
                    <select
                        v-model="salesForm.reservation_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">Reservation</option>
                        <option
                            v-for="reservation in reservations"
                            :key="reservation.id"
                            :value="String(reservation.id)"
                        >
                            {{ reservation.reference }} ·
                            {{ reservation.unit?.number }}
                        </option>
                    </select>
                    <Input
                        v-model="salesForm.contracted_on"
                        type="date"
                        required
                    />
                    <Input
                        v-model="salesForm.sale_price"
                        type="number"
                        min="0"
                        placeholder="Sale price"
                    />
                    <select
                        v-model="salesForm.broker_id"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">No broker</option>
                        <option
                            v-for="broker in brokers"
                            :key="broker.id"
                            :value="String(broker.id)"
                        >
                            {{ broker.name }}
                        </option>
                    </select>
                    <Button :disabled="salesForm.processing"
                        >Create contract</Button
                    >
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Sales contracts</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="!salesContracts.length"
                    class="text-muted-foreground text-sm"
                >
                    No sales contracts yet.
                </p>
                <div
                    v-for="contract in salesContracts"
                    :key="contract.id"
                    class="flex items-center justify-between border-b pb-3 last:border-0"
                >
                    <span>{{ contract.reference }}</span>
                    <div class="flex items-center gap-3">
                        <span class="text-muted-foreground">{{
                            contract.status
                        }}</span>
                        <Button
                            v-if="
                                canManageTransactions &&
                                contract.status === 'draft'
                            "
                            size="sm"
                            @click="activateSalesContract(contract)"
                            >Activate</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
