<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t, status: statusLabel } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
type Item = {
    id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    due_at: string | null;
    assigned_to: number | null;
    vendor_id: number | null;
    estimated_cost: string | null;
    actual_cost: string | null;
    currency: string;
    requires_manager_confirmation: boolean;
    submitted_at: string | null;
};
const props = defineProps<{
    requests: Item[];
    filter: string;
    properties: { id: number; name: string }[];
    vendors: { id: number; name: string; trade: string | null }[];
    members: { id: number; name: string }[];
    canManageOperations: boolean;
}>();
const form = useForm({
    property_id: '',
    unit_id: '',
    title: '',
    priority: 'medium',
    due_at: '',
    vendor_id: '',
    estimated_cost: '',
    requires_manager_confirmation: false,
});
const statusError = ref('');
const reasons = ref<Record<number, string>>({});
const vendorForm = useForm({ name: '', trade: '', email: '', phone: '' });
function create(): void {
    form.post('/maintenance', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function createVendor(): void {
    vendorForm.post('/maintenance/vendors', {
        preserveScroll: true,
        onSuccess: () => vendorForm.reset(),
    });
}
function updateWorkOrder(item: Item, values: Partial<Item>): void {
    router.put(
        `/maintenance/${item.id}/work-order`,
        {
            vendor_id: values.vendor_id ?? item.vendor_id,
            estimated_cost: values.estimated_cost ?? item.estimated_cost,
            actual_cost: values.actual_cost ?? item.actual_cost,
        },
        { preserveScroll: true },
    );
}
function update(
    item: Item,
    status: string,
    assignedTo = item.assigned_to,
): void {
    router.put(
        `/maintenance/${item.id}/status`,
        {
            status,
            assigned_to: assignedTo,
            reason: reasons.value[item.id] ?? '',
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                statusError.value =
                    Object.values(errors)[0] ?? 'Unable to update the job.';
            },
            onSuccess: () => {
                statusError.value = '';
                reasons.value[item.id] = '';
            },
        },
    );
}
function applyFilter(filter: string): void {
    router.get('/maintenance', filter ? { filter } : {}, {
        preserveState: true,
    });
}
</script>
<template>
    <Head :title="t('Maintenance')" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :translate-text="false"
            :title="t('Maintenance')"
            :description="
                t('Track property service work and overdue requests.')
            "
        />
        <Link href="/operations/helpdesk" class="text-sm underline">{{
            t('Service helpdesk')
        }}</Link>
        <Link
            v-if="canManageOperations"
            href="/operations/spare-parts"
            class="text-sm underline underline-offset-4"
            >{{ t('Spare parts and stores') }}</Link
        >
        <Link
            v-if="canManageOperations"
            href="/operations/amc"
            class="text-sm underline"
            >{{ t('AMC contracts and equipment') }}</Link
        >
        <Link
            v-if="canManageOperations"
            href="/operations/projects"
            class="text-sm underline"
            >{{ t('Construction projects and contractor claims') }}</Link
        >
        <Link
            v-if="canManageOperations"
            href="/operations/fleet"
            class="text-sm underline"
            >{{ t('Fleet vehicles and service history') }}</Link
        >
        <Card v-if="canManageOperations">
            <CardHeader
                ><CardTitle>{{ t('Vendors') }}</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="flex flex-wrap gap-3"
                    @submit.prevent="createVendor"
                >
                    <Input
                        v-model="vendorForm.name"
                        :placeholder="t('Vendor name')"
                        required
                    />
                    <Input
                        v-model="vendorForm.trade"
                        :placeholder="t('Trade, e.g. HVAC')"
                    />
                    <Input
                        v-model="vendorForm.phone"
                        :placeholder="t('Phone')"
                    />
                    <Button :disabled="vendorForm.processing">{{
                        t('Add vendor')
                    }}</Button>
                </form>
            </CardContent>
        </Card>
        <Card v-if="canManageOperations"
            ><CardHeader
                ><CardTitle>{{ t('New request') }}</CardTitle></CardHeader
            ><CardContent
                ><form class="flex flex-wrap gap-3" @submit.prevent="create">
                    <select
                        v-model="form.property_id"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Property') }}</option>
                        <option
                            v-for="property in properties"
                            :key="property.id"
                            :value="String(property.id)"
                        >
                            {{ property.name }}
                        </option></select
                    ><Input
                        v-model="form.title"
                        :placeholder="t('Request title')"
                        required
                    /><select
                        v-model="form.priority"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option
                            v-for="priority in [
                                'low',
                                'medium',
                                'high',
                                'urgent',
                            ]"
                            :key="priority"
                            :value="priority"
                        >
                            {{ statusLabel(priority) }}
                        </option></select
                    ><Input v-model="form.due_at" type="datetime-local" />
                    <select
                        v-model="form.vendor_id"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">{{ t('No vendor') }}</option>
                        <option
                            v-for="vendor in vendors"
                            :key="vendor.id"
                            :value="String(vendor.id)"
                        >
                            {{ vendor.name }}
                        </option>
                    </select>
                    <Input
                        v-model="form.estimated_cost"
                        type="number"
                        min="0"
                        :placeholder="t('Estimated AED')"
                    />
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="form.requires_manager_confirmation"
                            type="checkbox"
                        />{{
                            t('Require manager confirmation before completion')
                        }}</label
                    >
                    <p
                        v-for="(message, field) in form.errors"
                        :key="field"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ message }}
                    </p>
                    <Button :disabled="form.processing">{{
                        t('Create request')
                    }}</Button>
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader
                ><CardTitle>{{ t('Workload') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><div class="flex flex-wrap gap-2">
                    <Button
                        v-for="option in [
                            { value: '', label: 'All' },
                            { value: 'open', label: 'Open' },
                            { value: 'overdue', label: 'Overdue' },
                            { value: 'mine', label: 'Mine' },
                            { value: 'completed', label: 'Completed' },
                        ]"
                        :key="option.label"
                        size="sm"
                        :variant="
                            filter === option.value ? 'default' : 'outline'
                        "
                        @click="applyFilter(option.value)"
                        >{{ t(option.label) }}</Button
                    >
                </div>
                <p
                    v-if="statusError"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{ statusError }}
                </p>
                <p
                    v-if="!requests.length"
                    class="text-muted-foreground text-sm"
                >
                    {{ t('No maintenance requests.') }}
                </p>
                <div
                    v-for="item in requests"
                    :key="item.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 last:border-0"
                >
                    <label v-if="canManageOperations" class="text-sm"
                        >{{ t('Reason for reopening / cancellation')
                        }}<Input v-model="reasons[item.id]" maxlength="2000" />
                    </label>
                    <span
                        >{{ item.reference }} ·
                        <Link
                            :href="`/maintenance/${item.id}/job-card`"
                            class="underline underline-offset-4"
                            >{{ item.title }}</Link
                        >
                        · {{ statusLabel(item.priority) }}</span
                    ><select
                        v-if="canManageOperations"
                        :value="item.status"
                        class="border-input h-8 rounded-md border px-2 text-sm"
                        @change="
                            update(
                                item,
                                String(
                                    ($event.target as HTMLSelectElement).value,
                                ),
                            )
                        "
                    >
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
                            {{ statusLabel(status) }}
                        </option></select
                    ><span v-else>{{ statusLabel(item.status) }}</span>
                    <span
                        v-if="item.submitted_at && item.status !== 'completed'"
                        class="text-sm"
                        >{{ t('Awaiting confirmation') }}</span
                    >
                    <select
                        v-if="canManageOperations"
                        :value="item.assigned_to ?? ''"
                        :disabled="
                            Boolean(item.submitted_at) &&
                            item.status !== 'completed'
                        "
                        class="border-input h-8 rounded-md border px-2 text-sm"
                        @change="
                            update(
                                item,
                                item.status,
                                Number(
                                    ($event.target as HTMLSelectElement).value,
                                ) || null,
                            )
                        "
                    >
                        <option value="">{{ t('Unassigned') }}</option>
                        <option
                            v-for="member in props.members"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.name }}
                        </option>
                    </select>
                    <select
                        v-if="canManageOperations"
                        :value="item.vendor_id ?? ''"
                        :disabled="
                            Boolean(item.submitted_at) ||
                            ['completed', 'cancelled'].includes(item.status)
                        "
                        class="border-input h-8 rounded-md border px-2 text-sm"
                        @change="
                            updateWorkOrder(item, {
                                vendor_id:
                                    Number(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    ) || null,
                            })
                        "
                    >
                        <option value="">{{ t('No vendor') }}</option>
                        <option
                            v-for="vendor in vendors"
                            :key="vendor.id"
                            :value="vendor.id"
                        >
                            {{ vendor.name }}
                        </option>
                    </select>
                    <Input
                        v-if="canManageOperations"
                        :disabled="
                            Boolean(item.submitted_at) ||
                            ['completed', 'cancelled'].includes(item.status)
                        "
                        :model-value="item.actual_cost || ''"
                        class="h-8 w-28"
                        type="number"
                        min="0"
                        :placeholder="t('Actual AED')"
                        @change="
                            updateWorkOrder(item, {
                                actual_cost:
                                    ($event.target as HTMLInputElement).value ||
                                    null,
                            })
                        "
                    />
                    <span
                        v-if="item.estimated_cost || item.actual_cost"
                        class="text-muted-foreground text-sm"
                        >AED {{ item.actual_cost || '—' }} /
                        {{ item.estimated_cost || '—' }}</span
                    >
                </div></CardContent
            ></Card
        >
    </div>
</template>
