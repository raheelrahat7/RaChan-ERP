<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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

const { t, status: statusLabel } = useLocale();

type Row = {
    id: number;
    request: string;
    priority: string;
    status: string;
    assignee: string;
    vendor: string;
    cost: string;
    reason: string;
};
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
const createOpen = ref(false);
const vendorOpen = ref(false);
const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const cellSelect =
    'border-input bg-background h-8 rounded-md border px-2 text-sm';
const STATUSES = ['open', 'in_progress', 'on_hold', 'completed', 'cancelled'];
const FILTERS = [
    { value: '', label: 'All' },
    { value: 'open', label: 'Open' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'mine', label: 'Mine' },
    { value: 'completed', label: 'Completed' },
];
const locked = (item: Item): boolean =>
    Boolean(item.submitted_at) ||
    ['completed', 'cancelled'].includes(item.status);
const itemOf = (id: number): Item =>
    props.requests.find((item) => item.id === id)!;
const rows = computed<Row[]>(() =>
    props.requests.map((item) => ({
        id: item.id,
        request: `${item.reference} · ${item.title}`,
        priority: item.priority,
        status: item.status,
        assignee: '',
        vendor: '',
        cost: '',
        reason: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'request', label: t('Request'), sortable: true },
    { key: 'priority', label: t('Priority'), sortable: true },
    { key: 'status', label: t('Status') },
    { key: 'assignee', label: t('Assigned to') },
    { key: 'vendor', label: t('Vendor') },
    { key: 'cost', label: t('Cost (AED)') },
    ...(props.canManageOperations
        ? [
              {
                  key: 'reason' as const,
                  label: t('Reason for reopening / cancellation'),
              },
          ]
        : []),
]);
const reasons = ref<Record<number, string>>({});
const vendorForm = useForm({ name: '', trade: '', email: '', phone: '' });
function create(): void {
    form.post('/maintenance', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
}
function createVendor(): void {
    vendorForm.post('/maintenance/vendors', {
        preserveScroll: true,
        onSuccess: () => {
            vendorForm.reset();
            vendorOpen.value = false;
        },
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
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :translate="false"
            :title="t('Maintenance')"
            :description="
                t('Track property service work and overdue requests.')
            "
        >
            <template #actions>
                <Link href="/operations/helpdesk" class="text-sm underline">{{
                    t('Service helpdesk')
                }}</Link>
                <template v-if="canManageOperations">
                    <Link
                        href="/operations/spare-parts"
                        class="text-sm underline"
                        >{{ t('Spare parts and stores') }}</Link
                    >
                    <Link href="/operations/amc" class="text-sm underline">{{
                        t('AMC contracts and equipment')
                    }}</Link>
                    <Link
                        href="/operations/projects"
                        class="text-sm underline"
                        >{{
                            t('Construction projects and contractor claims')
                        }}</Link
                    >
                    <Link href="/operations/fleet" class="text-sm underline">{{
                        t('Fleet vehicles and service history')
                    }}</Link>
                </template>
            </template>
        </PageHeader>

        <div
            class="flex flex-wrap items-center gap-2"
            role="group"
            :aria-label="t('Workload')"
        >
            <Button
                v-for="option in FILTERS"
                :key="option.label"
                size="sm"
                :variant="filter === option.value ? 'default' : 'outline'"
                @click="applyFilter(option.value)"
                >{{ t(option.label) }}</Button
            >
            <Button
                v-if="canManageOperations"
                type="button"
                size="sm"
                variant="ghost"
                class="ms-auto"
                @click="vendorOpen = true"
                >{{ t('Add vendor') }}</Button
            >
        </div>
        <p v-if="statusError" role="alert" class="text-destructive text-sm">
            {{ statusError }}
        </p>

        <CrmSettingsTable
            :show-title="false"
            title="Maintenance requests"
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.request"
            add-label="New request"
            :selectable="false"
            searchable
            :can-edit="canManageOperations"
            @add="createOpen = true"
        >
            <template #cell-request="{ row }">
                <Link
                    :href="`/maintenance/${row.id}/job-card`"
                    class="text-primary font-medium underline-offset-2 hover:underline"
                    >{{ row.request }}</Link
                >
            </template>
            <template #cell-priority="{ row }">{{
                statusLabel(row.priority)
            }}</template>
            <template #cell-status="{ row }">
                <select
                    v-if="canManageOperations"
                    :value="row.status"
                    :class="cellSelect"
                    :aria-label="`${t('Status')} ${row.request}`"
                    @change="
                        update(
                            itemOf(row.id),
                            String(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="status in STATUSES"
                        :key="status"
                        :value="status"
                    >
                        {{ statusLabel(status) }}
                    </option>
                </select>
                <Badge v-else variant="secondary">{{
                    statusLabel(row.status)
                }}</Badge>
                <span
                    v-if="
                        itemOf(row.id).submitted_at &&
                        row.status !== 'completed'
                    "
                    class="text-muted-foreground ms-2 text-xs"
                    >{{ t('Awaiting confirmation') }}</span
                >
            </template>
            <template #cell-assignee="{ row }">
                <select
                    v-if="canManageOperations"
                    :value="itemOf(row.id).assigned_to ?? ''"
                    :disabled="
                        Boolean(itemOf(row.id).submitted_at) &&
                        row.status !== 'completed'
                    "
                    :class="cellSelect"
                    :aria-label="`${t('Assigned to')} ${row.request}`"
                    @change="
                        update(
                            itemOf(row.id),
                            itemOf(row.id).status,
                            Number(
                                ($event.target as HTMLSelectElement).value,
                            ) || null,
                        )
                    "
                >
                    <option value="">{{ t('Unassigned') }}</option>
                    <option
                        v-for="member in members"
                        :key="member.id"
                        :value="member.id"
                    >
                        {{ member.name }}
                    </option>
                </select>
                <span v-else>{{
                    members.find(
                        (member) => member.id === itemOf(row.id).assigned_to,
                    )?.name ?? '—'
                }}</span>
            </template>
            <template #cell-vendor="{ row }">
                <select
                    v-if="canManageOperations"
                    :value="itemOf(row.id).vendor_id ?? ''"
                    :disabled="locked(itemOf(row.id))"
                    :class="cellSelect"
                    :aria-label="`${t('Vendor')} ${row.request}`"
                    @change="
                        updateWorkOrder(itemOf(row.id), {
                            vendor_id:
                                Number(
                                    ($event.target as HTMLSelectElement).value,
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
                <span v-else>{{
                    vendors.find(
                        (vendor) => vendor.id === itemOf(row.id).vendor_id,
                    )?.name ?? '—'
                }}</span>
            </template>
            <template #cell-cost="{ row }">
                <div class="flex items-center gap-2">
                    <Input
                        v-if="canManageOperations"
                        :disabled="locked(itemOf(row.id))"
                        :model-value="itemOf(row.id).actual_cost || ''"
                        class="h-8 w-24"
                        type="number"
                        min="0"
                        :placeholder="t('Actual AED')"
                        :aria-label="`${t('Actual AED')} ${row.request}`"
                        @change="
                            updateWorkOrder(itemOf(row.id), {
                                actual_cost:
                                    ($event.target as HTMLInputElement).value ||
                                    null,
                            })
                        "
                    />
                    <span
                        v-if="
                            itemOf(row.id).estimated_cost ||
                            itemOf(row.id).actual_cost
                        "
                        class="text-muted-foreground text-xs whitespace-nowrap"
                        >{{ itemOf(row.id).actual_cost || '—' }} /
                        {{ itemOf(row.id).estimated_cost || '—' }}</span
                    >
                </div>
            </template>
            <template #cell-reason="{ row }">
                <Input
                    v-model="reasons[row.id]"
                    maxlength="2000"
                    class="h-8 w-48"
                    :aria-label="`${t('Reason for reopening / cancellation')} ${row.request}`"
                />
            </template>
        </CrmSettingsTable>

        <Sheet v-model:open="createOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('New request')
                    }}</SheetTitle>
                    <SheetDescription>{{
                        t('Track property service work and overdue requests.')
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    id="request-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="create"
                >
                    <div class="space-y-1">
                        <Label for="mr-property">{{ t('Property') }}</Label>
                        <select
                            id="mr-property"
                            v-model="form.property_id"
                            :class="selectClass"
                            required
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="String(property.id)"
                            >
                                {{ property.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.property_id" />
                    </div>
                    <div class="space-y-1">
                        <Label for="mr-title">{{ t('Request title') }}</Label
                        ><Input
                            id="mr-title"
                            v-model="form.title"
                            required
                        /><InputError :message="form.errors.title" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="mr-priority">{{ t('Priority') }}</Label>
                            <select
                                id="mr-priority"
                                v-model="form.priority"
                                :class="selectClass"
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
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label for="mr-due">{{ t('Due') }}</Label
                            ><Input
                                id="mr-due"
                                v-model="form.due_at"
                                type="datetime-local"
                            /><InputError :message="form.errors.due_at" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <Label for="mr-vendor">{{ t('Vendor') }}</Label>
                        <select
                            id="mr-vendor"
                            v-model="form.vendor_id"
                            :class="selectClass"
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
                    </div>
                    <div class="space-y-1">
                        <Label for="mr-est">{{ t('Estimated AED') }}</Label
                        ><Input
                            id="mr-est"
                            v-model="form.estimated_cost"
                            type="number"
                            min="0"
                        /><InputError :message="form.errors.estimated_cost" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.requires_manager_confirmation"
                            type="checkbox"
                        />{{
                            t('Require manager confirmation before completion')
                        }}</label
                    >
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="request-form"
                        :disabled="form.processing"
                        >{{ t('Create request') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="createOpen = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <Sheet v-model:open="vendorOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Vendors')
                    }}</SheetTitle>
                    <SheetDescription>{{ t('Add vendor') }}</SheetDescription>
                </SheetHeader>
                <form
                    id="vendor-form"
                    class="flex-1 space-y-4 overflow-y-auto p-4"
                    @submit.prevent="createVendor"
                >
                    <div class="space-y-1">
                        <Label for="mv-name">{{ t('Vendor name') }}</Label
                        ><Input
                            id="mv-name"
                            v-model="vendorForm.name"
                            required
                        /><InputError :message="vendorForm.errors.name" />
                    </div>
                    <div class="space-y-1">
                        <Label for="mv-trade">{{ t('Trade, e.g. HVAC') }}</Label
                        ><Input id="mv-trade" v-model="vendorForm.trade" />
                    </div>
                    <div class="space-y-1">
                        <Label for="mv-phone">{{ t('Phone') }}</Label
                        ><Input id="mv-phone" v-model="vendorForm.phone" />
                    </div>
                </form>
                <SheetFooter class="border-t">
                    <Button
                        type="submit"
                        form="vendor-form"
                        :disabled="vendorForm.processing"
                        >{{ t('Add vendor') }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        @click="vendorOpen = false"
                        >{{ t('Cancel') }}</Button
                    >
                </SheetFooter>
            </SheetContent>
        </Sheet>
    </div>
</template>
