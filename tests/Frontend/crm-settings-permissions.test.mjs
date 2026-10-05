import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    cellKey,
    countChanges,
    filterGroups,
    getCell,
    levelLabel,
    setCell,
    toggleCollapsed,
} from '../../resources/js/lib/crm-permissions.ts';
import {
    SETTINGS_CATEGORIES,
    connectedCount,
    findCategory,
    pageCount,
    pageSlice,
} from '../../resources/js/lib/crm-settings.ts';

const groups = [
    {
        key: 'lead',
        label: 'Lead',
        rows: [
            { key: 'read', label: 'Read', kind: 'level' },
            { key: 'import', label: 'Import', kind: 'level' },
            { key: 'form', label: 'Allow custom view form', kind: 'toggle' },
        ],
    },
    {
        key: 'deal',
        label: 'Deal pipeline: Off plan',
        rows: [{ key: 'read', label: 'Read', kind: 'level' }],
    },
];
const roles = [
    { id: 1, name: 'Manager', members: [] },
    { id: 2, name: 'Agent', members: [] },
];

await test('cells default to deny and false, and edits are immutable', () => {
    const base = {};
    assert.equal(getCell(base, 1, 'lead.read', 'level'), 'deny');
    assert.equal(getCell(base, 1, 'lead.form', 'toggle'), false);
    const next = setCell(base, 1, cellKey('lead', 'read'), 'all');
    assert.deepEqual(base, {});
    assert.equal(getCell(next, 1, 'lead.read', 'level'), 'all');
    assert.equal(getCell(next, 2, 'lead.read', 'level'), 'deny');
    assert.equal(levelLabel('own'), 'Their own items');
    assert.equal(levelLabel(undefined), 'Deny access');
});

await test('changes count effective differences only', () => {
    const before = { 1: { 'lead.read': 'own' } };
    let after = setCell(before, 1, 'lead.read', 'all');
    after = setCell(after, 2, 'lead.form', true);
    assert.equal(countChanges(groups, roles, before, after), 2);
    assert.equal(
        countChanges(
            groups,
            roles,
            before,
            setCell(before, 2, 'lead.import', 'deny'),
        ),
        0,
    );
});

await test('row search keeps matching groups and all rows of a matching group', () => {
    assert.equal(filterGroups(groups, '').length, 2);
    assert.deepEqual(
        filterGroups(groups, 'import').map((g) => g.rows.map((r) => r.key)),
        [['import']],
    );
    assert.equal(filterGroups(groups, 'off plan')[0].rows.length, 1);
    assert.equal(filterGroups(groups, 'zzz').length, 0);
    assert.deepEqual(toggleCollapsed([], 'lead'), ['lead']);
    assert.deepEqual(toggleCollapsed(['lead'], 'lead'), []);
});

await test('settings categories are unique and tiles stay honest about connection', () => {
    const keys = SETTINGS_CATEGORIES.map((c) => c.key);
    assert.equal(new Set(keys).size, keys.length);
    const tiles = SETTINGS_CATEGORIES.flatMap((c) => c.tiles.map((t) => t.key));
    assert.equal(new Set(tiles).size, tiles.length);
    assert.ok(SETTINGS_CATEGORIES.every((c) => c.tiles.length > 0));
    assert.ok(
        findCategory('automation').tiles.every((t) =>
            t.href?.startsWith('/crm/'),
        ),
    );
    assert.equal(findCategory('missing').key, 'start');
    assert.ok(connectedCount(findCategory('start')) >= 4);
});

await test('paging slices rows and never reports zero pages', () => {
    const rows = Array.from({ length: 45 }, (_, i) => i);
    assert.equal(pageSlice(rows, 1, 20).length, 20);
    assert.equal(pageSlice(rows, 3, 20).length, 5);
    assert.equal(pageCount(45, 20), 3);
    assert.equal(pageCount(0, 20), 1);
    assert.deepEqual(pageSlice(rows, 0, 2), [0, 1]);
});
