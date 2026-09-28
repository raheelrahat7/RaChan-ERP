import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    ariaSort,
    nextSort,
    selectionState,
    toggleAll,
    toggleOne,
} from '../../resources/js/lib/data-table.ts';

await test('sorting cycles ascending, descending, then off, and resets on a new column', () => {
    let sort = nextSort(null, 'amount');
    assert.deepEqual(sort, { key: 'amount', direction: 'asc' });
    sort = nextSort(sort, 'amount');
    assert.deepEqual(sort, { key: 'amount', direction: 'desc' });
    assert.equal(nextSort(sort, 'amount'), null);
    assert.deepEqual(nextSort(sort, 'due'), { key: 'due', direction: 'asc' });
    assert.equal(
        ariaSort({ key: 'amount', direction: 'desc' }, 'amount'),
        'descending',
    );
    assert.equal(ariaSort({ key: 'amount', direction: 'asc' }, 'due'), 'none');
    assert.equal(ariaSort(null, 'due'), 'none');
});

await test('row selection toggles single rows and all visible rows', () => {
    assert.deepEqual(toggleOne([1, 2], 2), [1]);
    assert.deepEqual(toggleOne([1], 3), [1, 3]);
    assert.deepEqual(toggleAll([9], [1, 2]), [9, 1, 2]);
    assert.deepEqual(toggleAll([9, 1, 2], [1, 2]), [9]);
    assert.deepEqual(toggleAll([], []), []);
});

await test('the header checkbox reflects none, some or all visible rows', () => {
    assert.equal(selectionState([], [1, 2]), false);
    assert.equal(selectionState([1], [1, 2]), 'indeterminate');
    assert.equal(selectionState([1, 2, 7], [1, 2]), true);
    assert.equal(selectionState([7], []), false);
});
