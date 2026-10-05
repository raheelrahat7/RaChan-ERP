import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    findReferenceSection,
    formDefaults,
    numberPreview,
    parentOptions,
    sectionRows,
} from '../../resources/js/lib/crm-reference.ts';

await test('sections resolve by key and unknown keys are null', () => {
    assert.equal(findReferenceSection('currency')?.kind, 'currencies');
    assert.equal(findReferenceSection('nope'), null);
});

await test('numbering sections only show their own template kind', () => {
    const rows = [
        { id: 1, kind: 'invoice', active: 1 },
        { id: 2, kind: 'document', active: 1 },
    ];
    assert.deepEqual(
        sectionRows(findReferenceSection('num-invoices'), rows).map(
            (r) => r.id,
        ),
        [1],
    );
});

await test('form defaults cover every required field and read MySQL booleans', () => {
    const currency = formDefaults(findReferenceSection('currency'));
    assert.deepEqual(Object.keys(currency).sort(), [
        'active',
        'code',
        'exchange_rate',
        'face_value',
        'is_base',
        'is_reporting',
        'name',
    ]);
    const edited = formDefaults(findReferenceSection('currency'), {
        id: 1,
        code: 'AED',
        name: 'Dirham',
        exchange_rate: '1.0000000000',
        face_value: 1,
        is_base: 1,
        is_reporting: 0,
        active: 1,
    });
    assert.equal(edited.is_base, true);
    assert.equal(edited.is_reporting, false);
    assert.equal(
        formDefaults(findReferenceSection('num-invoices')).kind,
        'invoice',
    );
});

await test('locations offer only active parents of the right type', () => {
    const places = [
        { id: 1, type: 'country', active: 1 },
        { id: 2, type: 'country', active: 0 },
        { id: 3, type: 'region', active: 1 },
    ];
    assert.deepEqual(
        parentOptions(places, 'region').map((p) => p.id),
        [1],
    );
    assert.deepEqual(
        parentOptions(places, 'city').map((p) => p.id),
        [3],
    );
    assert.deepEqual(parentOptions(places, 'country'), []);
});

await test('number preview pads and optionally adds the year', () => {
    assert.equal(numberPreview('INV', 5, 42, true, 2026), 'INV-2026-00042');
    assert.equal(numberPreview('DOC', 3, 7, false, 2026), 'DOC-007');
});
