import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    activityColumns,
    activityDateInput,
    zonedDayKey,
    bucketFor,
    calendarWeeks,
    dayKey,
    followUpsByDay,
} from '../../resources/js/lib/crm-activity-views.ts';

// Wednesday 14 October 2026, local time.
const now = new Date(2026, 9, 14, 10, 0);
const make = (id, due, overdue = false, lead = id) => ({
    id,
    type: 'call',
    notes: null,
    due_at: due?.toISOString() ?? null,
    is_overdue: overdue,
    lead: { id: lead, first_name: 'L', last_name: String(lead) },
});

await test('follow-ups land in the right due bucket', () => {
    assert.equal(
        bucketFor(make(1, new Date(2026, 9, 12, 9), true), now),
        'overdue',
    );
    assert.equal(bucketFor(make(2, new Date(2026, 9, 14, 18)), now), 'today');
    assert.equal(
        bucketFor(make(3, new Date(2026, 9, 16, 9)), now),
        'this_week',
    );
    assert.equal(
        bucketFor(make(4, new Date(2026, 9, 19, 9)), now),
        'next_week',
    );
    assert.equal(bucketFor(make(5, new Date(2026, 9, 26, 9)), now), 'later');
    assert.equal(bucketFor(make(6, null), now), null);
});

await test('idle lists open leads without a planned follow-up', () => {
    const leads = [
        { id: 1, first_name: 'A', last_name: 'B', converted: false },
        { id: 2, first_name: 'C', last_name: 'D', converted: false },
        { id: 3, first_name: 'E', last_name: 'F', converted: true },
    ];
    const columns = activityColumns(
        [make(9, new Date(2026, 9, 14, 12), false, 1)],
        leads,
        now,
    );
    assert.equal(columns.today.length, 1);
    assert.deepEqual(
        columns.idle.map((item) => item.lead.id),
        [2],
    );
});

await test('calendar grid is Monday-first and covers the whole month', () => {
    const weeks = calendarWeeks(2026, 9);
    assert.equal(weeks[0][0].getDay(), 1);
    assert.ok(weeks.every((week) => week.length === 7));
    const keys = weeks.flat().map(dayKey);
    assert.ok(keys.includes('2026-10-01') && keys.includes('2026-10-31'));
});

await test('follow-ups group by local day', () => {
    const map = followUpsByDay([
        make(1, new Date(2026, 9, 14, 8)),
        make(2, new Date(2026, 9, 14, 20)),
        make(3, null),
    ]);
    assert.equal(map.get('2026-10-14').length, 2);
    assert.equal(map.size, 1);
});

await test('calendar and activity editor use organization dates across midnight and daylight saving', () => {
    const due = new Date('2026-10-05T23:30:00Z');
    assert.equal(zonedDayKey(due, 'Asia/Karachi'), '2026-10-06');
    const grouped = followUpsByDay([make(91, due)], 'Asia/Karachi');
    assert.equal(grouped.get('2026-10-06')[0].id, 91);
    assert.equal(grouped.has('2026-10-05'), false);
    assert.equal(
        activityDateInput(due.toISOString(), 'Asia/Karachi'),
        '2026-10-06T04:30:00',
    );
    assert.equal(
        activityDateInput('2026-03-08T07:30:00Z', 'America/New_York'),
        '2026-03-08T03:30:00',
    );
    assert.equal(activityDateInput(null, 'Asia/Karachi'), '');
    assert.equal(activityDateInput('invalid', 'Asia/Karachi'), '');
});
