import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    activeFilterCount,
    codeFromName,
    countFor,
    createExtras,
    detailForm,
    emptyFilters,
    filtersQuery,
    hasChanges,
    moneyText,
    priceRangeError,
    segmentLabel,
    statusChoices,
    statusName,
    stripStatuses,
    updateBody,
} from '../../resources/js/lib/listings.ts';

const listing = {
    id: 1,
    version: 3,
    reference: 'L-1',
    purpose: 'sale',
    status: 'draft',
    workflow_status: 'draft',
    market_segment: 'secondary',
    price: '1000000.00',
    currency: 'AED',
    price_per_sqft: null,
    public_url: null,
    unit: null,
    property: null,
    building: null,
    bedrooms: 2,
    portals: ['bayut'],
    loading_bay: false,
    owner_id: 4,
    permissions: { read: true, edit: true, change_status: true },
};

await test('form mirrors a listing and survives numbers from the server', () => {
    const form = detailForm(listing);
    assert.equal(form.bedrooms, '2');
    assert.equal(form.owner_id, '4');
    assert.equal(form.portals, 'bayut');
    assert.equal(form.currency, 'AED');
    assert.equal(detailForm().workflow_status, 'draft');
});

await test('update body is partial, versioned and typed', () => {
    const form = detailForm(listing);
    assert.deepEqual(updateBody(listing, form, true), { expected_version: 3 });
    form.community = 'Marina';
    form.bedrooms = '3';
    form.owner_id = '';
    form.portals = 'bayut, dubizzle, bayut';
    form.size_sqft = '1200.5';
    assert.deepEqual(updateBody(listing, form, true), {
        expected_version: 3,
        community: 'Marina',
        bedrooms: 3,
        owner_id: null,
        portals: ['bayut', 'dubizzle'],
        size_sqft: '1200.5',
    });
    assert.equal(hasChanges({ expected_version: 3 }), false);
});

await test('secondary fields are ignored off the secondary screen', () => {
    const form = detailForm(listing);
    form.valuation_price = '900000';
    form.buyer_contact_id = '8';
    assert.equal(hasChanges(updateBody(listing, form, false)), false);
    assert.deepEqual(updateBody(listing, form, true), {
        expected_version: 3,
        valuation_price: '900000',
        buyer_contact_id: 8,
    });
});

await test('create extras leave blanks and the default status out', () => {
    const form = detailForm();
    form.emirate = 'Dubai';
    form.bedrooms = '2';
    assert.deepEqual(createExtras(form, false), {
        emirate: 'Dubai',
        bedrooms: 2,
    });
    form.workflow_status = 'documents_pending';
    form.noc_status = 'pending';
    assert.deepEqual(createExtras(form, true), {
        workflow_status: 'documents_pending',
        emirate: 'Dubai',
        bedrooms: 2,
        noc_status: 'pending',
    });
});

await test('filters become a short query string', () => {
    const f = emptyFilters();
    assert.equal(filtersQuery(f, null), '');
    f.q = 'marina';
    f.sort = 'price_desc';
    assert.equal(
        filtersQuery(f, 'secondary', 2),
        'q=marina&sort=price_desc&market_segment=secondary&page=2',
    );
    assert.equal(activeFilterCount(f), 2);
});

await test('status strip and choices keep archived values only when in use', () => {
    const statuses = [
        { code: 'a', name: 'A', position: 1, active: true },
        { code: 'b', name: 'B', position: 0, active: false },
    ];
    assert.deepEqual(
        stripStatuses(statuses, '').map((s) => s.code),
        ['a'],
    );
    assert.deepEqual(
        stripStatuses(statuses, 'b').map((s) => s.code),
        ['b', 'a'],
    );
    assert.deepEqual(
        statusChoices(statuses, 'b').map((s) => s.code),
        ['a', 'b'],
    );
    assert.equal(statusName(statuses, 'b'), 'B');
    assert.equal(statusName(statuses, 'on_hold'), 'On hold');
    assert.equal(countFor(null, 'a'), null);
    assert.equal(countFor([{ code: 'a', total: 3 }], 'b'), 0);
});

await test('small helpers', () => {
    assert.equal(
        segmentLabel({ purpose: 'rent', market_segment: null }),
        'Rental',
    );
    assert.equal(
        segmentLabel({ purpose: 'sale', market_segment: 'secondary' }),
        'Resale',
    );
    assert.equal(moneyText(null), '—');
    assert.equal(moneyText('10.00', 'USD'), 'USD 10.00');
    assert.equal(priceRangeError({ price_min: '10', price_max: '5' }), true);
    assert.equal(priceRangeError({ price_min: '', price_max: '5' }), false);
    assert.equal(codeFromName(' Legal Review! '), 'legal_review');
    assert.equal(codeFromName('1abc'), '');
});
