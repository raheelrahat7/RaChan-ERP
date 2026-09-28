import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(
    new URL('../../resources/js/pages', import.meta.url),
);
const EXCLUDED =
    /\/(auth|settings|portal|public)\/|Welcome\.vue$|Styleguide\.vue$/;

function pages(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
            return pages(path);
        }

        return path.endsWith('.vue') ? [path] : [];
    });
}

function rootContainer(source) {
    const template = source.slice(source.indexOf('<template>'));

    return /class="([^"]*\bp-4\b[^"]*)"/.exec(template)?.[1] ?? null;
}

await test('every ERP page uses the same centred container width and padding', () => {
    const offenders = pages(root)
        .filter((path) => !EXCLUDED.test(path))
        .map((path) => [
            path.slice(root.length + 1),
            rootContainer(readFileSync(path, 'utf8')),
        ])
        .filter(
            ([, classes]) =>
                classes === null ||
                !/\bmx-auto\b/.test(classes) ||
                !/\bw-full\b/.test(classes) ||
                !/\bmax-w-7xl\b/.test(classes) ||
                /\bmax-w-(?!7xl)\w+/.test(classes) ||
                !/\bmd:p-6\b/.test(classes),
        );
    assert.deepEqual(offenders, []);
});

await test('nothing visible sits outside the page container', () => {
    const offenders = pages(root)
        .filter((path) => !EXCLUDED.test(path))
        .filter((path) => {
            const source = readFileSync(path, 'utf8');
            const template = source.slice(source.indexOf('<template>') + 10);
            const beforeRoot = template.slice(0, template.search(/<div\b/));

            return /<(?!Head\b|!--)[A-Za-z]/.test(beforeRoot);
        })
        .map((path) => path.slice(root.length + 1));
    assert.deepEqual(offenders, []);
});
