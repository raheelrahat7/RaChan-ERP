export type FollowUpKind = 'activity' | 'task' | 'meeting';
export type FollowUpGroup = 'overdue' | 'upcoming' | 'undated' | 'done';
export type FollowUp = {
    key: string;
    kind: FollowUpKind;
    title: string;
    at: string | null;
    who: string | null;
    done: boolean;
    cancelled: boolean;
    group: FollowUpGroup;
};
type ActivityIn = {
    id: number;
    type: string;
    notes: string | null;
    due_at: string | null;
    completed_at: string | null;
    creator?: { name: string } | null;
};
type TaskIn = {
    id: number;
    title: string;
    status: string;
    due_at: string | null;
    assignee_name: string | null;
};
type MeetingIn = {
    id: number;
    title: string;
    type: string;
    status: string;
    starts_at: string | null;
    assignee_name: string | null;
};

/** Server timestamps are UTC, with or without a zone marker. */
export function stamp(value: string | null): number | null {
    if (!value) {
        return null;
    }
    const iso = /[zZ]|[+-]\d\d:?\d\d$/.test(value)
        ? value
        : `${value.replace(' ', 'T')}Z`;
    const time = new Date(iso).getTime();

    return Number.isNaN(time) ? null : time;
}

function place(done: boolean, at: string | null, now: number): FollowUpGroup {
    if (done) {
        return 'done';
    }
    const time = stamp(at);

    return time === null ? 'undated' : time < now ? 'overdue' : 'upcoming';
}

/**
 * One list of everything still to do for a lead: activities with a due date,
 * tasks and meetings. Finished or cancelled items drop to "done".
 */
export function followUps(
    sources: {
        activities: ActivityIn[];
        tasks: TaskIn[];
        meetings: MeetingIn[];
    },
    now: number,
): FollowUp[] {
    const items: FollowUp[] = [
        ...sources.activities
            .filter(
                (item) => item.due_at !== null || item.completed_at !== null,
            )
            .map((item) => {
                const done = item.completed_at !== null;

                return {
                    key: `a${item.id}`,
                    kind: 'activity' as const,
                    title: item.notes
                        ? `${item.type}: ${item.notes}`
                        : item.type,
                    at: item.due_at,
                    who: item.creator?.name ?? null,
                    done,
                    cancelled: false,
                    group: place(done, item.due_at, now),
                };
            }),
        ...sources.tasks.map((item) => {
            const done = item.status === 'completed';

            return {
                key: `t${item.id}`,
                kind: 'task' as const,
                title: item.title,
                at: item.due_at,
                who: item.assignee_name,
                done,
                cancelled: false,
                group: place(done, item.due_at, now),
            };
        }),
        ...sources.meetings.map((item) => {
            const cancelled = item.status === 'cancelled';
            const done = item.status === 'completed' || cancelled;

            return {
                key: `m${item.id}`,
                kind: 'meeting' as const,
                title: item.title,
                at: item.starts_at,
                who: item.assignee_name,
                done,
                cancelled,
                group: place(done, item.starts_at, now),
            };
        }),
    ];
    const order: Record<FollowUpGroup, number> = {
        overdue: 0,
        upcoming: 1,
        undated: 2,
        done: 3,
    };

    return items.sort((a, b) => {
        if (a.group !== b.group) {
            return order[a.group] - order[b.group];
        }
        const left = stamp(a.at) ?? 0;
        const right = stamp(b.at) ?? 0;

        // Oldest first for what is late, soonest first for what is coming, newest first for history.
        return a.group === 'done' ? right - left : left - right;
    });
}

export function groupItems(
    items: FollowUp[],
    group: FollowUpGroup,
): FollowUp[] {
    return items.filter((item) => item.group === group);
}
