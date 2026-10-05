export type DayWindow = { on: boolean; start: string; end: string };
export type Week = Record<number, DayWindow>;
export type ServerCalendar = {
    working_days: Record<string, { start: string; end: string }>;
    holidays: string[];
    timezone: string;
} | null;

/** ISO weekdays, Monday first (the backend keys working days 1–7 this way). */
export const WEEKDAYS = [
    { iso: 1, label: 'Monday' },
    { iso: 2, label: 'Tuesday' },
    { iso: 3, label: 'Wednesday' },
    { iso: 4, label: 'Thursday' },
    { iso: 5, label: 'Friday' },
    { iso: 6, label: 'Saturday' },
    { iso: 7, label: 'Sunday' },
];

/** Monday–Friday 09:00–18:00 until someone saves a calendar. */
export function defaultWeek(): Week {
    return Object.fromEntries(
        WEEKDAYS.map(({ iso }) => [
            iso,
            { on: iso <= 5, start: '09:00', end: '18:00' },
        ]),
    );
}

export function weekFrom(calendar: ServerCalendar): Week {
    if (!calendar) {
        return defaultWeek();
    }
    const week = Object.fromEntries(
        WEEKDAYS.map(({ iso }) => {
            const window = calendar.working_days[String(iso)];

            return [
                iso,
                window
                    ? { on: true, start: window.start, end: window.end }
                    : { on: false, start: '09:00', end: '18:00' },
            ];
        }),
    );

    return week;
}

/** Error text per weekday where the window is on but does not end after it starts. */
export function weekErrors(week: Week): Record<number, string> {
    const errors: Record<number, string> = {};
    for (const { iso } of WEEKDAYS) {
        const day = week[iso];
        if (day.on && day.start >= day.end) {
            errors[iso] = 'End time must be after the start time.';
        }
    }
    if (!WEEKDAYS.some(({ iso }) => week[iso].on)) {
        errors[0] = 'Select at least one working day.';
    }

    return errors;
}

export function workingHoursPerWeek(week: Week): number {
    let minutes = 0;
    for (const { iso } of WEEKDAYS) {
        const day = week[iso];
        if (day.on && day.start < day.end) {
            const toMinutes = (time: string): number =>
                Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));
            minutes += toMinutes(day.end) - toMinutes(day.start);
        }
    }

    return Math.round((minutes / 60) * 10) / 10;
}

export function calendarPayload(
    week: Week,
    holidays: string[],
): {
    working_days: Record<number, { start: string; end: string }>;
    holidays: string[];
} {
    return {
        working_days: Object.fromEntries(
            WEEKDAYS.filter(({ iso }) => week[iso].on).map(({ iso }) => [
                iso,
                { start: week[iso].start, end: week[iso].end },
            ]),
        ),
        holidays: [...new Set(holidays)].sort(),
    };
}

export function addHoliday(holidays: string[], date: string): string[] {
    return /^\d{4}-\d{2}-\d{2}$/.test(date) && !holidays.includes(date)
        ? [...holidays, date].sort()
        : holidays;
}
