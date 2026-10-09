import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    followUps,
    groupItems,
    stamp,
} from '../../resources/js/lib/crm-timeline.ts';

const now = Date.parse('2026-10-10T12:00:00Z');

await test('timestamps with and without a zone read as UTC', () => {
    assert.equal(stamp('2026-10-10 12:00:00'), now);
    assert.equal(stamp('2026-10-10T12:00:00Z'), now);
    assert.equal(stamp('nonsense'), null);
    assert.equal(stamp(null), null);
});

await test('items are grouped and ordered by urgency', () => {
    const items = followUps(
        {
            activities: [
                {
                    id: 1,
                    type: 'call',
                    notes: 'Ring back',
                    due_at: '2026-10-09 09:00:00',
                    completed_at: null,
                },
                {
                    id: 2,
                    type: 'note',
                    notes: null,
                    due_at: null,
                    completed_at: null,
                },
                {
                    id: 3,
                    type: 'email',
                    notes: null,
                    due_at: '2026-10-01 09:00:00',
                    completed_at: '2026-10-01 10:00:00',
                    creator: { name: 'Sam' },
                },
            ],
            tasks: [
                {
                    id: 4,
                    title: 'Send brochure',
                    status: 'open',
                    due_at: '2026-10-12 09:00:00',
                    assignee_name: 'Lee',
                },
                {
                    id: 5,
                    title: 'Check docs',
                    status: 'open',
                    due_at: null,
                    assignee_name: null,
                },
                {
                    id: 6,
                    title: 'Old task',
                    status: 'completed',
                    due_at: '2026-09-01 09:00:00',
                    assignee_name: null,
                },
            ],
            meetings: [
                {
                    id: 7,
                    title: 'Viewing',
                    type: 'viewing',
                    status: 'scheduled',
                    starts_at: '2026-10-11 09:00:00',
                    assignee_name: 'Lee',
                },
                {
                    id: 8,
                    title: 'Called off',
                    type: 'meeting',
                    status: 'cancelled',
                    starts_at: '2026-10-20 09:00:00',
                    assignee_name: null,
                },
            ],
        },
        now,
    );
    assert.deepEqual(
        items.map((i) => i.key),
        ['a1', 'm7', 't4', 't5', 'm8', 'a3', 't6'],
    );
    assert.deepEqual(
        groupItems(items, 'overdue').map((i) => i.key),
        ['a1'],
    );
    assert.deepEqual(
        groupItems(items, 'upcoming').map((i) => i.key),
        ['m7', 't4'],
    );
    assert.deepEqual(
        groupItems(items, 'undated').map((i) => i.key),
        ['t5'],
    );
    assert.deepEqual(
        groupItems(items, 'done').map((i) => i.key),
        ['m8', 'a3', 't6'],
    );
    assert.equal(items.find((i) => i.key === 'm8').cancelled, true);
    assert.equal(items.find((i) => i.key === 'a1').title, 'call: Ring back');
    assert.equal(
        items.some((i) => i.key === 'a2'),
        false,
    );
});
