import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    createBody,
    criteriaText,
    fromLocalInput,
    hasChanges,
    matchForm,
    needsDate,
    priceText,
    scoreTone,
    statusChoices,
    toLocalInput,
    updateBody,
} from '../../resources/js/lib/crm-matches.ts';

const match = {
    id: 9,
    lead_id: 1,
    listing_id: 3,
    version: 2,
    match_percent: 80,
    match_percent_override: null,
    score_source: 'automatic',
    matched_on: ['purpose'],
    evaluated_on: ['purpose', 'budget'],
    shared: false,
    viewing_status: 'scheduled',
    viewing_at: '2027-01-02T10:00:00+00:00',
    notes: null,
    listing: {
        id: 3,
        reference: 'L-3',
        status: 'active',
        purpose: 'sale',
        market_segment: null,
        price: '1000000.00',
        currency: 'AED',
        unit: null,
        property: null,
        community: null,
    },
    permissions: { read: true, edit: true, delete: true },
};

await test('criteria text shows matched over evaluated', () => {
    assert.equal(criteriaText(['purpose'], ['purpose', 'budget']), '1/2');
    assert.equal(criteriaText([], []), '—');
    assert.deepEqual(
        [scoreTone(90), scoreTone(50), scoreTone(10)],
        ['high', 'medium', 'low'],
    );
});

await test('local datetime round-trips through ISO', () => {
    const iso = fromLocalInput('2027-01-02T10:00');
    assert.match(iso, /^2027-01-0[12]T\d\d:\d\d:00\.000Z$/);
    assert.equal(toLocalInput(iso), '2027-01-02T10:00');
    assert.equal(fromLocalInput(''), null);
    assert.equal(fromLocalInput('garbage'), null);
    assert.equal(toLocalInput(null), '');
});

await test('create body maps blanks to null', () => {
    const form = { ...matchForm(), notes: '  ', override: '' };
    assert.deepEqual(createBody(3, form), {
        listing_id: 3,
        match_percent_override: null,
        shared: false,
        viewing_status: 'not_scheduled',
        viewing_at: null,
        notes: null,
    });
});

await test('update body is partial and always carries the version', () => {
    const form = matchForm(match);
    assert.deepEqual(updateBody(match, form), { expected_version: 2 });
    assert.equal(hasChanges(updateBody(match, form)), false);
    form.override = '73';
    form.shared = true;
    form.notes = 'Viewing done';
    const body = updateBody(match, form);
    assert.deepEqual(body, {
        expected_version: 2,
        match_percent_override: 73,
        shared: true,
        notes: 'Viewing done',
    });
    form.override = '';
    assert.equal(
        'match_percent_override' in
            updateBody({ ...match, match_percent_override: 73 }, form),
        true,
    );
});

await test('scheduled status needs a date and archived statuses stay visible only when current', () => {
    assert.equal(needsDate('scheduled', ''), true);
    assert.equal(needsDate('completed', ''), false);
    const choices = [
        { value: 'a', label: 'A', active: true },
        { value: 'b', label: 'B', active: false },
    ];
    assert.deepEqual(
        statusChoices(choices, 'a').map((c) => c.value),
        ['a'],
    );
    assert.deepEqual(
        statusChoices(choices, 'b').map((c) => c.value),
        ['a', 'b'],
    );
    assert.equal(priceText(match.listing), 'AED 1000000.00');
});

await test('a numeric override from a number input is accepted', () => {
    const form = { ...matchForm(match), override: 73 };
    assert.equal(updateBody(match, form).match_percent_override, 73);
    assert.equal(
        createBody(3, { ...matchForm(), override: 0 }).match_percent_override,
        0,
    );
});
