import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    daysUntil,
    detailBody,
    detailForm,
    hasChanges,
    leaseQuery,
    moneyText,
    renewBody,
    renewError,
    renewForm,
    renewalText,
} from '../../resources/js/lib/leases.ts';

const lease = {
    id: 7,
    version: 2,
    ends_on: '2026-12-31',
    rent_amount: '60000.00',
    tenancy_number: 'TN-1',
    renewal_due_on: null,
    advance_amount: null,
};

await test('detail body is partial and versioned; blanks clear', () => {
    const form = detailForm(lease);
    assert.deepEqual(detailBody(lease, form), { expected_version: 2 });
    form.tenancy_number = '';
    form.advance_amount = 25000;
    form.renewal_due_on = '2026-11-01';
    assert.deepEqual(detailBody(lease, form), {
        expected_version: 2,
        tenancy_number: null,
        renewal_due_on: '2026-11-01',
        advance_amount: '25000',
    });
    assert.equal(hasChanges({ expected_version: 2 }), false);
});

await test('renewal needs a later end date and only sends changed rent', () => {
    const form = renewForm(lease);
    assert.equal(renewError(lease, form), true);
    form.ends_on = '2026-12-31';
    assert.equal(renewError(lease, form), true);
    form.ends_on = '2027-12-31';
    assert.equal(renewError(lease, form), false);
    assert.deepEqual(renewBody(lease, form), {
        expected_version: 2,
        ends_on: '2027-12-31',
    });
    form.rent_amount = 65000;
    form.renewal_due_on = '2027-10-31';
    assert.deepEqual(renewBody(lease, form), {
        expected_version: 2,
        ends_on: '2027-12-31',
        rent_amount: '65000',
        renewal_due_on: '2027-10-31',
    });
});

await test('queries and day counts', () => {
    assert.equal(leaseQuery('', ''), '');
    assert.equal(
        leaseQuery(' TN ', 'renewal_due', 2),
        'q=TN&tab=renewal_due&page=2',
    );
    const now = Date.parse('2026-10-10T15:00:00Z');
    assert.equal(daysUntil('2026-10-20', now), 10);
    assert.equal(daysUntil('2026-10-09', now), -1);
    assert.equal(daysUntil(null, now), null);
    assert.equal(
        renewalText({ renewal_due_on: null, ends_on: '2026-10-20' }, now),
        '10d',
    );
    assert.equal(
        renewalText(
            { renewal_due_on: '2026-10-05', ends_on: '2026-12-31' },
            now,
        ),
        '5d overdue',
    );
    assert.equal(moneyText(null), '—');
    assert.equal(moneyText('5.00', 'USD'), 'USD 5.00');
});
