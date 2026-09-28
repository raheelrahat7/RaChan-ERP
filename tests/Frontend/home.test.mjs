import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    attentionItems,
    greetingKey,
    normalizeHome,
    percentChange,
    summarySentences,
} from '../../resources/js/lib/home.ts';

const legacy = {
    metrics: {
        openMaintenance: 4,
        overdueMaintenance: 1,
        availableUnits: 12,
        reservedUnits: 3,
        activeLeads: 48,
        outstandingAed: 11600,
    },
    alerts: [{ title: 'Overdue maintenance', count: 1, href: '/maintenance' }],
};

const kpis = {
    revenue: { value: 188250, previous: 176900 },
    expenses: { value: 67515, previous: 60000 },
    net_profit: { value: 120735, previous: 116900 },
    cash_balance: { value: 50760, change_7d_pct: -12 },
    receivables: { value: 11600 },
    payables: { value: 52257 },
    vat_payable: { value: 4027 },
    commission_payable: { value: 300400, agents: 18 },
    active_deals: { count: 4 },
    expiring_contracts: { count: 2 },
    pdc_due: { count: 3, amount: 9000 },
    bounced_cheques: { count: 4, amount: 64000 },
    pending_approvals: null,
    overdue_tasks: null,
};

await test("with today's props only, real numbers show and the rest is coming soon", () => {
    const view = normalizeHome(legacy);
    assert.equal(view.hasKpis, false);
    assert.deepEqual(view.figures.receivables, {
        value: 11600,
        change: null,
        count: null,
        soon: false,
    });
    assert.equal(view.figures.revenue.soon, true);
    assert.equal(view.figures.revenue.value, null);
    assert.equal(view.trend, null);
    assert.equal(view.topAgents, null);
    assert.equal(view.alerts.length, 1);
    assert.equal(view.legacy.activeLeads, 48);
});

await test('with the full contract, null KPIs stay coming soon, never zero', () => {
    const view = normalizeHome({ ...legacy, kpis });
    assert.equal(view.hasKpis, true);
    assert.equal(view.figures.revenue.change, 6.4);
    assert.equal(view.figures.cash_balance.change, -12);
    assert.deepEqual(view.figures.bounced_cheques, {
        value: 64000,
        change: null,
        count: 4,
        soon: false,
    });
    assert.equal(view.figures.pending_approvals.soon, true);
    assert.equal(view.figures.overdue_tasks.value, null);
});

await test('percentage change never divides by zero', () => {
    assert.equal(percentChange(100, 0), null);
    assert.equal(percentChange(100, null), null);
    assert.equal(percentChange(50, 100), -50);
    assert.equal(percentChange(-50, -100), 50);
});

await test('the summary sentence reports direction and what needs attention', () => {
    const up = summarySentences(normalizeHome({ ...legacy, kpis }));
    assert.deepEqual(up[0], {
        key: 'Revenue is up :change on the previous period.',
        params: { change: '6.4%' },
    });
    assert.deepEqual(up[1], {
        key: ':count cheques bounced this week.',
        params: { count: 4 },
        emphasis: true,
    });
    const flat = summarySentences(
        normalizeHome({
            kpis: {
                ...kpis,
                revenue: { value: 100, previous: 0 },
                bounced_cheques: { count: 0, amount: 0 },
            },
        }),
    );
    assert.deepEqual(
        flat.map((sentence) => sentence.key),
        ['Nothing needs your attention right now.'],
    );
    const today = summarySentences(normalizeHome(legacy));
    assert.deepEqual(
        today.map((sentence) => sentence.key),
        ['Here is where things stand today.', '1 item needs your attention.'],
    );
    const one = summarySentences(
        normalizeHome({
            kpis: { ...kpis, bounced_cheques: { count: 1, amount: 50 } },
        }),
    );
    assert.equal(one[1].key, '1 cheque bounced this week.');
});

await test('attention lists urgent money first, then expiries, then backend alerts', () => {
    const items = attentionItems(normalizeHome({ ...legacy, kpis }));
    assert.deepEqual(
        items.map((item) => [item.tag, item.tone]),
        [
            ['PDC', 'danger'],
            ['PDC', 'warning'],
            ['Lease', 'warning'],
            ['Alert', 'info'],
        ],
    );
    assert.equal(items[0].amount, 64000);
    assert.equal(items[3].count, 1);
    assert.deepEqual(attentionItems(normalizeHome({})), []);
});

await test('the greeting follows the time of day', () => {
    assert.equal(greetingKey(6), 'Good morning, :name.');
    assert.equal(greetingKey(13), 'Good afternoon, :name.');
    assert.equal(greetingKey(19), 'Good evening, :name.');
    assert.equal(greetingKey(2), 'Good evening, :name.');
});
