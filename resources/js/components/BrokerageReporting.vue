<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    brokerageQuery,
    canClawback,
    clawbackBody,
    clawbackError,
    isNegative,
    money,
    remainingText,
    teamName,
} from '@/lib/commissions';
import type { DataTableColumn } from '@/lib/data-table';
import type { Clawback, Commission, TeamSummary } from '@/lib/commissions';

type Paged = {
    data: Commission[];
    current_page: number;
    last_page: number;
    total: number;
};
type Row = Commission & {
    broker: string;
    team: string;
    commissionText: string;
    netCompany: string;
    agentText: string;
    clawbackText: string;
    contribution: string;
    actions: string;
};

const props = defineProps<{ view: 'transactions' | 'teams' }>();
const { t } = useLocale();
const selectClass =
    'border-input bg-background h-9 rounded-md border px-3 text-sm';

const commissions = ref<Paged>({
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
});
const teams = ref<TeamSummary[]>([]);
const teamId = ref('');
const loading = ref(true);
const loadError = ref('');

const sheetOpen = ref(false);
const selected = ref<Commission | null>(null);
const clawbacks = ref<Clawback[]>([]);
const form = ref({ amount: '' as string | number, reason: '' });
const errors = ref<Record<string, string>>({});
const message = ref('');
const processing = ref(false);

const rows = computed<Row[]>(() =>
    commissions.value.data.map((item) => ({
        ...item,
        broker: item.broker_name ?? '—',
        team: teamName(item.team_name),
        commissionText: money(item.commission_amount, item.currency ?? 'AED'),
        netCompany: money(item.net_company, item.currency ?? 'AED'),
        agentText: money(item.agent_payable, item.currency ?? 'AED'),
        clawbackText: money(item.clawback_total, item.currency ?? 'AED'),
        contribution: money(item.net_contribution, item.currency ?? 'AED'),
        actions: '',
    })),
);
const columns = computed<DataTableColumn<Row>[]>(() => [
    { key: 'broker', label: t('Broker') },
    { key: 'team', label: t('Team') },
    { key: 'commissionText', label: t('Commission'), align: 'end' },
    { key: 'allocation_status', label: t('Allocation') },
    { key: 'netCompany', label: t('Net company'), align: 'end' },
    { key: 'agentText', label: t('Agent payable'), align: 'end' },
    { key: 'clawbackText', label: t('Clawbacks'), align: 'end' },
    { key: 'contribution', label: t('Net contribution'), align: 'end' },
    { key: 'actions', label: '' },
]);
const teamColumns = computed<DataTableColumn<TeamSummary & { name: string }>[]>(
    () => [
        { key: 'name', label: t('Team') },
        { key: 'total_commissions', label: t('Commissions'), align: 'end' },
        { key: 'gross_commission', label: t('Gross commission'), align: 'end' },
        { key: 'clawback_total', label: t('Clawbacks'), align: 'end' },
        { key: 'net_contribution', label: t('Net contribution'), align: 'end' },
    ],
);
const teamRows = computed(() =>
    teams.value.map((team) => ({ ...team, name: teamName(team.team_name) })),
);
const teamOptions = computed(() =>
    teams.value.filter((team) => team.team_id !== null),
);

async function load(page = 1): Promise<void> {
    loading.value = true;
    try {
        const data = await apiJson<{
            commissions: Paged;
            teamSummary: TeamSummary[];
        }>(`/real-estate/brokerage/data?${brokerageQuery(teamId.value, page)}`);
        commissions.value = data.commissions;
        teams.value = data.teamSummary;
        loadError.value = '';
    } catch {
        loadError.value = t('Could not load commissions.');
    } finally {
        loading.value = false;
    }
}
watch(teamId, () => void load(1));
onMounted(() => void load());

async function open(commission: Commission): Promise<void> {
    selected.value = commission;
    clawbacks.value = [];
    form.value = { amount: '', reason: '' };
    errors.value = {};
    message.value = '';
    sheetOpen.value = true;
    await refreshDetail(commission.id);
}
async function refreshDetail(id: number): Promise<void> {
    try {
        const data = await apiJson<{
            commission: Commission;
            clawbacks: Clawback[];
        }>(`/real-estate/brokerage/commissions/${id}`);
        selected.value = data.commission;
        clawbacks.value = data.clawbacks;
    } catch {
        message.value = t('Could not load this commission.');
    }
}

async function record(): Promise<void> {
    if (!selected.value) {
        return;
    }
    errors.value = {};
    message.value = '';
    const problem = clawbackError(selected.value, form.value);
    if (problem) {
        errors.value = {
            [problem === 'reason' ? 'reason' : 'amount']:
                problem === 'range'
                    ? `${t('The clawback cannot exceed the remaining')} ${remainingText(selected.value)}.`
                    : problem === 'reason'
                      ? t('Enter a reason.')
                      : t('Enter a positive amount with at most two decimals.'),
        };

        return;
    }
    processing.value = true;
    try {
        await apiJson(
            `/real-estate/brokerage/commissions/${selected.value.id}/clawbacks`,
            'POST',
            clawbackBody(selected.value, form.value),
        );
        form.value = { amount: '', reason: '' };
        message.value = t('Clawback recorded.');
        await Promise.all([
            refreshDetail(selected.value.id),
            load(commissions.value.current_page),
        ]);
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.fieldErrors();
            message.value =
                errors.value.expected_version ??
                errors.value.commission ??
                (Object.keys(errors.value).length ? '' : error.message);
        } else {
            message.value = t('Something went wrong. Please try again.');
        }
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <div class="space-y-3">
        <p class="text-muted-foreground max-w-3xl text-sm">
            {{
                t(
                    'Net contribution is the approved company share less recorded clawbacks. Clawbacks are reporting figures only: they do not post an accounting reversal or change a paid commission.',
                )
            }}
        </p>
        <p v-if="loadError" role="alert" class="text-destructive text-sm">
            {{ loadError }}
        </p>
        <template v-if="view === 'transactions'">
            <div class="flex flex-wrap items-center gap-2">
                <select
                    v-model="teamId"
                    :class="selectClass"
                    :aria-label="t('Team')"
                >
                    <option value="">{{ t('All teams') }}</option>
                    <option
                        v-for="team in teamOptions"
                        :key="team.team_id ?? 0"
                        :value="String(team.team_id)"
                    >
                        {{ team.team_name }}
                    </option>
                </select>
            </div>
            <DataTable
                :columns="columns"
                :rows="rows"
                :row-key="(row) => row.id"
                :row-label="(row) => row.broker"
                :loading="loading"
                :error="loadError || null"
                :empty-title="t('No commissions yet.')"
                max-height=""
                @retry="load(commissions.current_page)"
            >
                <template #cell-allocation_status="{ row }">
                    <Badge v-if="row.allocation_status" variant="secondary">{{
                        row.allocation_status
                    }}</Badge
                    ><template v-else>—</template>
                </template>
                <template #cell-contribution="{ row }">
                    <span
                        :class="
                            isNegative(row.net_contribution) &&
                            'text-destructive'
                        "
                        >{{ row.contribution }}</span
                    >
                </template>
                <template #cell-actions="{ row }">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="open(row)"
                        >{{ t('Details') }}</Button
                    >
                </template>
            </DataTable>
            <div
                v-if="commissions.last_page > 1"
                class="flex items-center justify-center gap-3 text-sm"
            >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="commissions.current_page <= 1"
                    @click="load(commissions.current_page - 1)"
                    >{{ t('Previous') }}</Button
                >
                <span
                    >{{ commissions.current_page }} /
                    {{ commissions.last_page }}</span
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="
                        commissions.current_page >= commissions.last_page
                    "
                    @click="load(commissions.current_page + 1)"
                    >{{ t('Next') }}</Button
                >
            </div>
        </template>
        <template v-else>
            <Card v-if="!loading && !teamRows.length"
                ><CardContent class="text-muted-foreground text-sm">{{
                    t('No team figures yet.')
                }}</CardContent></Card
            >
            <DataTable
                v-else
                :columns="teamColumns"
                :rows="teamRows"
                :row-key="(row) => row.team_id ?? 0"
                :row-label="(row) => row.name"
                :loading="loading"
                max-height=""
            >
                <template #cell-net_contribution="{ row }">
                    <span
                        :class="
                            isNegative(row.net_contribution) &&
                            'text-destructive'
                        "
                        >{{ money(row.net_contribution) }}</span
                    >
                </template>
                <template #cell-gross_commission="{ row }">{{
                    money(row.gross_commission)
                }}</template>
                <template #cell-clawback_total="{ row }">{{
                    money(row.clawback_total)
                }}</template>
            </DataTable>
        </template>

        <Sheet v-model:open="sheetOpen">
            <SheetContent class="w-full gap-0 sm:max-w-md" side="right">
                <SheetHeader class="border-b">
                    <SheetTitle class="font-display text-2xl font-medium">{{
                        t('Commission details')
                    }}</SheetTitle>
                    <SheetDescription
                        >{{ selected?.broker_name }} ·
                        {{
                            teamName(selected?.team_name ?? null)
                        }}</SheetDescription
                    >
                </SheetHeader>
                <div
                    v-if="selected"
                    class="flex-1 space-y-5 overflow-y-auto p-4"
                >
                    <p v-if="message" role="status" class="text-sm">
                        {{ message }}
                    </p>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                {{ t('Commission') }}
                            </dt>
                            <dd class="font-medium">
                                {{
                                    money(
                                        selected.commission_amount,
                                        selected.currency ?? 'AED',
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                {{ t('Net company') }}
                            </dt>
                            <dd class="font-medium">
                                {{
                                    money(
                                        selected.net_company,
                                        selected.currency ?? 'AED',
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                {{ t('Clawbacks') }}
                            </dt>
                            <dd class="font-medium">
                                {{
                                    money(
                                        selected.clawback_total,
                                        selected.currency ?? 'AED',
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                {{ t('Net contribution') }}
                            </dt>
                            <dd
                                class="font-medium"
                                :class="
                                    isNegative(selected.net_contribution) &&
                                    'text-destructive'
                                "
                            >
                                {{
                                    money(
                                        selected.net_contribution,
                                        selected.currency ?? 'AED',
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                    <section class="space-y-2">
                        <h3 class="text-eyebrow">
                            {{ t('Clawbacks') }} ({{ clawbacks.length }})
                        </h3>
                        <p
                            v-if="!clawbacks.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No clawbacks recorded.') }}
                        </p>
                        <ul
                            v-else
                            role="list"
                            class="divide-y rounded-md border text-sm"
                        >
                            <li
                                v-for="item in clawbacks"
                                :key="item.id"
                                class="px-3 py-2"
                            >
                                <p class="font-medium">AED {{ item.amount }}</p>
                                <p class="text-muted-foreground">
                                    {{ item.reason }}
                                </p>
                            </li>
                        </ul>
                    </section>
                    <form
                        v-if="canClawback(selected)"
                        class="space-y-3"
                        @submit.prevent="record"
                    >
                        <h3 class="text-eyebrow">{{ t('Record clawback') }}</h3>
                        <p class="text-muted-foreground text-xs">
                            {{ t('Remaining') }}: AED
                            {{ remainingText(selected) }}
                        </p>
                        <div class="space-y-1">
                            <Label for="cb-amount">{{
                                t('Amount (AED)')
                            }}</Label>
                            <Input
                                id="cb-amount"
                                v-model="form.amount"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                            <InputError :message="errors.amount" />
                        </div>
                        <div class="space-y-1">
                            <Label for="cb-reason">{{ t('Reason') }}</Label>
                            <textarea
                                id="cb-reason"
                                v-model="form.reason"
                                rows="3"
                                maxlength="2000"
                                class="border-input bg-background w-full rounded-md border p-2 text-sm"
                            />
                            <InputError :message="errors.reason" />
                        </div>
                        <InputError :message="errors.expected_version" />
                        <Button type="submit" :disabled="processing">{{
                            t('Record clawback')
                        }}</Button>
                    </form>
                    <p
                        v-else-if="selected.permissions.record_clawback"
                        class="text-muted-foreground text-sm"
                    >
                        {{
                            t(
                                'No clawback can be recorded: only AED commissions with an amount remaining accept one.',
                            )
                        }}
                    </p>
                </div>
            </SheetContent>
        </Sheet>
    </div>
</template>
