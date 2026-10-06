import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    activeStages,
    canCreateKind,
    estimateTotal,
    groupByStage,
    moveTargets,
    needsReason,
} from '../../resources/js/lib/workflows.ts';

const stages = [
    {
        id: 2,
        name: 'Won',
        type: 'success',
        color: '#000000',
        position: 2,
        active: true,
        is_initial: false,
    },
    {
        id: 1,
        name: 'New',
        type: 'normal',
        color: '#000000',
        position: 1,
        active: true,
        is_initial: true,
    },
    {
        id: 3,
        name: 'Old',
        type: 'failure',
        color: '#000000',
        position: 3,
        active: false,
        is_initial: false,
    },
];

await test('stages are ordered and archived ones hidden', () => {
    assert.deepEqual(
        activeStages({ stages }).map((s) => s.id),
        [1, 2],
    );
});

await test('records group by stage, ignoring unknown stages', () => {
    const groups = groupByStage(activeStages({ stages }), [
        { id: 1, stage_id: 1 },
        { id: 2, stage_id: 1 },
        { id: 3, stage_id: 9 },
    ]);
    assert.equal(groups.get(1).length, 2);
    assert.equal(groups.get(2).length, 0);
});

await test('move targets exclude the current and archived stages', () => {
    assert.deepEqual(
        moveTargets(stages, 1).map((s) => s.id),
        [2],
    );
});

await test('failure or reopening needs a reason', () => {
    assert.equal(needsReason({ closed_at: null }, { type: 'failure' }), true);
    assert.equal(
        needsReason({ closed_at: '2026-01-01' }, { type: 'normal' }),
        true,
    );
    assert.equal(needsReason({ closed_at: null }, { type: 'success' }), false);
});

await test('estimate totals match the server rounding', () => {
    assert.equal(
        estimateTotal([
            { description: 'a', quantity: '2', unit_price: '10.50' },
            { description: 'b', quantity: '1.5', unit_price: '3.33' },
        ]),
        '26.00',
    );
    assert.equal(estimateTotal([]), '0.00');
});

await test('only estimates and recruitment are created here', () => {
    assert.equal(canCreateKind('estimate'), true);
    assert.equal(canCreateKind('invoice'), false);
});

import {
    requirableFields,
    stageRulesFrom,
    stageRulesPayload,
    toggleRule,
} from '../../resources/js/lib/workflows.ts';

await test('toggling a rule returns null when nothing is left', () => {
    assert.deepEqual(toggleRule(null, 'a'), ['a']);
    assert.equal(toggleRule(['a'], 'a'), null);
    assert.deepEqual(toggleRule([1, 2], 3), [1, 2, 3]);
});

await test('requirable fields combine kind fields with custom keys', () => {
    const all = ['name', 'email', 'lines', 'currency', 'notes', 'valid_until'];
    const fields = requirableFields('estimate', all, ['site_visit']);
    assert.deepEqual(
        fields.map((f) => f.value),
        ['valid_until', 'lines', 'currency', 'notes', 'custom:site_visit'],
    );
});

await test('stage rule payload sends nulls for open restrictions', () => {
    const rules = stageRulesFrom({
        entry_roles: ['owner'],
        required_fields: [],
    });
    assert.deepEqual(stageRulesPayload(rules, 'estimate'), {
        is_initial: false,
        allowed_from_stage_ids: null,
        entry_roles: ['owner'],
        required_fields: [],
        source_statuses: null,
    });
    assert.deepEqual(
        stageRulesPayload({ ...rules, source_statuses: ['paid'] }, 'invoice')
            .source_statuses,
        ['paid'],
    );
});

import { operationKey } from '../../resources/js/lib/workflows.ts';

await test('operation keys are UUIDs, also without crypto.randomUUID', () => {
    const uuid =
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    assert.match(operationKey(), uuid);
    const descriptor =
        Object.getOwnPropertyDescriptor(
            Object.getPrototypeOf(crypto),
            'randomUUID',
        ) ?? Object.getOwnPropertyDescriptor(crypto, 'randomUUID');
    Object.defineProperty(crypto, 'randomUUID', {
        value: undefined,
        configurable: true,
    });
    try {
        assert.match(operationKey(), uuid);
    } finally {
        delete crypto.randomUUID;
        if (descriptor && !('randomUUID' in crypto)) {
            Object.defineProperty(crypto, 'randomUUID', descriptor);
        }
    }
});
