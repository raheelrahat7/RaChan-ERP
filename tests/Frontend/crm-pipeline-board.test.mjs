import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    boardColumns,
    canMoveOnBoard,
    canDropOnStage,
} from '../../resources/js/lib/crm-pipeline-board.ts';
const initial = { id: 1, name: 'Start', type: 'normal', active: true };
const lost = { id: 2, name: 'Closed', type: 'lost', active: true };
const won = { id: 3, name: 'Success', type: 'won', active: true };
const archived = { id: 4, name: 'Old', type: 'normal', active: false };
const pipeline = {
    id: 10,
    active: true,
    stages: [lost, initial, won, archived],
};
const lead = {
    id: 100,
    pipeline_id: 10,
    current_stage_id: 1,
    converted: false,
};
await test('board follows dynamic stage order, keeps archived leads, and excludes other pipelines', () => {
    const columns = boardColumns(pipeline, [
        lead,
        { ...lead, id: 101, current_stage_id: 4 },
        { ...lead, id: 102, pipeline_id: 20 },
    ]);
    assert.deepEqual(
        columns.map((column) => [
            column.stage.id,
            column.leads.map((item) => item.id),
        ]),
        [
            [2, []],
            [1, [100]],
            [3, []],
            [4, [101]],
        ],
    );
});
await test('drops require permission, active pipeline/target, local lead, and a different configured stage', () => {
    assert.equal(canDropOnStage(pipeline, lead, lost, true), true);
    assert.equal(canDropOnStage(pipeline, lead, lost, false), false);
    assert.equal(
        canDropOnStage({ ...pipeline, active: false }, lead, lost, true),
        false,
    );
    assert.equal(
        canDropOnStage(pipeline, { ...lead, pipeline_id: 20 }, lost, true),
        false,
    );
    assert.equal(
        canDropOnStage(pipeline, { ...lead, converted: true }, lost, true),
        false,
    );
    assert.equal(canDropOnStage(pipeline, lead, initial, true), false);
    assert.equal(canDropOnStage(pipeline, lead, archived, true), false);
    assert.equal(
        canDropOnStage(pipeline, lead, { ...initial, id: 99 }, true),
        false,
    );
});
await test('lost leads reopen only to nonterminal stages, including moves out of an archived stage', () => {
    const closed = { ...lead, current_stage_id: 2 };
    assert.equal(canDropOnStage(pipeline, closed, initial, true), true);
    assert.equal(canDropOnStage(pipeline, closed, won, true), false);
    assert.equal(
        canDropOnStage(
            pipeline,
            { ...lead, current_stage_id: 4 },
            initial,
            true,
        ),
        true,
    );
    assert.equal(
        canMoveOnBoard(pipeline, { ...lead, converted: true }, true),
        false,
    );
});

await test('server-supplied rule explanations prevent restricted drops', () => {
    const restricted = {
        ...lead,
        transitionOptions: [
            { stage_id: lost.id, reasons: ['Phone is required.'] },
        ],
    };
    assert.equal(canDropOnStage(pipeline, restricted, lost, true), false);
    assert.equal(
        canDropOnStage(
            pipeline,
            {
                ...restricted,
                transitionOptions: [{ stage_id: lost.id, reasons: [] }],
            },
            lost,
            true,
        ),
        true,
    );
});
