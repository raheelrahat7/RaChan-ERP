<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import LeaseDetailSheet from '@/components/LeaseDetailSheet.vue';
import LeaseRenewDialog from '@/components/LeaseRenewDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
import { LEASE_TABS, leaseQuery, moneyText, renewalText } from '@/lib/leases';
import type { DataTableColumn } from '@/lib/data-table';
import type { LeaseRow, LeaseTab } from '@/lib/leases';

type Paged = {
    data: LeaseRow[];
    current_page: number;
    last_page: number;
    total: number;
};
type Row = LeaseRow & {
    tenant_name: string;
    unit_number: string;
    rentText: string;
    advanceText: string;
    renewal: string;
    actions: string;
};

const props = defineProps<{
    commissionPlanId: string;
    canManage: boolean;
}>();
const { t } = useLocale();

const tab = ref<LeaseTab | ''>('');
const query = ref('');
const leases = ref<Paged>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const counts = ref<Record<string, number>>({});
const loading = ref(true);
const loadError = ref('');
const actionError = ref('');
const detail = ref<LeaseRow | null>(null);
const detailOpen = ref(false);
const renewing = ref<LeaseRow | null>(null);
const renewOpen = ref(false);
let timer: number | null = null;

const rows = computed<Row[]>(() =>
    leases.value.data.map((lease) => ({
        ...lease,
        tenant_name: lease.tenant?.name ?? '—',
        unit_number: lease.unit?.number ?? '—',
        rentText: moneyText(lease.rent_amount, lease.currency),
        advanceText: moneyText(lease.advance_amount, lease.currency),
        renewal: renewalText(lease, Date.now()),
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'reference', label: t('Reference') },
    { key: 'tenancy_number', label: t('Tenancy no.') },
    { key: 'tenant_name', label: t('Tenant') },
    { key: 'unit_number', label: t('Unit') },
    { key: 'ends_on', label: t('Ends on') },
    { key: 'renewal', label: t('Renewal in') },
    { key: 'rentText', label: t('Rent'), align: 'end' },
    { key: 'advanceText', label: t('Advance'), align: 'end' },
    { key: 'status', label: t('Status') },
    { key: 'actions', label: '' },
]);

async function load(page = 1): Promise<void> {
    loading.value = true;
    try {
        const data = await apiJson<{
            leases: Paged;
            tabCounts: Record<string, number>;
        }>(
            `/agreements/leases/data?${leaseQuery(query.value, tab.value, page)}`,
        );
        leases.value = data.leases;
        counts.value = data.tabCounts;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load leases.');
    } finally {
        loading.value = false;
    }
}
function reload(): void {
    void load(leases.value.current_page);
}
defineExpose({ reload });

watch(tab, () => void load(1));
watch(query, () => {
    if (timer) {
        clearTimeout(timer);
    }
    timer = window.setTimeout(() => void load(1), 300);
});
onMounted(() => void load());
onBeforeUnmount(() => timer && clearTimeout(timer));

function showDetail(lease: LeaseRow): void {
    detail.value = lease;
    detailOpen.value = true;
}
function startRenew(lease: LeaseRow): void {
    renewing.value = lease;
    renewOpen.value = true;
}
function activate(lease: LeaseRow): void {
    if (confirm(`${t('Activate')} ${lease.reference}?`)) {
        actionError.value = '';
        router.post(
            `/agreements/leases/${lease.id}/activate`,
            { commission_plan_id: props.commissionPlanId || null },
            {
                preserveScroll: true,
                onSuccess: reload,
                onError: (errors) => {
                    actionError.value =
                        Object.values(errors)[0] ??
                        t('Could not activate this lease.');
                },
            },
        );
    }
}
</script>

<template>
    <div class="space-y-3">
        <nav :aria-label="t('Lease status')" class="flex flex-wrap gap-1.5">
            <button
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-medium"
                :class="
                    tab === ''
                        ? 'bg-primary text-primary-foreground border-primary'
                        : 'hover:bg-muted'
                "
                @click="tab = ''"
            >
                {{ t('All') }}
            </button>
            <button
                v-for="item in LEASE_TABS"
                :key="item.key"
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-medium"
                :class="
                    tab === item.key
                        ? 'bg-primary text-primary-foreground border-primary'
                        : 'hover:bg-muted'
                "
                @click="tab = item.key"
            >
                {{ t(item.label) }} ({{ counts[item.key] ?? 0 }})
            </button>
        </nav>
        <p v-if="actionError" role="alert" class="text-destructive text-sm">
            {{ actionError }}
        </p>
        <Input
            v-model="query"
            type="search"
            class="w-full sm:w-72"
            :placeholder="t('Reference or tenancy number')"
            :aria-label="t('Search leases')"
        />
        <DataTable
            :columns="columns"
            :rows="rows"
            :row-key="(row) => row.id"
            :row-label="(row) => row.reference"
            :loading="loading"
            :error="loadError || null"
            :empty-title="t('No leases here.')"
            max-height=""
            @retry="reload"
        >
            <template #cell-status="{ row }">
                <Badge variant="secondary">{{ row.status }}</Badge>
                <Badge v-if="row.renewal_due" variant="outline" class="ms-1">{{
                    t('Renewal due')
                }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex flex-wrap justify-end gap-1.5">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="showDetail(row)"
                        >{{ t('Details') }}</Button
                    >
                    <Button
                        v-if="canManage && row.status === 'draft'"
                        type="button"
                        size="sm"
                        @click="activate(row)"
                        >{{ t('Activate') }}</Button
                    >
                    <Button
                        v-if="row.permissions.renew"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="startRenew(row)"
                        >{{ t('Renew') }}</Button
                    >
                </div>
            </template>
        </DataTable>
        <div
            v-if="leases.last_page > 1"
            class="flex items-center justify-center gap-3 text-sm"
        >
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="leases.current_page <= 1"
                @click="load(leases.current_page - 1)"
                >{{ t('Previous') }}</Button
            >
            <span>{{ leases.current_page }} / {{ leases.last_page }}</span>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="leases.current_page >= leases.last_page"
                @click="load(leases.current_page + 1)"
                >{{ t('Next') }}</Button
            >
        </div>
        <LeaseDetailSheet
            v-model:open="detailOpen"
            :lease="detail"
            @saved="reload"
        />
        <LeaseRenewDialog
            v-model:open="renewOpen"
            :lease="renewing"
            @renewed="reload"
        />
    </div>
</template>
