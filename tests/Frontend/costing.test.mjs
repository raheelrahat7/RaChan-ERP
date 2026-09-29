import assert from 'node:assert/strict';
import { test } from 'node:test';
import { clearBillIdIfEstimate } from '../../resources/js/lib/costing.ts';

await test('switching to estimate clears a previously entered vendor bill id', () => {
    assert.equal(clearBillIdIfEstimate('estimate', '42'), '');
});

await test('switching to bill_linked keeps whatever bill id was entered', () => {
    assert.equal(clearBillIdIfEstimate('bill_linked', '42'), '42');
});

await test('estimate with no bill id stays empty', () => {
    assert.equal(clearBillIdIfEstimate('estimate', ''), '');
});
