export type Rule = {
    id: number;
    name: string;
    pipeline_id: number;
    stage_id: number | null;
    trigger: string;
    condition_field: string | null;
    condition_operator: string | null;
    condition_value: string | null;
    action: string;
    due_days: number | null;
    delay_minutes?: number | null;
    activity_type?: string | null;
    target_stage_id?: number | null;
    active: boolean;
};
export type Execution = {
    id: number;
    rule_id: number;
    outcome: string;
    scheduled_at: string | null;
    failure_reason: string | null;
    created_at: string;
};
export type DelayUnit = 'minutes' | 'hours' | 'days';

export const ACTION_GROUPS: {
    key: string;
    label: string;
    actions: { action: string; label: string; hint: string }[];
}[] = [
    {
        key: 'alerts',
        label: 'Employee alerts',
        actions: [
            {
                action: 'notify_assignee',
                label: 'Notify responsible person',
                hint: 'An in-app notice to whoever is responsible for the lead.',
            },
            {
                action: 'notify_managers',
                label: 'Notify supervisors',
                hint: 'An in-app notice to the managers who can see the lead.',
            },
        ],
    },
    {
        key: 'activities',
        label: 'Activities',
        actions: [
            {
                action: 'create_follow_up',
                label: 'Schedule an activity',
                hint: 'Creates a call, email, meeting, task or note due after a set number of days.',
            },
        ],
    },
    {
        key: 'stage',
        label: 'Stage',
        actions: [
            {
                action: 'change_stage',
                label: 'Change stage',
                hint: 'Moves the lead to another active, non-final stage.',
            },
        ],
    },
];

export function actionLabel(action: string): string {
    for (const group of ACTION_GROUPS) {
        const found = group.actions.find((item) => item.action === action);
        if (found) {
            return found.label;
        }
    }

    return action.replaceAll('_', ' ');
}

export function formatDelay(minutes: number | null | undefined): string {
    const value = minutes ?? 0;
    if (value <= 0) {
        return 'immediately';
    }
    if (value % 1440 === 0) {
        const days = value / 1440;

        return `${days} ${days === 1 ? 'day' : 'days'} later`;
    }
    if (value % 60 === 0) {
        const hours = value / 60;

        return `${hours} ${hours === 1 ? 'hour' : 'hours'} later`;
    }

    return `${value} ${value === 1 ? 'minute' : 'minutes'} later`;
}

/** Splits stored minutes into the largest whole unit for editing. */
export function splitDelay(minutes: number | null | undefined): {
    amount: number;
    unit: DelayUnit;
} {
    const value = minutes ?? 0;
    if (value > 0 && value % 1440 === 0) {
        return { amount: value / 1440, unit: 'days' };
    }
    if (value > 0 && value % 60 === 0) {
        return { amount: value / 60, unit: 'hours' };
    }

    return { amount: value, unit: 'minutes' };
}

export function joinDelay(amount: number, unit: DelayUnit): number {
    const safe = Number.isFinite(amount) && amount > 0 ? Math.floor(amount) : 0;

    return Math.min(
        525600,
        safe * (unit === 'days' ? 1440 : unit === 'hours' ? 60 : 1),
    );
}

export function ruleTarget(
    rule: Rule,
    stageName: (id: number | null | undefined) => string,
): string {
    switch (rule.action) {
        case 'notify_assignee':
            return 'Responsible person';
        case 'notify_managers':
            return 'Supervisors';
        case 'create_follow_up':
            return `${rule.activity_type ?? 'task'}${rule.due_days ? ` · due in ${rule.due_days} d` : ' · due today'}`;
        case 'change_stage':
            return `To ${stageName(rule.target_stage_id)}`;
        default:
            return '';
    }
}

export type Lanes = {
    triggers: Rule[];
    byStage: Map<number, Rule[]>;
};

/**
 * "Lead created" rules and rules for any stage go in the triggers row; stage-entry rules
 * sit in their stage lane. Disabled rules stay visible.
 */
export function groupRules(
    rules: Rule[],
    pipelineId: number,
    query = '',
): Lanes {
    const needle = query.trim().toLowerCase();
    const lanes: Lanes = { triggers: [], byStage: new Map() };
    for (const rule of rules) {
        if (rule.pipeline_id !== pipelineId) {
            continue;
        }
        if (
            needle &&
            !`${rule.name} ${actionLabel(rule.action)}`
                .toLowerCase()
                .includes(needle)
        ) {
            continue;
        }
        if (rule.trigger === 'lead_created' || rule.stage_id === null) {
            lanes.triggers.push(rule);
        } else {
            lanes.byStage.set(rule.stage_id, [
                ...(lanes.byStage.get(rule.stage_id) ?? []),
                rule,
            ]);
        }
    }

    return lanes;
}

export function outcomeTone(outcome: string): 'ok' | 'bad' | 'neutral' {
    if (outcome === 'failed' || outcome === 'blocked') {
        return 'bad';
    }

    return outcome === 'completed' ? 'ok' : 'neutral';
}

export function outcomeLabel(outcome: string): string {
    return outcome.replaceAll('_', ' ');
}
