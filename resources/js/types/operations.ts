export type OperationsSummary = {
    total: number;
    active: number;
    overdue: number;
    unassigned: number;
    urgent: number;
    on_hold: number;
    completed: number;
    cancelled: number;
    due_plans: number;
};

export type OperationsPage<T> = {
    data: T[];
    total: number;
    links: { label: string; url: string | null; active: boolean }[];
};

export type MaintenanceBacklogRow = {
    id: number;
    reference: string;
    title: string;
    property: string | null;
    vendor: string | null;
    assignee: string;
    priority: string;
    status: string;
    due_at: string | null;
    overdue: number;
};

export type PreventivePlanRow = {
    id: number;
    title: string;
    property: string | null;
    vendor: string | null;
    next_due_on: string;
};

export type OperationsWorkloadRow = {
    assignee_id: number | null;
    assignee: string;
    active: number;
    overdue: number;
    on_hold: number;
};
