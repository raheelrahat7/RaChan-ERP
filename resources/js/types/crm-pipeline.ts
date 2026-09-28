export type Stage = {
    id: number;
    name: string;
    description: string | null;
    type: 'normal' | 'on_hold' | 'won' | 'lost';
    color: string;
    position: number;
    active: boolean;
    is_initial: boolean;
    allowed_from_stage_ids?: number[] | null;
    entry_roles?: string[] | null;
    required_fields?: string[] | null;
    notify_assignee_on_entry?: boolean;
    follow_up_due_days?: number | null;
    assignment_member_ids?: number[] | null;
};
export type LostReason = {
    id: number;
    name: string;
    description: string | null;
    position: number;
    active: boolean;
};
export type Pipeline = {
    id: number;
    name: string;
    description: string | null;
    active: boolean;
    is_default: boolean;
    stages: Stage[];
    reasons: LostReason[];
};

export type TransitionOption = { stage_id: number; reasons: string[] };
