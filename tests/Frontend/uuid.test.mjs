import assert from 'node:assert/strict';
import { test } from 'node:test';
import { uuid } from '../../resources/js/lib/uuid.ts';

await test('uuids are UUIDs, also without crypto.randomUUID', () => {
    const pattern =
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    assert.match(uuid(), pattern);
    const descriptor =
        Object.getOwnPropertyDescriptor(
            Object.getPrototypeOf(crypto),
            'randomUUID',
        ) ?? Object.getOwnPropertyDescriptor(crypto, 'randomUUID');
    Object.defineProperty(crypto, 'randomUUID', {
        value: undefined,
        configurable: true,
    });
    try {
        assert.match(uuid(), pattern);
    } finally {
        delete crypto.randomUUID;
        if (descriptor && !('randomUUID' in crypto)) {
            Object.defineProperty(crypto, 'randomUUID', descriptor);
        }
    }
});
