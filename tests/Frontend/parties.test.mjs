import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    createBody,
    hasChanges,
    partyForm,
    partyQuery,
    shorten,
    updateBody,
} from '../../resources/js/lib/parties.ts';

const party = {
    id: 1,
    name: 'Coastal',
    email: null,
    phone: '050',
    reference: null,
    payment_terms: 'Milestone',
    commission_notes: null,
    version: 2,
};

await test('create body keeps the name and only filled optional fields', () => {
    const form = partyForm();
    form.name = ' Coastal ';
    form.payment_terms = 'Net 30';
    assert.deepEqual(createBody(form), {
        name: 'Coastal',
        payment_terms: 'Net 30',
    });
});

await test('update body is partial and versioned; blanks clear optional fields', () => {
    const form = partyForm(party);
    assert.deepEqual(updateBody(party, form), { expected_version: 2 });
    form.phone = '';
    form.commission_notes = 'Two percent';
    form.name = 'Coastal Homes';
    assert.deepEqual(updateBody(party, form), {
        expected_version: 2,
        name: 'Coastal Homes',
        phone: null,
        commission_notes: 'Two percent',
    });
    assert.equal(hasChanges({ expected_version: 2 }), false);
});

await test('query and shortening', () => {
    assert.equal(partyQuery(''), '');
    assert.equal(partyQuery(' coast ', 3), 'q=coast&page=3');
    assert.equal(shorten(null), '—');
    assert.equal(shorten('Line one\nline two'), 'Line one');
    assert.equal(shorten('x'.repeat(100), 10), 'xxxxxxxxx…');
});
