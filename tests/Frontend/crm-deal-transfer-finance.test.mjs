import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    transferBody,
    transferStages,
    transferTargets,
} from '../../resources/js/lib/crm-deal-transfer.ts';
import {
    emptyFinancials,
    linkBody,
    outstandingTotal,
} from '../../resources/js/lib/crm-deal-finance.ts';

const pipelines = [
    {
        id: 1,
        name: 'A',
        active: true,
        stages: [],
        permissions: { add: ['own'] },
    },
    {
        id: 2,
        name: 'B',
        active: true,
        stages: [
            {
                id: 20,
                name: 'Late',
                type: 'normal',
                color: '#000',
                position: 2,
                active: true,
            },
            {
                id: 21,
                name: 'Early',
                type: 'normal',
                color: '#000',
                position: 1,
                active: true,
            },
            {
                id: 22,
                name: 'Old',
                type: 'lost',
                color: '#000',
                position: 3,
                active: false,
            },
        ],
        permissions: { add: ['organization'] },
    },
    { id: 3, name: 'C', active: true, stages: [], permissions: { add: [] } },
    {
        id: 4,
        name: 'D',
        active: false,
        stages: [],
        permissions: { add: ['own'] },
    },
];

await test('transfer targets exclude the current, inactive and no-add pipelines', () => {
    assert.deepEqual(
        transferTargets(pipelines, 1).map((p) => p.id),
        [2],
    );
});

await test('transfer stages are active and ordered', () => {
    assert.deepEqual(
        transferStages(pipelines, 2).map((s) => s.id),
        [21, 20],
    );
    assert.deepEqual(transferStages(pipelines, 99), []);
});

await test('transfer body only carries filled optional fields', () => {
    assert.deepEqual(
        transferBody({
            expectedVersion: 3,
            pipelineId: 2,
            stageId: 21,
            notes: ' ',
            lostReason: '',
            confirmed: true,
        }),
        { expected_version: 3, pipeline_id: 2, stage_id: 21, confirmed: true },
    );
    assert.equal(
        transferBody({
            expectedVersion: 3,
            pipelineId: 2,
            stageId: 21,
            notes: 'why',
            lostReason: 'gone',
            confirmed: true,
        }).lost_reason,
        'gone',
    );
});

await test('link body validates the record id and supports removal', () => {
    assert.equal(
        linkBody({ kind: 'invoice', recordId: 'abc', expectedVersion: 1 }),
        null,
    );
    assert.equal(
        linkBody({ kind: 'invoice', recordId: '0', expectedVersion: 1 }),
        null,
    );
    assert.deepEqual(
        linkBody({
            kind: 'commission',
            recordId: '7',
            expectedVersion: 2,
            remove: true,
        }),
        { kind: 'commission', record_id: 7, expected_version: 2, remove: true },
    );
});

await test('financial summaries default to empty lists and total what is outstanding', () => {
    assert.deepEqual(emptyFinancials(null), { invoices: [], commissions: [] });
    assert.equal(outstandingTotal([]), null);
    assert.equal(
        outstandingTotal([
            {
                id: 1,
                reference: 'I',
                status: 'posted',
                total: 10,
                currency: 'AED',
                outstanding: '10.10',
            },
            {
                id: 2,
                reference: 'J',
                status: 'posted',
                total: 5,
                currency: 'AED',
                outstanding: 0.2,
            },
        ]),
        '10.30',
    );
});
