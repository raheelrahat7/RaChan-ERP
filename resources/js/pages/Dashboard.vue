<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    BadgePercent,
    Banknote,
    Building,
    CalendarCheck,
    CalendarClock,
    Clock3,
    FileMinus,
    FileText,
    Gem,
    Handshake,
    ListTodo,
    Receipt,
    SquareCheck,
    TrendingDown,
    TrendingUp,
    UsersRound,
    Wallet,
    Wrench,
} from '@lucide/vue';
import { computed } from 'vue';
import AttentionList from '@/components/home/AttentionList.vue';
import BarList from '@/components/home/BarList.vue';
import ComingSoon from '@/components/home/ComingSoon.vue';
import CommissionDonut from '@/components/home/CommissionDonut.vue';
import CostCentreTable from '@/components/home/CostCentreTable.vue';
import FigureGroup from '@/components/home/FigureGroup.vue';
import HomeFigure from '@/components/home/HomeFigure.vue';
import HomeFilters from '@/components/home/HomeFilters.vue';
import HomePanel from '@/components/home/HomePanel.vue';
import HomeWelcome from '@/components/home/HomeWelcome.vue';
import InsightsPanel from '@/components/home/InsightsPanel.vue';
import StageRail from '@/components/home/StageRail.vue';
import TopAgents from '@/components/home/TopAgents.vue';
import ValueTrend from '@/components/home/ValueTrend.vue';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import type { Figure } from '@/lib/home';
import { normalizeHome } from '@/lib/home';
import type { HomeProps } from '@/types/home';

const props = defineProps<HomeProps>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Home', href: '/dashboard' }] },
});

const { t } = useLocale();
const { compact, money } = useFormat();
const page = usePage();

function can(ability: string): boolean {
    return page.props.abilities?.[ability] !== false;
}
const view = computed(() => normalizeHome(props));
const f = computed(() => view.value.figures);

function count(value: number | undefined): Figure {
    return {
        value: value ?? null,
        change: null,
        count: null,
        soon: value === undefined,
    };
}

const leadStages = computed(() =>
    (view.value.leadPipeline?.stages ?? []).map((stage) => ({
        label: stage.name,
        count: stage.count,
        won: stage.type === 'won',
    })),
);
const dealStages = computed(() =>
    (view.value.dealPipeline ?? []).map((stage) => ({
        label: stage.label,
        count: stage.count,
        won: ['closed', 'settled'].includes(stage.key),
    })),
);
</script>

<template>
    <Head :title="t('Home')" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <HomeFilters :filters="filters" />
        <HomeWelcome :view="view" />

        <FigureGroup
            title="Financial position"
            subtitle="Company-wide, selected period"
        >
            <HomeFigure
                label="Revenue"
                :icon="TrendingUp"
                :figure="f.revenue"
            />
            <HomeFigure
                label="Expenses"
                :icon="TrendingDown"
                :figure="f.expenses"
                invert-tone
            />
            <HomeFigure label="Net profit" :icon="Gem" :figure="f.net_profit" />
            <HomeFigure
                label="Cash balance"
                :icon="Wallet"
                :figure="f.cash_balance"
                note="this week"
            />
            <HomeFigure
                label="Receivables"
                :icon="FileText"
                :figure="f.receivables"
                note="Customer ledger"
            />
            <HomeFigure
                label="Payables"
                :icon="FileMinus"
                :figure="f.payables"
                note="Vendor ledger"
            />
        </FigureGroup>

        <FigureGroup
            title="Pipeline & obligations"
            subtitle="What needs action"
        >
            <HomeFigure
                label="Active deals"
                :icon="Handshake"
                :figure="f.active_deals"
                kind="count"
                note="In pipeline"
            />
            <HomeFigure
                label="Pending approvals"
                :icon="SquareCheck"
                :figure="f.pending_approvals"
                kind="count"
            />
            <HomeFigure
                label="Overdue tasks"
                :icon="ListTodo"
                :figure="f.overdue_tasks"
                kind="count"
            />
            <HomeFigure
                label="Expiring contracts"
                :icon="CalendarClock"
                :figure="f.expiring_contracts"
                kind="count"
                note="Next 30 days"
            />
            <HomeFigure
                label="Cheques due"
                :icon="Banknote"
                :figure="f.pdc_due"
                note=":count in the next 30 days"
                :note-params="{ count: f.pdc_due.count ?? 0 }"
            />
            <HomeFigure
                label="VAT payable"
                :icon="Receipt"
                :figure="f.vat_payable"
                note="Net of input tax"
            />
        </FigureGroup>

        <FigureGroup
            v-if="view.legacy"
            title="Portfolio & operations"
            subtitle="Live counts"
        >
            <HomeFigure
                label="Available units"
                :icon="Building"
                :figure="count(view.legacy.availableUnits)"
                kind="count"
            />
            <HomeFigure
                label="Reserved units"
                :icon="CalendarCheck"
                :figure="count(view.legacy.reservedUnits)"
                kind="count"
            />
            <HomeFigure
                label="Active leads"
                :icon="UsersRound"
                :figure="count(view.legacy.activeLeads)"
                kind="count"
            />
            <HomeFigure
                label="Open maintenance"
                :icon="Wrench"
                :figure="count(view.legacy.openMaintenance)"
                kind="count"
            />
            <HomeFigure
                label="Overdue maintenance"
                :icon="Clock3"
                :figure="count(view.legacy.overdueMaintenance)"
                kind="count"
            />
            <HomeFigure
                label="Commission payable"
                :icon="BadgePercent"
                :figure="f.commission_payable"
            />
        </FigureGroup>

        <div class="grid gap-6 xl:grid-cols-[2fr_1fr]">
            <ValueTrend :trend="view.trend" />
            <CommissionDonut :split="view.commission" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2 xl:grid-cols-3">
            <AttentionList :view="view" />
            <TopAgents :agents="view.topAgents" />
            <InsightsPanel :insights="view.insights" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <HomePanel
                title="Lead pipeline"
                :subtitle="view.leadPipeline?.pipeline?.name ?? 'CRM'"
            >
                <template #action>
                    <Link
                        v-if="can('crm')"
                        href="/crm/leads"
                        class="text-accent-text text-xs"
                        >{{ t('Open CRM') }} →</Link
                    >
                </template>
                <ComingSoon v-if="!view.leadPipeline" />
                <template v-else>
                    <StageRail :stages="leadStages" />
                    <p class="text-muted-foreground mt-5 mb-2.5 text-xs">
                        {{ t('Leads by source') }}
                    </p>
                    <ComingSoon v-if="view.leadSources === null" />
                    <BarList
                        v-else-if="view.leadSources.length"
                        :rows="
                            view.leadSources.map((source) => ({
                                label: source.source,
                                value: source.count,
                            }))
                        "
                    />
                    <p v-else class="text-muted-foreground text-sm">
                        {{ t('No new leads this period.') }}
                    </p>
                </template>
            </HomePanel>
            <HomePanel title="Deal pipeline" subtitle="Deals & commission">
                <template #action>
                    <Link
                        v-if="can('deals')"
                        href="/agreements"
                        class="text-accent-text text-xs"
                        >{{ t('Open deals') }} →</Link
                    >
                </template>
                <ComingSoon v-if="!view.dealPipeline" />
                <template v-else>
                    <StageRail :stages="dealStages" />
                    <p class="text-muted-foreground mt-5 mb-2.5 text-xs">
                        {{ t('Deal value by stage') }}
                    </p>
                    <BarList
                        :rows="
                            view.dealPipeline.map((stage) => ({
                                label: t(stage.label),
                                value: stage.value,
                                display: compact(stage.value),
                            }))
                        "
                    />
                    <div
                        class="text-muted-foreground mt-4 flex items-center justify-between border-t pt-3.5 text-[13px]"
                    >
                        <span>{{
                            t('Commission payable across all agents')
                        }}</span>
                        <b
                            class="font-display text-foreground text-[22px] font-medium"
                            >{{
                                f.commission_payable.soon
                                    ? '—'
                                    : money(f.commission_payable.value, 'AED', {
                                          compact: true,
                                      })
                            }}</b
                        >
                    </div>
                </template>
            </HomePanel>
        </div>

        <CostCentreTable :rows="view.costCentres" />
    </div>
</template>
