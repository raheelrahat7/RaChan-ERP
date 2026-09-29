import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    RELATED_TYPES,
    appointmentTimesValid,
    conversionRate,
    isTaskOverdue,
    priceRangeValid,
    priorityTone,
    relatedRecordHref,
    relatedRecordIcon,
} from '../../resources/js/lib/sales-crm-tools.ts';

await test('every related type has a label, an icon and an href', () => {
    for (const { value } of RELATED_TYPES) {
        assert.ok(relatedRecordIcon(value), value);
        assert.ok(relatedRecordHref(value).startsWith('/'), value);
    }
    assert.equal(
        RELATED_TYPES.map((r) => r.value).length,
        new Set(RELATED_TYPES.map((r) => r.value)).size,
    );
});

await test('a null related type still resolves to a neutral icon, never throws', () => {
    assert.ok(relatedRecordIcon(null));
});

await test('priority tone escalates from muted to destructive', () => {
    assert.equal(priorityTone('low'), priorityTone('normal'));
    assert.notEqual(priorityTone('normal'), priorityTone('high'));
    assert.notEqual(priorityTone('high'), priorityTone('urgent'));
});

await test('a task is overdue only while open and past its due date', () => {
    const now = new Date('2026-09-29T12:00:00Z');
    assert.equal(
        isTaskOverdue({ status: 'open', due_at: '2026-09-28T00:00:00Z' }, now),
        true,
    );
    assert.equal(
        isTaskOverdue({ status: 'open', due_at: '2026-09-30T00:00:00Z' }, now),
        false,
    );
    assert.equal(isTaskOverdue({ status: 'open', due_at: null }, now), false);
    assert.equal(
        isTaskOverdue(
            { status: 'completed', due_at: '2026-09-28T00:00:00Z' },
            now,
        ),
        false,
    );
});

await test('an appointment must end strictly after it starts', () => {
    assert.equal(
        appointmentTimesValid('2026-09-29T09:00', '2026-09-29T10:00'),
        true,
    );
    assert.equal(
        appointmentTimesValid('2026-09-29T09:00', '2026-09-29T09:00'),
        false,
    );
    assert.equal(
        appointmentTimesValid('2026-09-29T09:00', '2026-09-29T08:00'),
        false,
    );
    assert.equal(
        appointmentTimesValid('not a date', '2026-09-29T10:00'),
        false,
    );
});

await test('a price range with either bound empty is always valid; max must be at least min', () => {
    assert.equal(priceRangeValid(null, null), true);
    assert.equal(priceRangeValid('', '500000'), true);
    assert.equal(priceRangeValid('500000', ''), true);
    assert.equal(priceRangeValid('500000', '400000'), false);
    assert.equal(priceRangeValid('400000', '500000'), true);
    assert.equal(priceRangeValid('400000', '400000'), true);
});

await test('conversion rate is null with no leads, never a divide-by-zero', () => {
    assert.equal(conversionRate(0, 0), null);
    assert.equal(conversionRate(3, 12), 25);
    assert.equal(conversionRate(1, 3), 33.3);
});
