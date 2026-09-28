export type Money = number;

export type LegacyMetrics = {
    openMaintenance: number;
    overdueMaintenance: number;
    availableUnits: number;
    reservedUnits: number;
    activeLeads: number;
    outstandingAed: number;
};

export type HomeAlert = { title: string; count: number; href: string };

export type HomeFilters = {
    period: 'month' | 'quarter' | 'year';
    purpose: 'all' | 'sale' | 'rent';
    company_id: number | null;
    branch_id: number | null;
    range: { from: string; to: string };
    options: {
        companies: { id: number; name: string }[];
        branches: { id: number; name: string; company_id: number }[];
    };
};

export type HomeKpis = {
    revenue: { value: Money; previous: Money | null };
    expenses: { value: Money; previous: Money | null };
    net_profit: { value: Money; previous: Money | null };
    cash_balance: { value: Money; change_7d_pct: number | null };
    receivables: { value: Money };
    payables: { value: Money };
    vat_payable: { value: Money };
    commission_payable: { value: Money; agents: number };
    active_deals: { count: number };
    expiring_contracts: { count: number };
    pdc_due: { count: number; amount: Money };
    bounced_cheques: { count: number; amount: Money };
    pending_approvals: { count: number } | null;
    overdue_tasks: { count: number } | null;
};

export type HomeTrend = {
    months: string[];
    sales_value: Money[];
    rental_value: Money[];
};

export type CommissionSplit = {
    net_company: Money;
    agent_payable: Money;
    co_broker: Money;
    referral: Money;
};

export type TopAgent = {
    user_id: number;
    name: string;
    team: string | null;
    commission: Money;
    deals: number;
};

export type LeadPipeline = {
    pipeline: { id: number; name: string } | null;
    stages: { id: number; name: string; type: string; count: number }[];
};

export type LeadSource = { source: string; count: number };

export type DealStage = {
    key: string;
    label: string;
    count: number;
    value: Money;
};

export type CostCentreRow = {
    level: 0 | 1 | 2;
    company: string;
    branch: string | null;
    department: string | null;
    cost_centre: string | null;
    revenue: Money;
    expense: Money;
    profit: Money;
};

export type Insight = { icon: string; text: string };

export type HomeProps = {
    metrics?: LegacyMetrics;
    alerts?: HomeAlert[];
    filters?: HomeFilters;
    kpis?: HomeKpis;
    trend?: HomeTrend;
    commission_split?: CommissionSplit | null;
    top_agents?: TopAgent[];
    lead_pipeline?: LeadPipeline;
    lead_sources?: LeadSource[];
    deal_pipeline?: { stages: DealStage[] };
    cost_centres?: CostCentreRow[];
    insights?: Insight[];
};
