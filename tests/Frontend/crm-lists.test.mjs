import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    SELECTION_LISTS,
    codeFromName,
    listLabel,
    nextPosition,
    optionsFor,
} from '../../resources/js/lib/crm-lists.ts';

const make = (id, list, position) => ({
    id,
    list_key: list,
    code: null,
    name: `n${id}`,
    position,
    active: 1,
});

await test('options are filtered to one list and ordered', () => {
    const options = [
        make(1, 'sources', 2),
        make(2, 'industries', 1),
        make(3, 'sources', 1),
    ];
    assert.deepEqual(
        optionsFor('sources', options).map((o) => o.id),
        [3, 1],
    );
    assert.equal(nextPosition(optionsFor('sources', options)), 3);
    assert.equal(nextPosition([]), 1);
});

await test('every backend list has a label', () => {
    assert.equal(SELECTION_LISTS.length, 9);
    assert.equal(listLabel('sources'), 'Lead sources');
    assert.equal(listLabel('other'), 'other');
});

await test('category codes are safe identifiers', () => {
    assert.equal(codeFromName('Off-plan Sales'), 'off_plan_sales');
    assert.equal(codeFromName('2nd hand'), '');
    assert.equal(codeFromName('A'.repeat(40)).length, 24);
});
