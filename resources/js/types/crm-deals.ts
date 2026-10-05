export type DealKind = 'off_plan' | 'secondary' | 'resale' | 'listing';

export type DealStage = {
    id: number;
    name: string;
    type: 'normal' | 'on_hold' | 'won' | 'lost';
    color: string;
    position: number;
    active: boolean;
};

export type DealPipeline = {
    id: number;
    name: string;
    kind: DealKind;
    active: boolean;
    stages: DealStage[];
};

export type Deal = {
    id: number;
    title: string;
    amount: number | null;
    currency: string;
    kind: DealKind;
    pipeline_id: number;
    stage_id: number;
    contact: { id: number; name: string } | null;
    assignee: { id: number; name: string } | null;
    lead_id: number | null;
    created_at: string;
    next_activity_at: string | null;
};

export type StageTotal = { id: number; count: number; amount: number | null };
