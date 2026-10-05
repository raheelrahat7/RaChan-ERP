export type FollowUp = {
    id: number;
    type: string;
    notes: string | null;
    due_at: string | null;
    is_overdue: boolean;
    lead: { id: number; first_name: string; last_name: string } | null;
};
export type BucketKey =
    | 'overdue'
    | 'today'
    | 'this_week'
    | 'next_week'
    | 'idle'
    | 'later';

export const BUCKET_ORDER: BucketKey[] = [
    'overdue',
    'today',
    'this_week',
    'next_week',
    'idle',
    'later',
];

function startOfDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

/** Monday-based start of the week containing `date`. */
export function startOfWeek(date: Date): Date {
    const day = startOfDay(date);
    const offset = (day.getDay() + 6) % 7;
    day.setDate(day.getDate() - offset);

    return day;
}

export function bucketFor(followUp: FollowUp, now: Date): BucketKey | null {
    if (!followUp.due_at) {
        return null;
    }
    const due = new Date(followUp.due_at);
    if (Number.isNaN(due.getTime())) {
        return null;
    }
    if (followUp.is_overdue) {
        return 'overdue';
    }
    const today = startOfDay(now);
    const dueDay = startOfDay(due);
    if (dueDay < today) {
        return 'overdue';
    }
    if (dueDay.getTime() === today.getTime()) {
        return 'today';
    }
    const thisWeek = startOfWeek(now);
    const nextWeek = new Date(thisWeek);
    nextWeek.setDate(nextWeek.getDate() + 7);
    const afterNext = new Date(thisWeek);
    afterNext.setDate(afterNext.getDate() + 14);
    if (dueDay < nextWeek) {
        return 'this_week';
    }

    return dueDay < afterNext ? 'next_week' : 'later';
}

export type ActivityColumns = Record<
    BucketKey,
    { followUp?: FollowUp; lead?: { id: number; name: string } }[]
>;

/** Groups open follow-ups by due date; "idle" lists open leads with none planned. */
export function activityColumns(
    followUps: FollowUp[],
    leads: {
        id: number;
        first_name: string;
        last_name: string;
        converted: boolean;
    }[],
    now: Date,
): ActivityColumns {
    const columns: ActivityColumns = {
        overdue: [],
        today: [],
        this_week: [],
        next_week: [],
        idle: [],
        later: [],
    };
    const planned = new Set<number>();
    for (const followUp of followUps) {
        const key = bucketFor(followUp, now);
        if (key) {
            columns[key].push({ followUp });
            if (followUp.lead) {
                planned.add(followUp.lead.id);
            }
        }
    }
    for (const lead of leads) {
        if (!lead.converted && !planned.has(lead.id)) {
            columns.idle.push({
                lead: {
                    id: lead.id,
                    name: `${lead.first_name} ${lead.last_name}`,
                },
            });
        }
    }

    return columns;
}

/** Six-row (or fewer) Monday-first grid of dates covering the month. */
export function calendarWeeks(year: number, month: number): Date[][] {
    const first = new Date(year, month, 1);
    const last = new Date(year, month + 1, 0);
    const cursor = startOfWeek(first);
    const weeks: Date[][] = [];
    while (cursor <= last) {
        const week: Date[] = [];
        for (let i = 0; i < 7; i++) {
            week.push(new Date(cursor));
            cursor.setDate(cursor.getDate() + 1);
        }
        weeks.push(week);
    }

    return weeks;
}

export function dayKey(date: Date): string {
    const pad = (n: number): string => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function followUpsByDay(followUps: FollowUp[]): Map<string, FollowUp[]> {
    const map = new Map<string, FollowUp[]>();
    for (const followUp of followUps) {
        if (!followUp.due_at) {
            continue;
        }
        const due = new Date(followUp.due_at);
        if (Number.isNaN(due.getTime())) {
            continue;
        }
        const key = dayKey(due);
        map.set(key, [...(map.get(key) ?? []), followUp]);
    }

    return map;
}
