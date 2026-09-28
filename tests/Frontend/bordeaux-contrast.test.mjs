import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);

function block(selector) {
    const start = css.indexOf(`${selector} {`);
    assert.notEqual(start, -1, `${selector} block missing`);
    const body = css.slice(start, css.indexOf('}', start));
    return Object.fromEntries(
        [...body.matchAll(/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6});/g)].map(
            (match) => [match[1], match[2]],
        ),
    );
}

function luminance(hex) {
    const channels = [1, 3, 5]
        .map((index) => parseInt(hex.slice(index, index + 2), 16) / 255)
        .map((value) =>
            value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4,
        );
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrast(a, b) {
    const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (high + 0.05) / (low + 0.05);
}

const TEXT_PAIRS = [
    ['foreground', 'background'],
    ['foreground', 'card'],
    ['muted-foreground', 'background'],
    ['muted-foreground', 'card'],
    ['muted-foreground', 'surface-sunken'],
    ['primary-foreground', 'primary'],
    ['accent-text', 'background'],
    ['accent-text', 'card'],
    ['success', 'card'],
    ['info', 'card'],
    ['warning', 'card'],
    ['warning', 'surface-sunken'],
    ['destructive', 'card'],
    ['destructive', 'background'],
    ['destructive-foreground', 'destructive'],
    ['sidebar-foreground', 'sidebar-background'],
    ['sidebar-muted', 'sidebar-background'],
    ['sidebar-primary-foreground', 'sidebar-primary'],
];
const RING_PAIRS = [
    ['ring', 'background'],
    ['ring', 'card'],
];

for (const [theme, selector] of [
    ['light', ':root'],
    ['dark', '.dark'],
]) {
    await test(`${theme} theme text pairs meet WCAG AA 4.5:1`, () => {
        const tokens = block(selector);
        for (const [fg, bg] of TEXT_PAIRS) {
            assert.ok(
                tokens[fg] && tokens[bg],
                `${theme}: --${fg} or --${bg} is not a 6-digit hex`,
            );
            const ratio = contrast(tokens[fg], tokens[bg]);
            assert.ok(
                ratio >= 4.5,
                `${theme}: --${fg} on --${bg} is ${ratio.toFixed(2)}:1`,
            );
        }
    });

    await test(`${theme} theme focus ring meets 3:1`, () => {
        const tokens = block(selector);
        for (const [fg, bg] of RING_PAIRS) {
            assert.ok(
                tokens[fg] && tokens[bg],
                `${theme}: --${fg} or --${bg} is not a 6-digit hex`,
            );
            const ratio = contrast(tokens[fg], tokens[bg]);
            assert.ok(
                ratio >= 3,
                `${theme}: --${fg} on --${bg} is ${ratio.toFixed(2)}:1`,
            );
        }
    });
}
