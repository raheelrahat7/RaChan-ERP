export type DealStage = {
    id: number;
    name: string;
    type: 'normal' | 'on_hold' | 'won' | 'lost';
    color: string;
    position: number;
    active: boolean;
    is_initial?: boolean;
};

export type DealPipeline = {
    id: number;
    name: string;
    active: boolean;
    is_default?: boolean;
    stages: DealStage[];
    /** Assignable people for this pipeline, as the server allows for the current user. */
    members?: { id: number; name: string }[];
    /** Action to allowed scopes, e.g. { add: ['own'], move: ['organization'] }. */
    permissions?: Record<string, string[]>;
};

export type Deal = {
    id: number;
    title: string;
    category: string;
    /** Decimal string from the server; absent when the user may not see amounts. */
    amount?: string | number | null;
    currency?: string | null;
    pipeline_id: number;
    current_stage_id: number;
    first_name?: string | null;
    last_name?: string | null;
    email?: string | null;
    phone?: string | null;
    company?: string | null;
    source?: string | null;
    notes?: string | null;
    expected_close_date?: string | null;
    assigned_to?: number | null;
    assignee?: { id: number; name: string } | null;
    lead_id?: number | null;
    version: number;
    created_at: string;
    permissions?: Record<string, boolean>;
};

export type StageCount = {
    pipeline_id: number;
    current_stage_id: number;
    total: number | string;
    amount?: number | string | null;
};

/** Per-stage figures shown under the stage header. */
export type StageTotal = { id: number; count: number; amount: number | null };

export type CategoryOption = {
    code: string;
    name: string;
    active: boolean;
    id?: number | null;
};
