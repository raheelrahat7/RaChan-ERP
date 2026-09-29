import type {
    CommissionSplit,
    CostCentreRow,
    DealStage,
    HomeAlert,
    HomeProps,
    HomeTrend,
    Insight,
    LeadPipeline,
    LeadSource,
    LegacyMetrics,
    TopAgent,
} from '@/types/home';

export type Figure = {
    value: number | null;
    change: number | null;
    count: number | null;
    soon: boolean;
};

export type FigureKey =
    | 'revenue'
    | 'expenses'
    | 'net_profit'
    | 'cash_balance'
    | 'receivables'
    | 'payables'
    | 'vat_payable'
    | 'commission_payable'
    | 'active_deals'
    | 'expiring_contracts'
    | 'pdc_due'
    | 'bounced_cheques'
    | 'pending_approvals'
    | 'overdue_tasks';

export type HomeView = {
    hasKpis: boolean;
    figures: Record<FigureKey, Figure>;
    trend: HomeTrend | null;
    commission: CommissionSplit | null;
    topAgents: TopAgent[] | null;
    leadPipeline: LeadPipeline | null;
    leadSources: LeadSource[] | null;
    dealPipeline: DealStage[] | null;
    costCentres: CostCentreRow[] | null;
    insights: Insight[] | null;
    alerts: HomeAlert[];
    legacy: LegacyMetrics | null;
};

export type Sentence = {
    key: string;
    params?: Record<string, string | number>;
    emphasis?: boolean;
};

export type AttentionItem = {
    tag: string;
    tone: 'danger' | 'warning' | 'info' | 'brand';
    key: string;
    params: Record<string, string | number>;
    href: string | null;
    amount?: number;
    count?: number;
};

const SOON: Figure = { value: null, change: null, count: null, soon: true };

type Numeric = number | string | null | undefined;

/** Laravel sends DECIMAL sums and decimal casts as strings; coerce once here. */
function num(value: Numeric): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const number = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(number) ? number : null;
}

function amount(value: Numeric): number {
    return num(value) ?? 0;
}

export function percentChange(
    value: number,
    previous: number | null,
): number | null {
    if (previous === null || previous === 0) {
        return null;
    }

    return Math.round(((value - previous) / Math.abs(previous)) * 1000) / 10;
}

function figure(
    raw: Numeric,
    previous: Numeric = null,
    count: Numeric = null,
): Figure {
    const value = num(raw);
    if (value === null) {
        return SOON;
    }

    return {
        value,
        change: percentChange(value, num(previous)),
        count: num(count),
        soon: false,
    };
}

export function normalizeHome(props: HomeProps): HomeView {
    const k = props.kpis;

    return {
        hasKpis: Boolean(k),
        figures: {
            revenue: figure(k?.revenue.value, k?.revenue.previous ?? null),
            expenses: figure(k?.expenses.value, k?.expenses.previous ?? null),
            net_profit: figure(
                k?.net_profit.value,
                k?.net_profit.previous ?? null,
            ),
            cash_balance:
                k && num(k.cash_balance.value) !== null
                    ? {
                          value: num(k.cash_balance.value),
                          change: num(k.cash_balance.change_7d_pct),
                          count: null,
                          soon: false,
                      }
                    : SOON,
            receivables: figure(
                k ? k.receivables.value : props.metrics?.outstandingAed,
            ),
            payables: figure(k?.payables.value),
            vat_payable: figure(k?.vat_payable.value),
            commission_payable: figure(
                k?.commission_payable?.value,
                null,
                k?.commission_payable?.agents ?? null,
            ),
            active_deals: figure(k?.active_deals.count),
            expiring_contracts: figure(k?.expiring_contracts.count),
            pdc_due: figure(
                k?.pdc_due?.amount,
                null,
                k?.pdc_due?.count ?? null,
            ),
            bounced_cheques: figure(
                k?.bounced_cheques?.amount,
                null,
                k?.bounced_cheques?.count ?? null,
            ),
            pending_approvals: figure(k?.pending_approvals?.count),
            overdue_tasks: figure(k?.overdue_tasks?.count),
        },
        trend: props.trend
            ? {
                  months: props.trend.months,
                  sales_value: props.trend.sales_value.map(amount),
                  rental_value: props.trend.rental_value.map(amount),
              }
            : null,
        commission: props.commission_split
            ? {
                  net_company: amount(props.commission_split.net_company),
                  agent_payable: amount(props.commission_split.agent_payable),
                  co_broker: amount(props.commission_split.co_broker),
                  referral: amount(props.commission_split.referral),
              }
            : null,
        topAgents:
            props.top_agents?.map((agent) => ({
                ...agent,
                commission: amount(agent.commission),
                deals: amount(agent.deals),
            })) ?? null,
        leadPipeline: props.lead_pipeline
            ? {
                  pipeline: props.lead_pipeline.pipeline,
                  stages: props.lead_pipeline.stages.map((stage) => ({
                      ...stage,
                      count: amount(stage.count),
                  })),
              }
            : null,
        leadSources:
            props.lead_sources?.map((source) => ({
                ...source,
                count: amount(source.count),
            })) ?? null,
        dealPipeline:
            props.deal_pipeline?.stages.map((stage) => ({
                ...stage,
                count: amount(stage.count),
                value: amount(stage.value),
            })) ?? null,
        costCentres:
            props.cost_centres?.map((row) => ({
                ...row,
                revenue: amount(row.revenue),
                expense: amount(row.expense),
                profit: amount(row.profit),
            })) ?? null,
        insights: props.insights ?? null,
        alerts: props.alerts ?? [],
        legacy: props.metrics ?? null,
    };
}

function pct(value: number): string {
    return `${Number(value.toFixed(1))}%`;
}

export function summarySentences(view: HomeView): Sentence[] {
    const sentences: Sentence[] = [];
    const revenue = view.figures.revenue;
    if (!revenue.soon && revenue.change !== null) {
        if (revenue.change > 0.05) {
            sentences.push({
                key: 'Revenue is up :change on the previous period.',
                params: { change: pct(revenue.change) },
            });
        } else if (revenue.change < -0.05) {
            sentences.push({
                key: 'Revenue is down :change on the previous period.',
                params: { change: pct(-revenue.change) },
            });
        } else {
            sentences.push({
                key: 'Revenue is level with the previous period.',
            });
        }
    } else if (!view.hasKpis) {
        sentences.push({ key: 'Here is where things stand today.' });
    }

    const bounced = view.figures.bounced_cheques.count ?? 0;
    if (bounced > 0) {
        sentences.push(
            bounced === 1
                ? { key: '1 cheque bounced this week.', emphasis: true }
                : {
                      key: ':count cheques bounced this week.',
                      params: { count: bounced },
                      emphasis: true,
                  },
        );
    }
    const approvals = view.figures.pending_approvals.value ?? 0;
    if (approvals > 0) {
        sentences.push(
            approvals === 1
                ? { key: '1 approval is waiting for you.', emphasis: true }
                : {
                      key: ':count approvals are waiting for you.',
                      params: { count: approvals },
                      emphasis: true,
                  },
        );
    }
    if (bounced === 0 && approvals === 0) {
        const alerts = view.alerts.reduce((sum, alert) => sum + alert.count, 0);
        if (alerts === 0) {
            sentences.push({ key: 'Nothing needs your attention right now.' });
        } else if (alerts === 1) {
            sentences.push({
                key: '1 item needs your attention.',
                emphasis: true,
            });
        } else {
            sentences.push({
                key: ':count items need your attention.',
                params: { count: alerts },
                emphasis: true,
            });
        }
    }

    return sentences;
}

export function attentionItems(view: HomeView): AttentionItem[] {
    const items: AttentionItem[] = [];
    const {
        bounced_cheques: bounced,
        pdc_due: due,
        expiring_contracts: expiring,
        pending_approvals: approvals,
    } = view.figures;

    if (!bounced.soon && (bounced.count ?? 0) > 0) {
        items.push({
            tag: 'PDC',
            tone: 'danger',
            key: ':count cheques bounced this week',
            params: { count: bounced.count ?? 0 },
            href: '/lease-compliance',
            amount: bounced.value ?? undefined,
        });
    }
    if (!due.soon && (due.count ?? 0) > 0) {
        items.push({
            tag: 'PDC',
            tone: 'warning',
            key: ':count cheques due in the next 30 days',
            params: { count: due.count ?? 0 },
            href: '/lease-compliance',
            amount: due.value ?? undefined,
        });
    }
    if (!expiring.soon && (expiring.value ?? 0) > 0) {
        items.push({
            tag: 'Lease',
            tone: 'warning',
            key: ':count contracts end in the next 30 days',
            params: { count: expiring.value ?? 0 },
            href: '/lease-compliance',
        });
    }
    if (!approvals.soon && (approvals.value ?? 0) > 0) {
        items.push({
            tag: 'Approval',
            tone: 'brand',
            key: ':count approvals are waiting',
            params: { count: approvals.value ?? 0 },
            href: null,
        });
    }
    for (const alert of view.alerts) {
        items.push({
            tag: 'Alert',
            tone: 'info',
            key: alert.title,
            params: {},
            href: alert.href,
            count: alert.count,
        });
    }

    return items;
}

export function greetingKey(hour: number): string {
    if (hour >= 5 && hour < 12) {
        return 'Good morning, :name.';
    }

    return hour >= 12 && hour < 17
        ? 'Good afternoon, :name.'
        : 'Good evening, :name.';
}
