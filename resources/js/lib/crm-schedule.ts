export type Perms = Record<string, boolean>;
export type LeadTask = {
    id: number;
    title: string;
    description: string | null;
    priority: string;
    status: string;
    assigned_to: number | null;
    assignee_name: string | null;
    due_at: string | null;
    completed_at: string | null;
    version: number;
    permissions: Perms;
};
export type LeadMeeting = {
    id: number;
    type: string;
    title: string;
    assigned_to: number | null;
    assignee_name: string | null;
    listing_id: number | null;
    starts_at: string | null;
    ends_at: string | null;
    location: string | null;
    status: string;
    outcome: string | null;
    version: number;
    permissions: Perms;
};
export type TaskForm = {
    title: string;
    description: string;
    priority: string;
    assigned_to: string | number;
    due_at: string;
};
export type MeetingForm = {
    type: string;
    title: string;
    assigned_to: string | number;
    starts_at: string;
    ends_at: string;
    location: string;
    listing_id: string | number;
};

export const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
export const MEETING_TYPES = ['meeting', 'viewing'];

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/** Server timestamps are UTC ("2027-01-02 12:00:00" or ISO); the form shows browser-local time. */
export function toLocalInput(value: string | null): string {
    if (!value) {
        return '';
    }
    const iso = /[zZ]|[+-]\d\d:?\d\d$/.test(value)
        ? value
        : `${value.replace(' ', 'T')}Z`;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function fromLocalInput(local: string): string | null {
    if (!local.trim()) {
        return null;
    }
    const date = new Date(local);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/** Readable local date/time for lists. */
export function whenText(value: string | null): string {
    const local = toLocalInput(value);

    return local ? new Date(local).toLocaleString() : '—';
}

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value).trim()
        : '';
const idOrNull = (value: string | number): number | null =>
    text(value) === '' ? null : Number(value);

export function taskForm(
    task?: LeadTask,
    defaultAssignee?: number | null,
): TaskForm {
    return {
        title: task?.title ?? '',
        description: task?.description ?? '',
        priority: task?.priority ?? 'normal',
        assigned_to: task?.assigned_to ?? defaultAssignee ?? '',
        due_at: toLocalInput(task?.due_at ?? null),
    };
}

export function taskCreateBody(form: TaskForm): Record<string, unknown> {
    return {
        title: text(form.title),
        description: text(form.description) || null,
        priority: form.priority,
        assigned_to: idOrNull(form.assigned_to),
        due_at: fromLocalInput(form.due_at),
    };
}

export function taskUpdateBody(
    task: LeadTask,
    form: TaskForm,
): Record<string, unknown> {
    const before = taskForm(task);
    const body: Record<string, unknown> = { expected_version: task.version };
    if (text(form.title) !== before.title) body.title = text(form.title);
    if (text(form.description) !== before.description)
        body.description = text(form.description) || null;
    if (form.priority !== before.priority) body.priority = form.priority;
    if (text(form.assigned_to) !== text(before.assigned_to))
        body.assigned_to = idOrNull(form.assigned_to);
    if (form.due_at !== before.due_at)
        body.due_at = fromLocalInput(form.due_at);

    return body;
}

export function meetingForm(
    meeting?: LeadMeeting,
    defaultAssignee?: number | null,
): MeetingForm {
    return {
        type: meeting?.type ?? 'viewing',
        title: meeting?.title ?? '',
        assigned_to: meeting?.assigned_to ?? defaultAssignee ?? '',
        starts_at: toLocalInput(meeting?.starts_at ?? null),
        ends_at: toLocalInput(meeting?.ends_at ?? null),
        location: meeting?.location ?? '',
        listing_id: meeting?.listing_id ?? '',
    };
}

export function meetingCreateBody(form: MeetingForm): Record<string, unknown> {
    return {
        type: form.type,
        title: text(form.title),
        assigned_to: idOrNull(form.assigned_to),
        starts_at: fromLocalInput(form.starts_at),
        ends_at: fromLocalInput(form.ends_at),
        location: text(form.location) || null,
        listing_id: idOrNull(form.listing_id),
    };
}

export function meetingUpdateBody(
    meeting: LeadMeeting,
    form: MeetingForm,
): Record<string, unknown> {
    const before = meetingForm(meeting);
    const body: Record<string, unknown> = { expected_version: meeting.version };
    if (form.type !== before.type) body.type = form.type;
    if (text(form.title) !== before.title) body.title = text(form.title);
    if (text(form.assigned_to) !== text(before.assigned_to))
        body.assigned_to = idOrNull(form.assigned_to);
    if (form.starts_at !== before.starts_at)
        body.starts_at = fromLocalInput(form.starts_at);
    if (form.ends_at !== before.ends_at)
        body.ends_at = fromLocalInput(form.ends_at);
    if (text(form.location) !== before.location)
        body.location = text(form.location) || null;
    if (text(form.listing_id) !== text(before.listing_id))
        body.listing_id = idOrNull(form.listing_id);

    return body;
}

export function hasChanges(body: Record<string, unknown>): boolean {
    return Object.keys(body).some((key) => key !== 'expected_version');
}

/** Completing a meeting may also move the lead; the stage and the stage it was in go together. */
export function completeMeetingBody(
    meeting: LeadMeeting,
    outcome: string,
    stageId: number | null,
    currentStageId: number,
): Record<string, unknown> {
    return {
        expected_version: meeting.version,
        outcome: text(outcome) || null,
        ...(stageId !== null && stageId !== currentStageId
            ? { stage_id: stageId, expected_stage_id: currentStageId }
            : {}),
    };
}

export function endsBeforeStart(starts: string, ends: string): boolean {
    return starts !== '' && ends !== '' && ends <= starts;
}
