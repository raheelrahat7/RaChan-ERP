export type DealRule = {
    id: number;
    name: string;
    pipeline_id: number;
    stage_id: number;
    action: string;
    active: boolean | number;
    conditions: unknown[] | null;
    working_hours_only: boolean | number;
    target_stage_id: number | null;
    delay_minutes: number;
    due_days: number | null;
    activity_type: string | null;
};
export type DealExecution = {
    id: number;
    rule_id: number;
    outcome: string;
    scheduled_at: string;
};

export const ACTION_LABELS: Record<string, string> = {
    notify_assignee: 'Notify the responsible person',
    notify_managers: 'Notify managers',
    create_follow_up: 'Create a follow-up activity',
    change_stage: 'Move the deal to another stage',
};
export const ACTIVITY_TYPES = ['call', 'email', 'meeting', 'task', 'note'];

const OUTCOME_LABELS: Record<string, string> = {
    pending: 'Waiting',
    completed: 'Done',
    blocked: 'Blocked',
    skipped_disabled: 'Skipped: rule disabled',
    skipped_changed: 'Skipped: rule changed',
    skipped_stale: 'Skipped: deal moved on',
    skipped_condition: 'Skipped: conditions not met',
    skipped_recipient: 'Skipped: nobody to notify',
};

export function outcomeLabel(outcome: string): string {
    return OUTCOME_LABELS[outcome] ?? outcome;
}

/** "Immediately", "After 2 h", "After 3 d 4 h" — delays are stored in minutes. */
export function delayText(minutes: number): string {
    if (minutes <= 0) {
        return 'Immediately';
    }
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const rest = minutes % 60;
    const parts = [
        days ? `${days} d` : '',
        hours ? `${hours} h` : '',
        rest ? `${rest} min` : '',
    ].filter(Boolean);

    return `After ${parts.join(' ')}`;
}

export type RuleForm = {
    name: string;
    pipeline_id: number;
    stage_id: number;
    action: string;
    active: boolean;
    working_hours_only: boolean;
    target_stage_id: number | null;
    delay_minutes: number;
    due_days: number;
    activity_type: string;
};

export function ruleForm(
    rule: DealRule | null,
    pipelineId: number,
    stageId: number,
): RuleForm {
    return {
        name: rule?.name ?? '',
        pipeline_id: rule?.pipeline_id ?? pipelineId,
        stage_id: rule?.stage_id ?? stageId,
        action: rule?.action ?? 'notify_assignee',
        active: rule ? rule.active === true || rule.active === 1 : true,
        working_hours_only: rule
            ? rule.working_hours_only === true || rule.working_hours_only === 1
            : false,
        target_stage_id: rule?.target_stage_id ?? null,
        delay_minutes: rule?.delay_minutes ?? 0,
        due_days: rule?.due_days ?? 1,
        activity_type: rule?.activity_type ?? 'call',
    };
}

/** Sends only the fields the chosen action uses. */
export function rulePayload(
    form: RuleForm,
    conditions: unknown[],
): Record<string, unknown> {
    const data: Record<string, unknown> = {
        name: form.name.trim(),
        pipeline_id: form.pipeline_id,
        stage_id: form.stage_id,
        action: form.action,
        active: form.active,
        working_hours_only: form.working_hours_only,
        delay_minutes: Number(form.delay_minutes) || 0,
        conditions,
    };
    if (form.action === 'change_stage') {
        data.target_stage_id = form.target_stage_id;
    }
    if (form.action === 'create_follow_up') {
        data.due_days = Number(form.due_days) || 0;
        data.activity_type = form.activity_type;
    }

    return data;
}
