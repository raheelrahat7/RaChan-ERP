import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ApiError, apiJson } from '../../resources/js/lib/crm-api.ts';
import {
    amountOf,
    categoryLabel,
    computeStageTotals,
    contactName,
    creatablePipelines,
    groupDealsByStage,
    moveTargets,
    searchDeals,
    sortedStages,
    stageTotalsFromCounts,
} from '../../resources/js/lib/crm-deals.ts';

const stages = [
    {
        id: 2,
        name: 'Follow up',
        type: 'normal',
        color: '#ffd',
        position: 2,
        active: true,
    },
    {
        id: 1,
        name: 'Viewing booked',
        type: 'normal',
        color: '#0af',
        position: 1,
        active: true,
    },
    {
        id: 3,
        name: 'Won',
        type: 'won',
        color: '#8c3',
        position: 3,
        active: true,
    },
    {
        id: 4,
        name: 'Old',
        type: 'normal',
        color: '#999',
        position: 4,
        active: false,
    },
];
const pipeline = {
    id: 1,
    name: 'Off plan',
    active: true,
    stages,
    permissions: { add: ['own'] },
};
const deal = (id, current_stage_id, amount, extra = {}) => ({
    id,
    title: `Deal ${id}`,
    category: 'offplan',
    amount,
    currency: 'AED',
    pipeline_id: 1,
    current_stage_id,
    first_name: 'Buyer',
    last_name: String(id),
    assignee: { id: 9, name: 'Sana Nadeem' },
    lead_id: null,
    version: 1,
    created_at: '2026-10-01',
    ...extra,
});
const deals = [
    deal(1, 1, '100.00'),
    deal(2, 1, '250.50'),
    deal(3, 2, null),
    deal(4, 99, 5),
    deal(5, 1, undefined),
];

await test('stages sort by position and deals group into them', () => {
    assert.deepEqual(
        sortedStages(stages).map((s) => s.id),
        [1, 2, 3, 4],
    );
    assert.deepEqual(
        groupDealsByStage(stages, deals).map((g) => g.deals.map((d) => d.id)),
        [[1, 2, 5], [3], [], []],
    );
});

await test('amounts parse from decimal strings and stay null when hidden', () => {
    assert.equal(amountOf(deal(1, 1, '250.50')), 250.5);
    assert.equal(amountOf(deal(1, 1, null)), null);
    assert.equal(amountOf({}), null);
    assert.equal(amountOf({ amount: 'abc' }), null);
});

await test('server counts map to stages and never guess amounts', () => {
    const counts = [
        { pipeline_id: 1, current_stage_id: 1, total: '3' },
        { pipeline_id: 2, current_stage_id: 1, total: 9 },
        { pipeline_id: 1, current_stage_id: 2, total: 1, amount: '500' },
    ];
    assert.deepEqual(stageTotalsFromCounts(pipeline, counts), [
        { id: 1, count: 3, amount: null },
        { id: 2, count: 1, amount: 500 },
        { id: 3, count: 0, amount: null },
        { id: 4, count: 0, amount: null },
    ]);
    assert.deepEqual(computeStageTotals(stages, deals).slice(0, 3), [
        { id: 1, count: 3, amount: 350.5 },
        { id: 2, count: 1, amount: 0 },
        { id: 3, count: 0, amount: null },
    ]);
});

await test('search, labels and contacts read naturally', () => {
    const options = [{ code: 'offplan', name: 'Off plan', active: true }];
    assert.equal(categoryLabel('offplan', options), 'Off plan');
    assert.equal(categoryLabel('mystery_kind'), 'Mystery kind');
    assert.equal(
        contactName({ first_name: 'A', last_name: 'B', company: 'C' }),
        'A B',
    );
    assert.equal(contactName({ company: 'Acme' }), 'Acme');
    assert.equal(searchDeals(deals, '').length, 5);
    assert.deepEqual(
        searchDeals(deals, 'BUYER 2').map((d) => d.id),
        [2],
    );
    assert.equal(searchDeals(deals, 'off plan', options).length, 5);
    assert.equal(searchDeals(deals, 'nobody').length, 0);
});

await test('only pipelines with add access are creatable, and moves skip inactive and current stages', () => {
    assert.equal(
        creatablePipelines([
            pipeline,
            { ...pipeline, id: 2, permissions: { add: [] } },
            { ...pipeline, id: 3, active: false },
            { ...pipeline, id: 4, permissions: undefined },
        ]).length,
        1,
    );
    assert.deepEqual(
        moveTargets(stages, 1).map((s) => s.id),
        [2, 3],
    );
});

await test('api errors carry status and field messages', async () => {
    const original = globalThis.fetch;
    globalThis.document = { cookie: 'XSRF-TOKEN=abc%3D' };
    globalThis.fetch = async (_path, init) => ({
        ok: false,
        status: 422,
        json: async () => ({
            message: 'Invalid',
            errors: {
                expected_version: ['This deal changed.'],
                title: ['Required.'],
            },
        }),
        _init: init,
    });
    try {
        await apiJson('/x', 'PUT', { a: 1 });
        assert.fail('should throw');
    } catch (error) {
        assert.ok(error instanceof ApiError);
        assert.equal(error.status, 422);
        assert.equal(error.message, 'This deal changed.');
        assert.deepEqual(error.fieldErrors(), {
            expected_version: 'This deal changed.',
            title: 'Required.',
        });
    }
    let seen;
    globalThis.fetch = async (_path, init) => {
        seen = init;
        return { ok: true, status: 200, json: async () => ({ ok: 1 }) };
    };
    assert.deepEqual(await apiJson('/x', 'POST', { b: 2 }), { ok: 1 });
    assert.equal(seen.headers['X-XSRF-TOKEN'], 'abc=');
    assert.equal(seen.body, '{"b":2}');
    globalThis.fetch = original;
});
