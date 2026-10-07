import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    amountNeedsCurrency,
    commercialForm,
    createBody,
    hasChanges,
    money,
    statusLabel,
    statusOptions,
    totalsByCurrency,
    updateBody,
} from '../../resources/js/lib/crm-commercial.ts';

const record = {
    id: 7,
    lead_id: 2,
    kind: 'offer',
    reference: 'OFF-12',
    title: 'Unit 12 offer',
    party_name: 'Buyer',
    status: 'submitted',
    amount: '1500000.00',
    currency: 'AED',
    submitted_on: '2026-10-08',
    signed_on: null,
    notes: null,
    deal_id: 5,
    version: 3,
    permissions: { view: true, edit: true },
};

await test('create body trims, uppercases the currency and nulls blanks', () => {
    const form = {
        ...commercialForm('contract'),
        title: ' Lease ',
        amount: '100',
        currency: 'aed',
    };
    assert.deepEqual(createBody(form), {
        kind: 'contract',
        title: 'Lease',
        reference: null,
        party_name: null,
        status: 'draft',
        amount: '100',
        currency: 'AED',
        submitted_on: null,
        signed_on: null,
        notes: null,
        deal_id: null,
    });
});

await test('amount and currency must travel together', () => {
    assert.equal(amountNeedsCurrency({ amount: '10', currency: '' }), true);
    assert.equal(amountNeedsCurrency({ amount: '', currency: 'AED' }), true);
    assert.equal(amountNeedsCurrency({ amount: '', currency: '' }), false);
    assert.equal(amountNeedsCurrency({ amount: 10, currency: 'AED' }), false);
});

await test('update body is partial, versioned and never sends the kind', () => {
    const form = commercialForm('offer', record);
    assert.deepEqual(updateBody(record, form), { expected_version: 3 });
    assert.equal(hasChanges(updateBody(record, form)), false);
    form.status = 'accepted';
    form.notes = 'Agreed by phone';
    form.deal_id = '';
    assert.deepEqual(updateBody(record, form), {
        expected_version: 3,
        status: 'accepted',
        notes: 'Agreed by phone',
        deal_id: null,
    });
});

await test('archived statuses stay editable and labels fall back to the code', () => {
    const choices = [
        { value: 'draft', label: 'Draft', active: true },
        { value: 'old_sent', label: 'Old sent', active: false },
    ];
    assert.deepEqual(
        statusOptions(choices, 'draft').map((c) => c.value),
        ['draft'],
    );
    assert.deepEqual(
        statusOptions(choices, 'old_sent').map((c) => c.value),
        ['draft', 'old_sent'],
    );
    assert.equal(statusLabel(choices, 'old_sent'), 'Old sent');
    assert.equal(statusLabel(choices, 'on_hold'), 'on hold');
    assert.equal(money('10.00', 'AED'), 'AED 10.00');
    assert.equal(money(null, null), '—');
});

await test('totals are summed per currency, never mixed', () => {
    assert.deepEqual(
        totalsByCurrency([
            { total: '100.10', currency: 'AED' },
            { total: '0.20', currency: 'AED' },
            { total: '50.00', currency: 'USD' },
            { total: null, currency: 'AED' },
        ]),
        { AED: '100.30', USD: '50.00' },
    );
});
