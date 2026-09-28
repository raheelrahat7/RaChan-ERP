import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
).replace(/\s+/g, ' ');

const viteConfig = readFileSync(
    new URL('../../vite.config.ts', import.meta.url),
    'utf8',
).replace(/\s+/g, ' ');

await test('Arabic fonts download their Arabic subset, not only Latin', () => {
    for (const family of ['IBM Plex Sans Arabic', 'Noto Naskh Arabic']) {
        assert.match(
            viteConfig,
            new RegExp(
                `bunny\\('${family}', \\{[^}]*subsets: \\[[^\\]]*'arabic'`,
            ),
            `${family} must request the arabic subset`,
        );
    }
});

await test('right-to-left text is never letter-spaced, but left-to-right islands keep their tracking', () => {
    assert.match(
        css,
        /\[dir='rtl'\] :not\(\[dir='ltr'\], \[dir='ltr'\] \*\) \{ letter-spacing: normal !important; \}/,
    );
    assert.doesNotMatch(css, /\[dir='rtl'\] \*, /);
});
