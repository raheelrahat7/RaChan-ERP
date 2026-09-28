import type { SlaCycle } from '@/types/sla';

export type ReportFilters = {
    property_id?: number | null;
    vendor_id?: number | null;
    assigned_to?: number | null;
    status?: string | null;
    priority?: string | null;
    created_from?: string | null;
    created_to?: string | null;
};
export type ReportCost = {
    currency: string;
    estimated_cost: string;
    actual_cost: string;
    labor_cost: string;
    material_cost: string;
    recorded_cost: string;
    missing_estimates: number;
    missing_actuals: number;
};
export type ReportJob = {
    id: number;
    reference: string;
    title: string;
    status: string;
    priority: string;
    property: string;
    vendor: string;
    assignee: string;
    currency: string;
    age_days: number | null;
    estimated_cost: string | null;
    actual_cost: string | null;
    labor_cost: string;
    material_cost: string;
    recorded_cost: string;
    preventive_result: string | null;
    sla_cycles: SlaCycle[];
};
