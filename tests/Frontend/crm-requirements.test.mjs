import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    choiceCode,
    choiceLabel,
    draftFrom,
    fieldErrors,
    moveChoice,
    payloadFrom,
    requirementSummary,
    selectableChoices,
} from '../../resources/js/lib/crm-requirements.ts';

const base = {
    type: 'buyer',
    purpose: null,
    unit_category: null,
    emirate: null,
    property_type: null,
    furnishing: null,
    rent_frequency: null,
    timeline: null,
    completion_status: null,
    payment_method: null,
    financing_status: null,
    language: null,
    amenities: ['pool'],
    temperature: 'hot',
    size_min: null,
    size_max: null,
    budget_min: '1500000.00',
    budget_max: null,
    down_payment_percent: null,
    roi_percent: null,
    bedrooms_min: 2,
    bedrooms_max: null,
    bathrooms_min: null,
    lead_score: 85,
    location: null,
    size_unit: null,
    budget_currency: 'AED',
    handover_on: null,
    preferences: null,
};

await test('draft turns nulls into empty text and numbers into text', () => {
    const draft = draftFrom(base);
    assert.equal(draft.purpose, '');
    assert.equal(draft.bedrooms_min, '2');
    assert.deepEqual(draft.amenities, ['pool']);
});

await test('payload carries only changed keys, coerced', () => {
    const draft = draftFrom(base);
    assert.deepEqual(payloadFrom(base, draft), {});
    draft.lead_score = '90';
    draft.bedrooms_max = '4';
    draft.preferences = '  ';
    draft.budget_currency = 'usd';
    draft.amenities = ['pool', 'gym'];
    draft.type = '';
    assert.deepEqual(payloadFrom(base, draft), {
        lead_score: 90,
        bedrooms_max: 4,
        budget_currency: 'USD',
        amenities: ['pool', 'gym'],
        type: null,
    });
});

await test('archived choices stay visible only while selected', () => {
    const choices = [
        { value: 'a', label: 'A', active: true },
        { value: 'b', label: 'B', active: false },
    ];
    assert.deepEqual(
        selectableChoices(choices, 'a').map((c) => c.value),
        ['a'],
    );
    assert.deepEqual(
        selectableChoices(choices, 'b').map((c) => c.value),
        ['a', 'b'],
    );
    assert.deepEqual(
        selectableChoices(choices, ['b']).map((c) => c.value),
        ['a', 'b'],
    );
    assert.equal(choiceLabel(choices, 'b'), 'B');
    assert.equal(choiceLabel(choices, 'zzz'), 'zzz');
    assert.equal(choiceLabel(choices, null), '—');
});

await test('server error keys map to field names', () => {
    assert.deepEqual(
        fieldErrors({
            'data.budget_max': 'min',
            'data.amenities.1': 'dup',
            expected_version: 'stale',
        }),
        { budget_max: 'min', amenities: 'dup', expected_version: 'stale' },
    );
});

await test('summary joins type, temperature and budget', () => {
    const choices = {
        type: [{ value: 'buyer', label: 'Buyer', active: true }],
        temperature: [{ value: 'hot', label: 'Hot', active: true }],
    };
    assert.equal(
        requirementSummary(base, choices),
        'Buyer · Hot · AED 1500000.00–',
    );
});

await test('codes and reordering for the choices editor', () => {
    assert.equal(choiceCode('Three to Six Months'), 'three_to_six_months');
    assert.equal(choiceCode('1 bed'), '');
    const list = [
        { value: 'a', label: 'A', active: true },
        { value: 'b', label: 'B', active: true },
    ];
    assert.deepEqual(
        moveChoice(list, 0, 1).map((c) => c.value),
        ['b', 'a'],
    );
    assert.equal(moveChoice(list, 0, -1), list);
});
