import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    addHoliday,
    calendarPayload,
    defaultWeek,
    weekErrors,
    weekFrom,
    workingHoursPerWeek,
} from '../../resources/js/lib/crm-calendar.ts';

await test('default week is Monday to Friday, 45 hours', () => {
    const week = defaultWeek();
    assert.equal(week[5].on, true);
    assert.equal(week[6].on, false);
    assert.equal(workingHoursPerWeek(week), 45);
});

await test('server calendars map onto weekdays and missing days are off', () => {
    const week = weekFrom({
        working_days: { 1: { start: '08:00', end: '12:30' } },
        holidays: [],
        timezone: 'Asia/Dubai',
    });
    assert.deepEqual(week[1], { on: true, start: '08:00', end: '12:30' });
    assert.equal(week[2].on, false);
    assert.equal(workingHoursPerWeek(week), 4.5);
});

await test('invalid windows and empty weeks are reported', () => {
    const week = defaultWeek();
    week[2].end = '08:00';
    assert.ok(weekErrors(week)[2]);
    for (const iso of [1, 2, 3, 4, 5]) week[iso].on = false;
    assert.ok(weekErrors(week)[0]);
});

await test('payload keeps only working days and sorted unique holidays', () => {
    const payload = calendarPayload(defaultWeek(), [
        '2026-12-02',
        '2026-01-01',
        '2026-12-02',
    ]);
    assert.deepEqual(Object.keys(payload.working_days), [
        '1',
        '2',
        '3',
        '4',
        '5',
    ]);
    assert.deepEqual(payload.holidays, ['2026-01-01', '2026-12-02']);
});

await test('holidays must be valid-looking dates and unique', () => {
    assert.deepEqual(addHoliday(['2026-01-01'], '2026-01-01'), ['2026-01-01']);
    assert.deepEqual(addHoliday([], 'soon'), []);
    assert.deepEqual(addHoliday(['2026-03-01'], '2026-02-01'), [
        '2026-02-01',
        '2026-03-01',
    ]);
});
