import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    brokerageQuery,
    canClawback,
    clawbackBody,
    clawbackError,
    isNegative,
    money,
    remainingCents,
    remainingText,
    teamName,
} from '../../resources/js/lib/commissions.ts';

const commission = {
    commission_amount: '6000.00',
    clawback_total: '500.25',
    currency: 'AED',
    version: 2,
    permissions: { read: true, record_clawback: true },
};

await test('remaining amount is the commission less recorded clawbacks', () => {
    assert.equal(remainingCents(commission), 549975);
    assert.equal(remainingText(commission), '5499.75');
    assert.equal(
        remainingText({ commission_amount: '10.00', clawback_total: '20.00' }),
        '0.00',
    );
    assert.equal(
        remainingCents({ commission_amount: '10.00', clawback_total: null }),
        1000,
    );
});

await test('clawback form is checked before it is sent', () => {
    assert.equal(
        clawbackError(commission, { amount: '', reason: 'x' }),
        'amount',
    );
    assert.equal(
        clawbackError(commission, { amount: '0', reason: 'x' }),
        'amount',
    );
    assert.equal(
        clawbackError(commission, { amount: '1.234', reason: 'x' }),
        'amount',
    );
    assert.equal(
        clawbackError(commission, { amount: 6000, reason: 'x' }),
        'range',
    );
    assert.equal(
        clawbackError(commission, { amount: '100', reason: '  ' }),
        'reason',
    );
    assert.equal(
        clawbackError(commission, { amount: 100.5, reason: 'Refund' }),
        null,
    );
    assert.deepEqual(
        clawbackBody(commission, { amount: 100.5, reason: ' Refund ' }),
        { expected_version: 2, amount: '100.5', reason: 'Refund' },
    );
});

await test('only AED commissions with room left accept a clawback', () => {
    assert.equal(canClawback(commission), true);
    assert.equal(canClawback({ ...commission, currency: 'USD' }), false);
    assert.equal(
        canClawback({ ...commission, clawback_total: '6000.00' }),
        false,
    );
    assert.equal(
        canClawback({
            ...commission,
            permissions: { read: true, record_clawback: false },
        }),
        false,
    );
});

await test('small helpers', () => {
    assert.equal(brokerageQuery('', 1), '');
    assert.equal(brokerageQuery('3', 2), 'team_id=3&page=2');
    assert.equal(money(null), '—');
    assert.equal(money('5.00'), 'AED 5.00');
    assert.equal(teamName(null), 'Unassigned');
    assert.equal(isNegative('-1.00'), true);
    assert.equal(isNegative(null), false);
});
