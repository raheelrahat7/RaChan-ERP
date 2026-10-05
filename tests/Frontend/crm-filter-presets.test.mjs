import assert from 'node:assert/strict';
import test from 'node:test';
import {
    builtinPresets,
    deletePreset,
    loadFieldSelection,
    loadPresets,
    saveFieldSelection,
    savePreset,
    visibleFieldKeys,
} from '../../resources/js/lib/crm-filter-presets.ts';

function memory() {
    const data = new Map();

    return {
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => data.set(key, value),
    };
}
const catalog = [
    { key: 'a', group: 'Lead' },
    { key: 'b', group: 'Lead' },
    { key: 'c', group: 'Activity' },
];

await test('field selection defaults, persists and drops removed fields', () => {
    const storage = memory();
    assert.equal(loadFieldSelection(storage), null);
    assert.deepEqual(visibleFieldKeys(catalog, null), ['a', 'b', 'c']);
    saveFieldSelection(storage, ['c', 'gone', 'a']);
    assert.deepEqual(visibleFieldKeys(catalog, loadFieldSelection(storage)), [
        'a',
        'c',
    ]);
});

await test('corrupt or unavailable storage falls back safely', () => {
    const broken = {
        getItem: () => '{nope',
        setItem: () => {
            throw new Error('full');
        },
    };
    assert.equal(loadFieldSelection(broken), null);
    assert.deepEqual(loadPresets(broken), []);
    assert.doesNotThrow(() => saveFieldSelection(broken, ['a']));
    assert.equal(loadFieldSelection(null), null);
});

await test('built-in presets include My leads only with a known user', () => {
    assert.deepEqual(
        builtinPresets(null).map((p) => p.id),
        ['all'],
    );
    assert.equal(builtinPresets(7)[1].state.assignee_id, 7);
});

await test('saved presets are named, replaced by name and deletable', () => {
    const storage = memory();
    const state = { q: 'x', assignee_id: null, filters: [] };
    assert.deepEqual(savePreset(storage, '   ', state), []);
    savePreset(storage, 'Hot', state);
    savePreset(storage, 'Hot', { ...state, q: 'y' });
    const saved = loadPresets(storage);
    assert.equal(saved.length, 1);
    assert.equal(saved[0].state.q, 'y');
    assert.deepEqual(deletePreset(storage, saved[0].id), []);
});
