import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

await test('every navigation icon name has a Lucide icon mapped', () => {
    const union = read('resources/js/lib/navigation.ts').match(
        /export type NavIcon =([^;]+);/,
    )[1];
    const names = [...union.matchAll(/'([A-Za-z]+)'/g)].map((m) => m[1]);
    const icons = read('resources/js/lib/nav-icons.ts');
    const missing = names.filter(
        (name) => !new RegExp(`\\b${name}: [A-Z]`).test(icons),
    );
    assert.deepEqual(missing, []);
});
