import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    NAVIGATION,
    activeGroupId,
    activeHref,
    navHrefs,
} from '../../resources/js/lib/navigation.ts';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const routeSource = read('routes/web.php') + read('routes/settings.php');
const getRoutes = new Set(
    [...routeSource.matchAll(/Route::(?:get|inertia)\('([^']+)'/g)].map(
        (match) => `/${match[1].replace(/^\//, '')}`,
    ),
);
const arabic = JSON.parse(read('resources/js/locales/ar.json'));

await test('every navigation link points at an existing GET route', () => {
    const missing = navHrefs().filter((href) => !getRoutes.has(href));
    assert.deepEqual(missing, []);
});

await test('no route appears twice in the navigation', () => {
    const hrefs = navHrefs();
    assert.equal(new Set(hrefs).size, hrefs.length);
});

await test('groups are unique, non-empty, and parents open their first child', () => {
    const ids = NAVIGATION.map((group) => group.id);
    assert.equal(new Set(ids).size, ids.length);
    for (const group of NAVIGATION) {
        assert.ok(group.items.length > 0, `${group.id} is empty`);
        for (const item of group.items) {
            if (item.children) {
                assert.equal(
                    item.href,
                    item.children[0].href,
                    `${item.label} must open its first child`,
                );
            }
        }
    }
});

await test('every group, item and child label has an Arabic translation', () => {
    const labels = NAVIGATION.flatMap((group) => [
        group.label,
        ...group.items.flatMap((item) => [
            item.label,
            ...(item.children ?? []).map((child) => child.label),
        ]),
    ]);
    const missing = [...new Set(labels)].filter((label) => !arabic[label]);
    assert.deepEqual(missing, []);
});

await test('the active link is the longest matching path, ignoring query strings', () => {
    assert.equal(
        activeHref('/accounting/vat-return?period=2026-09'),
        '/accounting/vat-return',
    );
    assert.equal(activeHref('/accounting'), '/accounting');
    assert.equal(activeHref('/operations/fleet/12'), '/operations/fleet');
    assert.equal(activeHref('/crm/leads-archive'), null);
    assert.equal(activeHref('/unknown'), null);
    assert.equal(activeGroupId('/accounting/vat-return'), 'finance');
    assert.equal(activeGroupId('/settings/profile'), 'administration');
    assert.equal(activeGroupId('/unknown'), null);
});
