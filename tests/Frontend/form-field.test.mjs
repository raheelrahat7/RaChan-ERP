import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fieldDescription } from '../../resources/js/lib/form-field.ts';

await test('controls point at the error when there is one, otherwise at the help text', () => {
    assert.deepEqual(
        fieldDescription('rent', { help: 'Yearly', error: 'Required' }),
        {
            describedBy: 'rent-error',
            invalid: true,
        },
    );
    assert.deepEqual(fieldDescription('rent', { help: 'Yearly' }), {
        describedBy: 'rent-help',
        invalid: false,
    });
    assert.deepEqual(fieldDescription('rent', {}), {
        describedBy: undefined,
        invalid: false,
    });
});
