import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    changeField,
    cleanConditions,
    exportQuery,
    isComplete,
    needsValue,
    newCondition,
} from '../../resources/js/lib/crm-deal-filters.ts';

const fields = [
    {
        key: 'budget',
        name: 'Budget',
        type: 'number',
        options: null,
        operators: ['equals', 'gte', 'empty'],
    },
    {
        key: 'vip',
        name: 'VIP',
        type: 'checkbox',
        options: null,
        operators: ['equals', 'empty'],
    },
    {
        key: 'note',
        name: 'Note',
        type: 'text',
        options: null,
        operators: ['contains', 'not_empty'],
    },
];

await test('new conditions start on the first field and operator', () => {
    assert.deepEqual(newCondition(fields), {
        field: 'budget',
        operator: 'equals',
    });
    assert.equal(newCondition([]), null);
});

await test('changing field resets operator and value', () => {
    assert.deepEqual(
        changeField(
            fields,
            { field: 'budget', operator: 'gte', value: '5' },
            'note',
        ),
        { field: 'note', operator: 'contains' },
    );
});

await test('completeness needs a known operator and a value unless empty-type', () => {
    assert.equal(
        isComplete(fields, { field: 'budget', operator: 'equals' }),
        false,
    );
    assert.equal(
        isComplete(fields, { field: 'budget', operator: 'empty' }),
        true,
    );
    assert.equal(
        isComplete(fields, {
            field: 'budget',
            operator: 'contains',
            value: 'x',
        }),
        false,
    );
    assert.equal(
        isComplete(fields, { field: 'ghost', operator: 'equals', value: '1' }),
        false,
    );
    assert.equal(needsValue('not_empty'), false);
});

await test('cleaning drops incomplete rows and coerces values', () => {
    const cleaned = cleanConditions(fields, [
        { field: 'budget', operator: 'gte', value: '5000' },
        { field: 'vip', operator: 'equals', value: 'true' },
        { field: 'note', operator: 'contains', value: '' },
        { field: 'note', operator: 'not_empty', value: 'ignored' },
    ]);
    assert.deepEqual(cleaned, [
        { field: 'budget', operator: 'gte', value: 5000 },
        { field: 'vip', operator: 'equals', value: true },
        { field: 'note', operator: 'not_empty' },
    ]);
});

await test('export query uses bracket syntax for conditions', () => {
    const query = exportQuery({
        pipeline_id: 2,
        q: 'villa',
        custom_filters: [{ field: 'budget', operator: 'gte', value: 5000 }],
    });
    const params = new URLSearchParams(query);
    assert.equal(params.get('pipeline_id'), '2');
    assert.equal(params.get('custom_filters[0][field]'), 'budget');
    assert.equal(params.get('custom_filters[0][value]'), '5000');
});
