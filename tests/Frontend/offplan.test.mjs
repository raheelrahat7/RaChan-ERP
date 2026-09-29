import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    allowedDealTransitions,
    dealTransitionsForUnit,
    milestonePercentageValid,
} from '../../resources/js/lib/offplan.ts';

await test('enquiry deals can move to reserved or cancelled, nothing else', () => {
    assert.deepEqual(allowedDealTransitions('enquiry'), [
        'reserved',
        'cancelled',
    ]);
});

await test('reserved deals can move to contracted or cancelled', () => {
    assert.deepEqual(allowedDealTransitions('reserved'), [
        'contracted',
        'cancelled',
    ]);
});

await test('contracted and cancelled deals have no further transitions', () => {
    assert.deepEqual(allowedDealTransitions('contracted'), []);
    assert.deepEqual(allowedDealTransitions('cancelled'), []);
});

await test('milestone percentage is valid up to the server tolerance of 100.001', () => {
    assert.equal(milestonePercentageValid(60, 40), true);
    assert.equal(milestonePercentageValid(60, 40.001), true);
    assert.equal(milestonePercentageValid(60, 40.01), false);
});

await test('milestone percentage handles an empty existing total', () => {
    assert.equal(milestonePercentageValid(0, 100), true);
    assert.equal(milestonePercentageValid(0, 100.01), false);
});

await test('an enquiry deal loses the Reserve option once its unit is no longer available', () => {
    assert.deepEqual(dealTransitionsForUnit('enquiry', 'available'), [
        'reserved',
        'cancelled',
    ]);
    assert.deepEqual(dealTransitionsForUnit('enquiry', 'reserved'), [
        'cancelled',
    ]);
    assert.deepEqual(dealTransitionsForUnit('enquiry', 'sold'), ['cancelled']);
});

await test('a reserved deal is unaffected since it never offers Reserve anyway', () => {
    assert.deepEqual(dealTransitionsForUnit('reserved', 'reserved'), [
        'contracted',
        'cancelled',
    ]);
});
