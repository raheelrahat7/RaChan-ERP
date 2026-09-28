import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    STATUS_TONES,
    STRUCK_TEXT_CLASS,
    displayStatusLabel,
    isStruck,
    statusLabel,
    statusTone,
} from '../../resources/js/lib/status-tones.ts';

const arabic = JSON.parse(
    readFileSync(
        new URL('../../resources/js/locales/ar.json', import.meta.url),
        'utf8',
    ),
);

await test('known statuses map to a tone regardless of case, spaces or dashes', () => {
    assert.equal(statusTone('paid'), 'success');
    assert.equal(statusTone('Partially paid'), 'info');
    assert.equal(statusTone('ON-HOLD'), 'warning');
    assert.equal(statusTone('overdue'), 'danger');
    assert.equal(statusTone('draft'), 'neutral');
});

await test('unknown, empty and null statuses fall back to neutral with a readable label', () => {
    assert.equal(statusTone('brand_new_backend_state'), 'neutral');
    assert.equal(
        statusLabel('brand_new_backend_state'),
        'Brand new backend state',
    );
    assert.equal(statusTone(null), 'neutral');
    assert.equal(statusTone(undefined), 'neutral');
    assert.equal(statusLabel(null), '');
    assert.equal(statusLabel(''), '');
});

await test('void statuses are struck through and others are not', () => {
    assert.equal(isStruck('void'), true);
    assert.equal(isStruck('Voided'), true);
    assert.equal(isStruck('cancelled'), false);
    assert.equal(isStruck(null), false);
});

await test('every mapped status has an Arabic label', () => {
    const missing = Object.keys(STATUS_TONES)
        .map((status) => statusLabel(status))
        .filter((label) => !arabic[label]);
    assert.deepEqual(missing, []);
});

await test('struck statuses stay readable: muted text, never the decorative faint colour', () => {
    assert.equal(STRUCK_TEXT_CLASS, 'text-muted-foreground line-through');
});

await test('a missing status still shows readable text', () => {
    assert.equal(displayStatusLabel(null), '—');
    assert.equal(displayStatusLabel(''), '—');
    assert.equal(displayStatusLabel('in_progress'), 'In progress');
});
