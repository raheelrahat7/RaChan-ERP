import assert from 'node:assert/strict';
import { test } from 'node:test';
import { splitName } from '../../resources/js/lib/crm-lead-picker.ts';

await test('names split into first and last', () => {
    assert.deepEqual(splitName('Lina Karim'), {
        first_name: 'Lina',
        last_name: 'Karim',
    });
    assert.deepEqual(splitName('Mary Ann Smith'), {
        first_name: 'Mary Ann',
        last_name: 'Smith',
    });
    assert.deepEqual(splitName('  Cher '), {
        first_name: 'Cher',
        last_name: '',
    });
    assert.deepEqual(splitName(''), { first_name: '', last_name: '' });
});
