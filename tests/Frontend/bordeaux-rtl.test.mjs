import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
)
    .replace(/\s+/g, ' ')
    .replace(/\( /g, '(')
    .replace(/ \)/g, ')');

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

await test('raw form controls on existing pages share the design-system look', () => {
    // Plain element selectors, so they outrank Tailwind's `font: inherit` reset.
    assert.match(
        css,
        /(?:\}|\*\/) select, textarea, input:not\(\[type='checkbox'\], \[type='radio'\], \[type='file'\], \[type='range'\], \[type='color'\]\) \{ background-color: var\(--card\);[^}]*font-size: 0\.875rem;/,
    );
    assert.doesNotMatch(css, /:where\(select/);
});
