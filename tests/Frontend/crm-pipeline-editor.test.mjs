import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    funnelSlices,
    groupStages,
    moveWithin,
} from '../../resources/js/lib/crm-pipeline-editor.ts';

const stage = (id, position, type = 'normal', extra = {}) => ({
    id,
    name: `S${id}`,
    position,
    type,
    color: '#336699',
    active: true,
    is_initial: false,
    ...extra,
});
const stages = [
    stage(5, 9, 'lost'),
    stage(1, 1, 'normal', { is_initial: true }),
    stage(3, 3, 'on_hold'),
    stage(2, 2),
    stage(4, 8, 'won'),
    stage(6, 10, 'lost'),
];

await test('stages are grouped by role and ordered by position', () => {
    const groups = groupStages(stages);
    assert.equal(groups.initial.id, 1);
    assert.deepEqual(
        groups.additional.map((s) => s.id),
        [2, 3],
    );
    assert.deepEqual(
        groups.won.map((s) => s.id),
        [4],
    );
    assert.deepEqual(
        groups.lost.map((s) => s.id),
        [5, 6],
    );
    assert.equal(groupStages([]).initial, null);
});

await test('moving a stage reuses the list positions and reports only changes', () => {
    const { additional } = groupStages(stages);
    assert.deepEqual(moveWithin(additional, 3, 0), [
        { id: 3, position: 2 },
        { id: 2, position: 3 },
    ]);
    assert.deepEqual(moveWithin(additional, 2, 0), []);
    assert.deepEqual(moveWithin(additional, 2, 99), [
        { id: 3, position: 2 },
        { id: 2, position: 3 },
    ]);
    assert.deepEqual(moveWithin(additional, 404, 0), []);
    const gapped = [stage(10, 4), stage(11, 7), stage(12, 20)];
    assert.deepEqual(moveWithin(gapped, 12, 0), [
        { id: 12, position: 4 },
        { id: 10, position: 7 },
        { id: 11, position: 20 },
    ]);
});

await test('funnel slices narrow toward the bottom and stay inside the box', () => {
    const slices = funnelSlices(
        [
            { id: 1, color: '#fff' },
            { id: 2, color: '#000' },
        ],
        100,
        60,
    );
    assert.equal(slices.length, 2);
    const widthOf = (points) => {
        const xs = points.split(' ').map((p) => Number(p.split(',')[0]));
        return Math.max(...xs) - Math.min(...xs);
    };
    assert.ok(widthOf(slices[0].points) > widthOf(slices[1].points));
    assert.ok(
        slices.every((s) =>
            s.points.split(' ').every((p) => {
                const [x, y] = p.split(',').map(Number);
                return x >= 0 && x <= 100 && y >= 0 && y <= 60;
            }),
        ),
    );
    assert.deepEqual(funnelSlices([]), []);
});
