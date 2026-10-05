import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    computeStageTotals,
    groupDealsByStage,
    kindLabel,
    searchDeals,
    sortedStages,
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
];
const deal = (id, stage_id, amount, extra = {}) => ({
    id,
    title: `Deal ${id}`,
    amount,
    currency: 'AED',
    kind: 'off_plan',
    pipeline_id: 1,
    stage_id,
    contact: { id, name: `Buyer ${id}` },
    assignee: { id: 9, name: 'Sana Nadeem' },
    lead_id: null,
    created_at: '2026-10-01',
    next_activity_at: null,
    ...extra,
});
const deals = [
    deal(1, 1, 100),
    deal(2, 1, 250.5),
    deal(3, 2, null),
    deal(4, 99, 5),
];

await test('stages sort by position and deals group into them', () => {
    assert.deepEqual(
        sortedStages(stages).map((s) => s.id),
        [1, 2, 3],
    );
    const grouped = groupDealsByStage(stages, deals);
    assert.deepEqual(
        grouped.map((g) => g.deals.map((d) => d.id)),
        [[1, 2], [3], []],
    );
});

await test('stage totals count deals and sum priced ones', () => {
    assert.deepEqual(computeStageTotals(stages, deals), [
        { id: 1, count: 2, amount: 350.5 },
        { id: 2, count: 1, amount: 0 },
        { id: 3, count: 0, amount: null },
    ]);
});

await test('search matches title, contact, assignee and type, ignoring case', () => {
    assert.equal(searchDeals(deals, '').length, 4);
    assert.deepEqual(
        searchDeals(deals, 'BUYER 2').map((d) => d.id),
        [2],
    );
    assert.equal(searchDeals(deals, 'sana').length, 4);
    assert.equal(searchDeals(deals, 'off plan').length, 4);
    assert.equal(searchDeals(deals, 'nobody').length, 0);
    assert.equal(kindLabel('resale'), 'Resale');
    assert.equal(kindLabel('mystery_kind'), 'mystery kind');
});
