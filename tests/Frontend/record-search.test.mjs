import assert from 'node:assert/strict';
import { test } from 'node:test';
import { searchRecords } from '../../resources/js/lib/record-search.ts';

function fakeFetch(handler) {
    return async (url) => handler(String(url));
}

await test('a query under two characters never calls the network', async () => {
    let called = false;
    const outcome = await searchRecords('lead', 'a', async () => {
        called = true;
        throw new Error('should not be called');
    });
    assert.equal(called, false);
    assert.deepEqual(outcome, { status: 'ok', results: [] });
});

await test('a 200 with a JSON array returns the results, url-encoded correctly', async () => {
    const outcome = await searchRecords(
        'lead',
        'Al Noor',
        fakeFetch(async (url) => {
            assert.ok(url.includes('type=lead'));
            assert.ok(url.includes('q=Al%20Noor') || url.includes('q=Al+Noor'));

            return {
                ok: true,
                json: async () => [
                    { id: 1, label: 'Al Noor Trading', sublabel: null },
                ],
            };
        }),
    );
    assert.deepEqual(outcome, {
        status: 'ok',
        results: [{ id: 1, label: 'Al Noor Trading', sublabel: null }],
    });
});

await test('a non-2xx response is treated as unavailable, not an error', async () => {
    const outcome = await searchRecords(
        'listing',
        'ab',
        fakeFetch(async () => ({ ok: false, json: async () => [] })),
    );
    assert.deepEqual(outcome, { status: 'unavailable' });
});

await test('a network failure is treated as unavailable', async () => {
    const outcome = await searchRecords('unit', 'ab', async () => {
        throw new TypeError('Failed to fetch');
    });
    assert.deepEqual(outcome, { status: 'unavailable' });
});

await test('a non-array JSON body is treated as unavailable, never surfaced as results', async () => {
    const outcome = await searchRecords(
        'job',
        'ab',
        fakeFetch(async () => ({
            ok: true,
            json: async () => ({ error: 'nope' }),
        })),
    );
    assert.deepEqual(outcome, { status: 'unavailable' });
});
