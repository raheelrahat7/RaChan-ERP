import {
    CalendarCheck,
    CircleDashed,
    FileKey,
    Handshake,
    UsersRound,
    Warehouse,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';

export type RelatedType =
    | 'lead'
    | 'reservation'
    | 'lease'
    | 'sale'
    | 'unit'
    | 'job';

export const RELATED_TYPES: { value: RelatedType; label: string }[] = [
    { value: 'lead', label: 'Lead' },
    { value: 'reservation', label: 'Reservation' },
    { value: 'lease', label: 'Lease' },
    { value: 'sale', label: 'Sale' },
    { value: 'unit', label: 'Unit' },
    { value: 'job', label: 'Job card' },
];

const RELATED_ICONS: Record<RelatedType, Component> = {
    lead: UsersRound,
    reservation: CalendarCheck,
    lease: FileKey,
    sale: Handshake,
    unit: Warehouse,
    job: Wrench,
};

export function relatedRecordIcon(type: RelatedType | null): Component {
    return type ? RELATED_ICONS[type] : CircleDashed;
}

const RELATED_HREFS: Record<RelatedType, string> = {
    lead: '/crm/leads',
    reservation: '/reservations',
    lease: '/agreements',
    sale: '/agreements',
    unit: '/inventory',
    job: '/maintenance',
};

export function relatedRecordHref(type: RelatedType): string {
    return RELATED_HREFS[type];
}

export type Priority = 'low' | 'normal' | 'high' | 'urgent';

const PRIORITY_TONE: Record<Priority, string> = {
    low: 'text-muted-foreground',
    normal: 'text-muted-foreground',
    high: 'text-warning',
    urgent: 'text-destructive',
};

export function priorityTone(priority: Priority): string {
    return PRIORITY_TONE[priority];
}

export function isTaskOverdue(
    task: { status: string; due_at: string | null },
    now: Date = new Date(),
): boolean {
    return (
        task.status === 'open' &&
        task.due_at !== null &&
        new Date(task.due_at).getTime() < now.getTime()
    );
}

export function appointmentTimesValid(
    startsAt: string,
    endsAt: string,
): boolean {
    const start = new Date(startsAt).getTime();
    const end = new Date(endsAt).getTime();

    return Number.isFinite(start) && Number.isFinite(end) && end > start;
}

export function priceRangeValid(
    min: string | null | undefined,
    max: string | null | undefined,
): boolean {
    if (!min || !max) {
        return true;
    }

    return Number(max) >= Number(min);
}

export function conversionRate(
    converted: number,
    leads: number,
): number | null {
    if (leads <= 0) {
        return null;
    }

    return Math.round((converted / leads) * 1000) / 10;
}
