import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    filterByType,
    highlight,
    typeCounts,
} from '../../resources/js/lib/search-results.ts';

const results = [
    { type: 'Lead', title: 'Lina', detail: '', href: '/a' },
    { type: 'Listing', title: 'L-1', detail: '', href: '/b' },
    { type: 'Lead', title: 'Lena', detail: '', href: '/c' },
];

await test('types are counted, most common first', () => {
    assert.deepEqual(typeCounts(results), [
        { type: 'Lead', total: 2 },
        { type: 'Listing', total: 1 },
    ]);
    assert.deepEqual(typeCounts([]), []);
});

await test('filtering by type keeps order and blank keeps all', () => {
    assert.equal(filterByType(results, '').length, 3);
    assert.deepEqual(
        filterByType(results, 'Lead').map((r) => r.href),
        ['/a', '/c'],
    );
});

await test('highlight splits around every match, ignoring case', () => {
    assert.deepEqual(highlight('Palm tower PALM', 'palm'), [
        { text: 'Palm', match: true },
        { text: ' tower ', match: false },
        { text: 'PALM', match: true },
    ]);
    assert.deepEqual(highlight('Marina', 'x'), [
        { text: 'Marina', match: false },
    ]);
    assert.deepEqual(highlight('Marina', 'a'), [
        { text: 'Marina', match: false },
    ]);
    assert.deepEqual(highlight('', 'palm'), [{ text: '', match: false }]);
});
