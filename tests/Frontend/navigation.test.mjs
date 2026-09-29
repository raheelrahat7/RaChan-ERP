import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    NAVIGATION,
    activeGroupId,
    activeHref,
    commandEntries,
    navHrefs,
    visibleNavigation,
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
const items = NAVIGATION.flatMap((group) => group.items);

await test('every enabled link points at an existing GET route', () => {
    const missing = navHrefs().filter(
        (href) => !getRoutes.has(href.split('#')[0]),
    );
    assert.deepEqual(missing, []);
});

await test('no link appears twice', () => {
    const hrefs = navHrefs();
    assert.equal(new Set(hrefs).size, hrefs.length);
});

await test('soon items have no link and every other item has one', () => {
    for (const item of items) {
        if (item.soon) {
            assert.equal(item.href, undefined, `${item.label} is soon`);
        } else {
            assert.ok(item.href, `${item.label} needs a route`);
        }
    }
});

await test('groups are unique and non-empty, and parents open their first child', () => {
    const ids = NAVIGATION.map((group) => group.id);
    assert.equal(new Set(ids).size, ids.length);
    for (const group of NAVIGATION) {
        assert.ok(group.items.length > 0, `${group.id} is empty`);
    }
    for (const item of items.filter((entry) => entry.children)) {
        assert.equal(item.href, item.children[0].href);
    }
});

await test('every section, item and child label has Arabic', () => {
    const labels = NAVIGATION.flatMap((group) => [
        group.label,
        ...group.items.flatMap((item) => [
            item.label,
            ...(item.children ?? []).map((child) => child.label),
        ]),
    ]);
    const missing = [...new Set([...labels, 'Soon'])].filter(
        (label) => !arabic[label],
    );
    assert.deepEqual(missing, []);
});

await test('the active link is the longest matching path', () => {
    assert.equal(
        activeHref('/accounting/vat-return?period=2026-09'),
        '/accounting/vat-return',
    );
    assert.equal(activeHref('/operations/fleet/12'), '/operations/fleet');
    assert.equal(activeHref('/settings/security'), '/settings/profile');
    assert.equal(activeHref('/crm/assignment'), '/crm/assignment');
    assert.equal(activeHref('/crm/leads-archive'), null);
    assert.equal(activeGroupId('/accounting/vat-return'), 'finance');
    assert.equal(activeGroupId('/settings/appearance'), 'corporate');
    assert.equal(activeGroupId('/maintenance/5'), 'facility');
    assert.equal(activeGroupId('/unknown'), null);
});

await test('abilities hide items, and a section with nothing left disappears', () => {
    assert.equal(visibleNavigation(NAVIGATION, null), NAVIGATION);
    const hidden = visibleNavigation(NAVIGATION, {
        fleet: false,
        crm: false,
    });
    const labels = hidden.flatMap((group) =>
        group.items.map((item) => item.label),
    );
    assert.ok(!labels.includes('Fleet'));
    assert.ok(!labels.includes('CRM & Leads'));
    assert.ok(labels.includes('Maintenance'));
    const onlyOverview = visibleNavigation(
        [
            NAVIGATION[0],
            {
                id: 'x',
                label: 'X',
                items: [
                    {
                        label: 'Fleet',
                        icon: 'fleet',
                        href: '/operations/fleet',
                        ability: 'fleet',
                    },
                ],
            },
        ],
        { fleet: false },
    );
    assert.deepEqual(
        onlyOverview.map((group) => group.id),
        ['overview'],
    );
});

await test('the command palette lists enabled pages only, children included', () => {
    const entries = commandEntries(NAVIGATION);
    assert.ok(entries.every((entry) => entry.href));
    assert.ok(!entries.some((entry) => entry.label === 'Secondary Market'));
    const vat = entries.find(
        (entry) => entry.href === '/accounting/vat-return',
    );
    assert.equal(vat.section, 'Finance');
});

await test('a real section disappears when abilities hide all of its items, soon ones included', () => {
    const groupIds = (abilities) =>
        visibleNavigation(NAVIGATION, abilities).map((group) => group.id);
    assert.ok(
        !groupIds({
            accounting: false,
            procurement: false,
            pdc: false,
        }).includes('finance'),
    );
    assert.ok(
        !groupIds({
            crm: false,
            listings: false,
            deals: false,
            leasing: false,
            commission: false,
        }).includes('sales'),
    );
    assert.ok(
        !groupIds({ crm: false, accounting: false }).includes('marketing'),
    );
    assert.ok(groupIds({}).includes('finance'));
});
