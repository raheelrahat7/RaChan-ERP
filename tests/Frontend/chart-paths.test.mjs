import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    areaPath,
    chartPoints,
    donutSegments,
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

await test('missing values leave a gap instead of shifting later points', () => {
    const points = chartPoints([1, Number.NaN, 3], 100, 24);
    assert.deepEqual(
        points.map(([x]) => x),
        [0, 100],
    );
    const line = linePath([1, 2, null, 3, 4], 100, 24);
    assert.equal((line.match(/M/g) ?? []).length, 2);
    assert.ok(!line.includes('NaN'));
});

await test('decimal strings from Laravel casts are charted, not dropped', () => {
    const line = linePath(['1200.00', '1350.50', '1300.25'], 100, 24);
    assert.match(line, /^M0,/);
    assert.ok(!line.includes('NaN'));
});

await test('a shared domain keeps two series on the same scale', () => {
    const high = chartPoints([50, 100], 100, 100, 0, [0, 100]);
    const low = chartPoints([10, 20], 100, 100, 0, [0, 100]);
    assert.deepEqual(
        high.map(([, y]) => y),
        [50, 0],
    );
    assert.deepEqual(
        low.map(([, y]) => y),
        [90, 80],
    );
});

await test('donut segments split the ring in proportion, with gaps', () => {
    assert.deepEqual(donutSegments([3, 1], 100, 2), [
        { length: 73, offset: 0 },
        { length: 23, offset: -75 },
    ]);
    assert.deepEqual(donutSegments([0, 0], 100), []);
    assert.deepEqual(donutSegments([-5, 5], 100), [
        { length: 0, offset: 0 },
        { length: 100, offset: 0 },
    ]);
});
