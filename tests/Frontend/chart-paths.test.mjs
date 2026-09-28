import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    areaPath,
    chartPoints,
    linePath,
} from '../../resources/js/lib/chart-paths.ts';

await test('points span the full width and respect padding', () => {
    const points = chartPoints([1, 3, 2], 100, 24, 2);
    assert.deepEqual(points[0], [0, 22]);
    assert.deepEqual(points[1], [50, 2]);
    assert.equal(points[2][0], 100);
});

await test('flat, single and empty series never produce NaN', () => {
    assert.equal(linePath([], 86, 24), '');
    assert.equal(areaPath([], 86, 24), '');
    assert.equal(linePath([5], 86, 24), 'M0,12 L86,12');
    for (const path of [
        linePath([5, 5, 5], 86, 24, 2),
        linePath([1, Number.NaN, 3], 86, 24),
    ]) {
        assert.ok(!path.includes('NaN'), path);
    }
    assert.deepEqual(
        chartPoints([5, 5, 5], 86, 24).map(([, y]) => y),
        [12, 12, 12],
    );
});

await test('line paths are smooth curves and areas close to the baseline', () => {
    const line = linePath([1, 2, 3], 100, 50);
    assert.match(line, /^M0,50 C/);
    assert.equal((line.match(/C/g) ?? []).length, 2);
    assert.ok(areaPath([1, 2, 3], 100, 50).endsWith('L100,50 L0,50 Z'));
});
