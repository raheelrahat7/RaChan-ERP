import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    duplicateTargets,
    errorMessages,
    guessMapping,
    hasNameMapping,
    initialStep,
    sampleValues,
    targetLabel,
} from '../../resources/js/lib/crm-import-wizard.ts';

const targets = [
    'first_name',
    'last_name',
    'full_name',
    'email',
    'phone',
    'custom:budget',
];

await test('wizard opens at the right step for the batch state', () => {
    assert.equal(initialStep(null), 1);
    assert.equal(initialStep({ committed_at: null, summary: null }), 2);
    assert.equal(
        initialStep({ committed_at: '2026-10-05', summary: { created: 1 } }),
        4,
    );
});

await test('headers are guessed once, with common aliases', () => {
    const mapping = guessMapping(
        ['First Name', 'Last name', 'Work E-mail', 'Mobile', 'Phone', 'Notes'],
        targets,
    );
    assert.equal(mapping['First Name'], 'first_name');
    assert.equal(mapping['Work E-mail'], 'email');
    assert.equal(mapping['Mobile'], 'phone');
    assert.equal(
        mapping['Phone'],
        '',
        'second phone-like column is not double mapped',
    );
    assert.equal(mapping['Notes'], '');
});

await test('name mapping and duplicate target checks', () => {
    assert.equal(hasNameMapping({ a: 'full_name' }), true);
    assert.equal(hasNameMapping({ a: 'first_name' }), false);
    assert.equal(hasNameMapping({ a: 'first_name', b: 'last_name' }), true);
    assert.deepEqual(duplicateTargets({ a: 'email', b: 'email', c: '' }), [
        'email',
    ]);
});

await test('sample values skip blanks and errors format both shapes', () => {
    assert.deepEqual(
        sampleValues(
            [{ a: '' }, { a: ' x ' }, { a: 'y' }, { a: 'z' }, { a: 'w' }],
            'a',
        ),
        ['x', 'y', 'z'],
    );
    assert.equal(errorMessages(['a', 'b']), 'a; b');
    assert.equal(
        errorMessages({ email: ['bad'], phone: ['worse'] }),
        'bad; worse',
    );
    assert.equal(targetLabel('custom:budget', { budget: 'Budget' }), 'Budget');
    assert.equal(targetLabel('first_name', {}), 'first name');
});
