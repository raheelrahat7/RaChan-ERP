export type StatusTone = 'success' | 'info' | 'warning' | 'danger' | 'neutral';

export const STATUS_TONES: Record<string, StatusTone> = {
    paid: 'success',
    posted: 'success',
    completed: 'success',
    approved: 'success',
    active: 'success',
    won: 'success',
    converted: 'success',
    received: 'success',
    deposited: 'success',
    reconciled: 'success',
    matched: 'success',
    signed: 'success',
    resolved: 'success',
    filed: 'success',
    cleared: 'success',
    available: 'success',
    sold: 'success',
    in_progress: 'info',
    partially_paid: 'info',
    submitted: 'info',
    scheduled: 'info',
    issued: 'info',
    sent: 'info',
    open: 'info',
    planned: 'info',
    prepared: 'info',
    local_prepared: 'info',
    registered: 'info',
    ready: 'info',
    reserved: 'info',
    occupied: 'info',
    new: 'info',
    pending: 'warning',
    on_hold: 'warning',
    attention: 'warning',
    unmatched: 'warning',
    queued: 'warning',
    overdue: 'danger',
    failed: 'danger',
    rejected: 'danger',
    bounced: 'danger',
    lost: 'danger',
    expired: 'danger',
    breached: 'danger',
    terminated: 'danger',
    in_arrears: 'danger',
    draft: 'neutral',
    closed: 'neutral',
    cancelled: 'neutral',
    void: 'neutral',
    voided: 'neutral',
    inactive: 'neutral',
    archived: 'neutral',
    vacant: 'neutral',
};

export const STATUS_TONE_CLASSES: Record<StatusTone, string> = {
    success: 'bg-success',
    info: 'bg-info',
    warning: 'bg-warning',
    danger: 'bg-destructive',
    neutral: 'bg-faint',
};

export const STRUCK_TEXT_CLASS = 'text-muted-foreground line-through';

const STRUCK = new Set(['void', 'voided']);

export function normalizeStatus(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');
}

export function statusTone(value: string | null | undefined): StatusTone {
    if (!value) {
        return 'neutral';
    }

    return STATUS_TONES[normalizeStatus(value)] ?? 'neutral';
}

export function statusLabel(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const words = normalizeStatus(value).replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function isStruck(value: string | null | undefined): boolean {
    return value ? STRUCK.has(normalizeStatus(value)) : false;
}
