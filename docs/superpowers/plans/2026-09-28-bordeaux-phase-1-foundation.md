# Bordeaux Phase 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the Bordeaux design system in place: tokens, fonts, restyled and new primitives, the Z1 building blocks, and a local-only `/styleguide` page. Every later phase converts pages onto this foundation.

**Architecture:**

- Bordeaux values replace the stock shadcn-vue CSS variables in `resources/css/app.css`, keeping the same token names. All 74 existing pages restyle without edits.
- Formatting, status tones, navigation data, table state and chart geometry live as pure TypeScript in `resources/js/lib`, unit-tested with `node:test`.
- Vue building blocks in `resources/js/components` consume those modules.
- `/styleguide` renders everything for visual review.

**Tech Stack:** Laravel 13, Inertia 3, Vue 3.5 `<script setup lang="ts">`, Tailwind CSS 4 (`@theme inline`, `@utility`), shadcn-vue (new-york-v4) on reka-ui, `@lucide/vue`, `laravel-vite-plugin/fonts` (Bunny), `node:test` with `--experimental-strip-types`, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-28-bordeaux-frontend-redesign-design.md`. The visual references are `.superpowers/brainstorm/design-directions-v3.html` (Bordeaux) and `.superpowers/brainstorm/components-gallery.html`.

## Commands (this machine)

The host has no Node. PHP runs in the `z1erp-web` container, and Node runs in a throwaway `node:22` container. Both are covered by the owner's standing permissions: the test database only, and `docker run --rm` only. Run everything from `/Users/RR/Docker/z1-erp`.

- **node-run** = `docker run --rm -v "$PWD":/workspace -w /workspace node:22`
    - Example: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 npm run typecheck`
- **php-run** = `docker exec z1erp-web sh -lc 'cd /workspace && <command>'`
    - Example: `docker exec z1erp-web sh -lc 'cd /workspace && php artisan test --filter=StyleguideTest'`
- **Frontend unit test:** `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/<file>.test.mjs`
- **Build:** first run `docker exec z1erp-web sh -lc 'cd /workspace && php artisan wayfinder:generate --with-form'`, then `docker run --rm -v "$PWD":/workspace -w /workspace -e WAYFINDER_GENERATED=1 node:22 npm run build`
- **Lint and format check:** `docker run --rm -v "$PWD":/workspace -w /workspace node:22 npm run lint`. To auto-format, use `npm run check:fix` the same way.

Pure modules in `resources/js/lib` that are imported by `tests/Frontend/*.test.mjs` must not import Vue, `@/` aliases or other relative `.ts` files. Node strips types but does not resolve aliases. `import type` is fine.

## Global Constraints

- Frontend only, except the `/styleguide` route and controller. Do not change business logic, domain modules, migrations or data.
- No new npm dependencies. After any CLI run, `git diff --stat package.json package-lock.json` must be empty. If it isn't, stop and ask the owner.
- Keep shadcn-vue token names (`--primary`, `--muted-foreground`, `--sidebar-*`, …). Add new tokens alongside them.
- Colors (hex) as in the spec's §2.1 table. They are exactly the values in the Task 1 CSS. Readable text pairs must reach ≥ 4.5:1 and focus rings ≥ 3:1 in both themes.
- Fonts:
    - Interface: `'Geist', 'IBM Plex Sans Arabic'`
    - Display: `'Cormorant Garamond', 'Noto Naskh Arabic'`
- Numbers use Western digits in both languages. Money displays as `AED 212,000.00` in English and `212,000.00 د.إ` in Arabic. Missing values display `—`.
- Direction-dependent CSS uses logical utilities (`ms-/me-/ps-/pe-/start-/end-/text-start/text-end`). Never use `ml-/mr-/pl-/pr-/left-/right-/text-left/text-right` in new code.
- Every user-visible string in a building block passes through `t()` from `useLocale`. New English keys get an Arabic entry in `resources/js/locales/ar.json`. After editing that file, run `docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'`. Never overwrite an existing key's Arabic value.
- `resources/js/components/ui/*` is excluded from lint and format (see `vite.config.ts`). Keep its existing style: double quotes, no semicolons.
- Commit after each task. Use conventional messages ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never push.

## Review Focus

1. **Empty and bad numbers:** null, `''`, `'abc'`, `NaN` or `Infinity` must display `—`, never `NaN`, `AED NaN` or `-0.00`. Pinned in Task 2 tests.
2. **Compact boundaries:** `999999` must show `1M`, not `1000K`, and negative values keep their sign. Pinned in Task 2 tests.
3. **Date-only values in far timezones:** `'2026-09-01'` must show 1 Sep for a viewer in Honolulu or Dubai. Laravel `Y-m-d H:i:s` strings must parse, not show "Invalid Date". Pinned in Task 2 (the test runs under three `TZ` values).
4. **Statuses the map doesn't know:** a new backend value must render neutral with a readable label, and `null` must not crash. Every mapped status must have Arabic. Pinned in Task 3 tests.
5. **Flat or tiny chart series:** constant values, a single point or an empty array must produce valid SVG paths with no `NaN`. Pinned in Task 7 tests.

Also covered, in Task 4: navigation links pointing at routes that don't exist, and Arabic labels missing from the sidebar.

---

### Task 1: Bordeaux tokens, fonts and utilities

**Files:**

- Modify: `resources/css/app.css` (full replacement below)
- Modify: `vite.config.ts:14-18` (fonts array)
- Modify: `resources/views/app.blade.php:26-32` (inline background colors)
- Test: `tests/Frontend/bordeaux-contrast.test.mjs`

**Interfaces:**

- Produces Tailwind utilities used by every later task:
    - Colors: `bg-surface-sunken`, `text-faint`, `bg-faint`, `bg-champagne`, `text-champagne`, `text-accent-text`, `bg-success`, `text-success`, `bg-info`, `text-info`, `bg-warning`, `text-warning`, `bg-primary-hover`, `text-sidebar-muted`
    - Shadows: `shadow-panel`, `shadow-overlay`
    - Type: `font-display`, `text-eyebrow`, `text-label`
    - Radii: `rounded-sm` = 3px, `rounded-md` = 4px, `rounded-lg` = 6px, `rounded-xl` = 8px

- [ ] **Step 1: Write the failing contrast test**

Create `tests/Frontend/bordeaux-contrast.test.mjs`:

```js
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
            const ratio = contrast(tokens[fg], tokens[bg]);
            assert.ok(
                ratio >= 3,
                `${theme}: --${fg} on --${bg} is ${ratio.toFixed(2)}:1`,
            );
        }
    });
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/bordeaux-contrast.test.mjs`

Expected: FAIL. The current tokens use `hsl()` and have no `--surface-sunken`, so you get "is not a 6-digit hex".

- [ ] **Step 3: Replace `resources/css/app.css`**

```css
@import 'tailwindcss';

@import 'tw-animate-css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@custom-variant dark (&:is(.dark *));

@theme inline {
    --font-sans:
        'Geist', 'IBM Plex Sans Arabic', ui-sans-serif, system-ui, sans-serif,
        'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol',
        'Noto Color Emoji';
    --font-display:
        'Cormorant Garamond', 'Noto Naskh Arabic', ui-serif, Georgia, serif;

    --radius-sm: 3px;
    --radius-md: 4px;
    --radius-lg: var(--radius);
    --radius-xl: 8px;

    --shadow-panel: var(--elevation-panel);
    --shadow-overlay: var(--elevation-overlay);

    --color-background: var(--background);
    --color-foreground: var(--foreground);
    --color-surface-sunken: var(--surface-sunken);
    --color-faint: var(--faint);

    --color-card: var(--card);
    --color-card-foreground: var(--card-foreground);

    --color-popover: var(--popover);
    --color-popover-foreground: var(--popover-foreground);

    --color-primary: var(--primary);
    --color-primary-hover: var(--primary-hover);
    --color-primary-foreground: var(--primary-foreground);

    --color-secondary: var(--secondary);
    --color-secondary-foreground: var(--secondary-foreground);

    --color-muted: var(--muted);
    --color-muted-foreground: var(--muted-foreground);

    --color-accent: var(--accent);
    --color-accent-foreground: var(--accent-foreground);
    --color-accent-text: var(--accent-text);
    --color-champagne: var(--champagne);

    --color-success: var(--success);
    --color-info: var(--info);
    --color-warning: var(--warning);
    --color-destructive: var(--destructive);
    --color-destructive-foreground: var(--destructive-foreground);

    --color-border: var(--border);
    --color-input: var(--input);
    --color-ring: var(--ring);

    --color-chart-1: var(--chart-1);
    --color-chart-2: var(--chart-2);
    --color-chart-3: var(--chart-3);
    --color-chart-4: var(--chart-4);
    --color-chart-5: var(--chart-5);

    --color-sidebar: var(--sidebar-background);
    --color-sidebar-foreground: var(--sidebar-foreground);
    --color-sidebar-muted: var(--sidebar-muted);
    --color-sidebar-primary: var(--sidebar-primary);
    --color-sidebar-primary-foreground: var(--sidebar-primary-foreground);
    --color-sidebar-accent: var(--sidebar-accent);
    --color-sidebar-accent-foreground: var(--sidebar-accent-foreground);
    --color-sidebar-border: var(--sidebar-border);
    --color-sidebar-ring: var(--sidebar-ring);
}

:root {
    --radius: 6px;

    --background: #f8f4f1;
    --foreground: #1e1215;
    --surface-sunken: #f0eae6;
    --faint: #a6989b;
    --card: #fdfbfa;
    --card-foreground: #1e1215;
    --popover: #fdfbfa;
    --popover-foreground: #1e1215;
    --primary: #6b1b2a;
    --primary-hover: #58141f;
    --primary-foreground: #fbf6f3;
    --secondary: #f0eae6;
    --secondary-foreground: #1e1215;
    --muted: #f0eae6;
    --muted-foreground: #6f6064;
    --accent: #f2ece8;
    --accent-foreground: #1e1215;
    --accent-text: #7a2233;
    --champagne: #c9a27a;
    --success: #3e6e52;
    --info: #2f5e86;
    --warning: #8c5f18;
    --destructive: #b23a1a;
    --destructive-foreground: #ffffff;
    --border: rgba(60, 16, 24, 0.1);
    --input: rgba(60, 16, 24, 0.18);
    --ring: #9a7440;
    --chart-1: #7a2233;
    --chart-2: #b08d57;
    --chart-3: #5b6b7a;
    --chart-4: #6e8b74;
    --chart-5: #c27c8a;
    --elevation-panel:
        0 1px 0 rgba(60, 16, 24, 0.03), 0 18px 40px -28px rgba(60, 16, 24, 0.3);
    --elevation-overlay:
        0 1px 2px rgba(60, 16, 24, 0.06),
        0 40px 80px -30px rgba(30, 10, 14, 0.45);
    --sidebar-background: #4a1320;
    --sidebar: #4a1320;
    --sidebar-foreground: #f6ece8;
    --sidebar-muted: #c49ca3;
    --sidebar-primary: #c9a27a;
    --sidebar-primary-foreground: #4a1320;
    --sidebar-accent: rgba(255, 255, 255, 0.09);
    --sidebar-accent-foreground: #f6ece8;
    --sidebar-border: rgba(255, 255, 255, 0.1);
    --sidebar-ring: #c9a27a;
}

.dark {
    --background: #0e0a0b;
    --foreground: #f1e9e7;
    --surface-sunken: #150f11;
    --faint: #6a5c5f;
    --card: #181214;
    --card-foreground: #f1e9e7;
    --popover: #181214;
    --popover-foreground: #f1e9e7;
    --primary: #9e2f45;
    --primary-hover: #b03750;
    --primary-foreground: #fff4f1;
    --secondary: #221a1c;
    --secondary-foreground: #f1e9e7;
    --muted: #1d1618;
    --muted-foreground: #a8989b;
    --accent: #211a1c;
    --accent-foreground: #f1e9e7;
    --accent-text: #d9b892;
    --champagne: #d2ae86;
    --success: #8dc3a1;
    --info: #8db5dc;
    --warning: #ddb46e;
    --destructive: #f08a70;
    --destructive-foreground: #1e1215;
    --border: rgba(245, 225, 228, 0.09);
    --input: rgba(245, 225, 228, 0.16);
    --ring: #d2ae86;
    --chart-1: #c75a6e;
    --chart-2: #d2ae86;
    --chart-3: #9aa8b6;
    --chart-4: #9dbba3;
    --chart-5: #e3a5b1;
    --elevation-panel: 0 24px 48px -28px rgba(0, 0, 0, 0.9);
    --elevation-overlay:
        0 0 0 1px rgba(255, 255, 255, 0.03),
        0 40px 80px -30px rgba(0, 0, 0, 0.85);
    --sidebar-background: #140c0e;
    --sidebar: #140c0e;
    --sidebar-foreground: #f1e9e7;
    --sidebar-muted: #9a878b;
    --sidebar-primary: #d2ae86;
    --sidebar-primary-foreground: #140c0e;
    --sidebar-accent: rgba(255, 255, 255, 0.05);
    --sidebar-accent-foreground: #f1e9e7;
    --sidebar-border: rgba(255, 255, 255, 0.06);
    --sidebar-ring: #d2ae86;
}

@utility text-eyebrow {
    font-size: 0.65625rem;
    line-height: 1rem;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--accent-text);
}

@utility text-label {
    font-size: 0.65625rem;
    line-height: 1rem;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

@layer base {
    * {
        @apply border-border outline-ring/50;
    }

    body {
        @apply bg-background text-foreground font-sans antialiased;
    }

    [dir='rtl'] body {
        line-height: 1.65;
    }

    ::selection {
        background-color: color-mix(in srgb, var(--champagne) 35%, transparent);
    }

    @media (prefers-reduced-motion: reduce) {
        *,
        ::before,
        ::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }
}

@layer utilities {
    [dir='rtl'] .text-eyebrow,
    [dir='rtl'] .text-label {
        font-size: 0.75rem;
        letter-spacing: 0;
        text-transform: none;
    }
}
```

- [ ] **Step 4: Run the contrast test and confirm it passes**

Run: the same command as Step 2.

Expected: PASS, 4 tests. If a pair fails, fix the token and re-run. Never lower the threshold.

- [ ] **Step 5: Confirm the four fonts exist on Bunny Fonts**

Run:

```bash
for f in geist cormorant-garamond ibm-plex-sans-arabic noto-naskh-arabic; do printf "%s " $f; curl -s "https://fonts.bunny.net/css?family=$f:500" | grep -c "@font-face"; done
```

Expected: each line prints a number > 0. If `geist` prints 0, stop and ask the owner. The fallback would be `Inter`, which changes the approved design.

- [ ] **Step 6: Load the fonts in `vite.config.ts`**

Replace the `fonts: [ … ]` array inside `laravel({ … })` with:

```ts
            fonts: [
                bunny('Geist', { weights: [300, 400, 500, 600] }),
                bunny('Cormorant Garamond', { weights: [500, 600] }),
                bunny('IBM Plex Sans Arabic', { weights: [400, 500, 600] }),
                bunny('Noto Naskh Arabic', { weights: [500, 600] }),
            ],
```

- [ ] **Step 7: Match the first paint in `resources/views/app.blade.php`**

Replace the inline `<style>` block:

```html
<style>
    html {
        background-color: #f8f4f1;
    }

    html.dark {
        background-color: #0e0a0b;
    }
</style>
```

- [ ] **Step 8: Build to prove the CSS and fonts compile**

Run the **Build** commands from the Commands section.

Expected: `✓ built in …` with no errors. `public/build/manifest.json` exists.

- [ ] **Step 9: Commit**

```bash
git add resources/css/app.css vite.config.ts resources/views/app.blade.php tests/Frontend/bordeaux-contrast.test.mjs
git commit -m "feat(ui): add Bordeaux design tokens, fonts and type utilities

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Locale-aware formatting (`lib/format.ts`, `useFormat`, `Money`, `DateText`)

**Files:**

- Create: `resources/js/lib/format.ts`
- Create: `resources/js/composables/useFormat.ts`
- Create: `resources/js/components/Money.vue`
- Create: `resources/js/components/DateText.vue`
- Test: `tests/Frontend/format.test.mjs`

**Interfaces:**

- Produces (`@/lib/format`):
    - `type AppLocale = 'en' | 'ar'`
    - `type NumericInput = number | string | null | undefined`
    - `EMPTY_VALUE = '—'`
    - `toNumber(value: NumericInput): number | null`
    - `formatNumber(value: NumericInput, decimals = 0): string`
    - `formatCompact(value: NumericInput, locale: AppLocale = 'en'): string`
    - `currencySymbol(currency: string, locale: AppLocale): string`
    - `formatMoney(value: NumericInput, currency = 'AED', locale: AppLocale = 'en', options: { compact?: boolean; decimals?: number } = {}): string`
    - `formatDate(value: string | Date | null | undefined, locale: AppLocale = 'en', options: { withTime?: boolean; timeZone?: string } = {}): string`
- Produces (`@/composables/useFormat`): `useFormat()` returns `{ money(value, currency?, options?), compact(value), number(value, decimals?), date(value, options?) }`, bound to the current locale.
- Produces components:
    - `<Money :value currency? compact? decimals? />`
    - `<DateText :value with-time? />`

- [ ] **Step 1: Write the failing tests**

Create `tests/Frontend/format.test.mjs`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    formatCompact,
    formatDate,
    formatMoney,
    formatNumber,
    toNumber,
} from '../../resources/js/lib/format.ts';

await test('money puts the code first in English and the Arabic symbol last', () => {
    assert.equal(formatMoney(212000, 'AED', 'en'), 'AED 212,000.00');
    assert.equal(formatMoney('96500.5', 'AED', 'ar'), '96,500.50 د.إ');
    assert.equal(formatMoney(1500, 'SAR', 'ar'), '1,500.00 ر.س');
    assert.equal(formatMoney(1500, 'USD', 'ar'), '1,500.00 USD');
    assert.equal(formatMoney(-1500, 'AED', 'en'), 'AED -1,500.00');
    assert.equal(formatMoney(1500, 'AED', 'en', { decimals: 0 }), 'AED 1,500');
});

await test('compact figures round cleanly across unit boundaries', () => {
    assert.equal(formatCompact(2418500), '2.42M');
    assert.equal(formatCompact(186200), '186K');
    assert.equal(formatCompact(999999), '1M');
    assert.equal(formatCompact(999_999_999), '1B');
    assert.equal(formatCompact(-1500000), '-1.5M');
    assert.equal(formatCompact(950), '950');
    assert.equal(formatCompact(2418500, 'ar'), '2.42 مليون');
    assert.equal(
        formatMoney(2418500, 'AED', 'ar', { compact: true }),
        '2.42 مليون د.إ',
    );
    assert.equal(
        formatMoney(2418500, 'AED', 'en', { compact: true }),
        'AED 2.42M',
    );
});

await test('missing or invalid values render an em dash, never NaN or -0', () => {
    for (const value of [
        null,
        undefined,
        '',
        '   ',
        'abc',
        Number.NaN,
        Number.POSITIVE_INFINITY,
    ]) {
        assert.equal(formatMoney(value), '—');
        assert.equal(formatNumber(value), '—');
        assert.equal(formatCompact(value), '—');
    }
    assert.equal(toNumber('0'), 0);
    assert.equal(formatNumber(-0.001, 2), '0.00');
    assert.equal(formatMoney(-0.001), 'AED 0.00');
});

await test('dates use Western digits and a fixed day month year order', () => {
    assert.equal(formatDate('2026-09-14', 'en'), '14 Sep 2026');
    assert.equal(formatDate('2026-09-14', 'ar'), '14 سبتمبر 2026');
    assert.equal(
        formatDate('2026-09-14T09:40:00Z', 'en', {
            withTime: true,
            timeZone: 'UTC',
        }),
        '14 Sep 2026, 09:40',
    );
    assert.equal(
        formatDate('2026-09-14T09:40:00Z', 'ar', {
            withTime: true,
            timeZone: 'UTC',
        }),
        '14 سبتمبر 2026، 09:40',
    );
    assert.equal(
        formatDate('2026-09-14 09:40:00', 'en', {
            withTime: true,
            timeZone: 'UTC',
        }),
        '14 Sep 2026, 09:40',
    );
    assert.equal(
        formatDate('2026-09-14', 'en', { withTime: true }),
        '14 Sep 2026',
    );
    assert.equal(formatDate('not a date'), '—');
    assert.equal(formatDate(null), '—');
    assert.equal(formatDate(''), '—');
});

await test('date-only values never shift a day in the viewer timezone', () => {
    assert.equal(formatDate('2026-09-01', 'en'), '1 Sep 2026');
    assert.equal(formatDate('2026-12-31', 'en'), '31 Dec 2026');
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/format.test.mjs`

Expected: FAIL with `ERR_MODULE_NOT_FOUND` for `resources/js/lib/format.ts`.

- [ ] **Step 3: Implement `resources/js/lib/format.ts`**

```ts
export type AppLocale = 'en' | 'ar';

export type NumericInput = number | string | null | undefined;

export type MoneyOptions = { compact?: boolean; decimals?: number };

export type DateOptions = { withTime?: boolean; timeZone?: string };

export const EMPTY_VALUE = '—';

const ARABIC_CURRENCY_SYMBOLS: Record<string, string> = {
    AED: 'د.إ',
    SAR: 'ر.س',
    QAR: 'ر.ق',
    KWD: 'د.ك',
    BHD: 'د.ب',
    OMR: 'ر.ع',
};

const COMPACT_UNITS: Record<AppLocale, [number, string][]> = {
    en: [
        [1e3, 'K'],
        [1e6, 'M'],
        [1e9, 'B'],
    ],
    ar: [
        [1e3, ' ألف'],
        [1e6, ' مليون'],
        [1e9, ' مليار'],
    ],
};

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
const SQL_DATETIME = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}(?::\d{2})?)$/;

export function toNumber(value: NumericInput): number | null {
    if (value === null || value === undefined) {
        return null;
    }
    if (typeof value === 'string' && value.trim() === '') {
        return null;
    }
    const number = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(number) ? number : null;
}

export function formatNumber(value: NumericInput, decimals = 0): string {
    const number = toNumber(value);
    if (number === null) {
        return EMPTY_VALUE;
    }
    const rounded = Number(number.toFixed(decimals)) || 0;

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(rounded);
}

function trimZeros(text: string): string {
    return text.includes('.') ? text.replace(/\.?0+$/, '') : text;
}

function compactDigits(scaled: number): string {
    const size = Math.abs(scaled);
    const digits = size >= 100 ? 0 : size >= 10 ? 1 : 2;

    return trimZeros(scaled.toFixed(digits));
}

export function formatCompact(
    value: NumericInput,
    locale: AppLocale = 'en',
): string {
    const number = toNumber(value);
    if (number === null) {
        return EMPTY_VALUE;
    }
    const units = COMPACT_UNITS[locale];
    let index = -1;
    for (let i = units.length - 1; i >= 0; i--) {
        if (Math.abs(number) >= units[i][0]) {
            index = i;
            break;
        }
    }
    if (index === -1) {
        return formatNumber(number, Number.isInteger(number) ? 0 : 2);
    }
    let text = compactDigits(number / units[index][0]);
    if (Math.abs(Number(text)) >= 1000 && index < units.length - 1) {
        index += 1;
        text = compactDigits(number / units[index][0]);
    }

    return `${text}${units[index][1]}`;
}

export function currencySymbol(currency: string, locale: AppLocale): string {
    return locale === 'ar'
        ? (ARABIC_CURRENCY_SYMBOLS[currency] ?? currency)
        : currency;
}

export function formatMoney(
    value: NumericInput,
    currency = 'AED',
    locale: AppLocale = 'en',
    options: MoneyOptions = {},
): string {
    const number = toNumber(value);
    if (number === null) {
        return EMPTY_VALUE;
    }
    const amount = options.compact
        ? formatCompact(number, locale)
        : formatNumber(number, options.decimals ?? 2);
    const symbol = currencySymbol(currency, locale);

    return locale === 'ar' ? `${amount} ${symbol}` : `${symbol} ${amount}`;
}

function parts(
    date: Date,
    tag: string,
    options: Intl.DateTimeFormatOptions,
): Record<string, string> {
    return Object.fromEntries(
        new Intl.DateTimeFormat(tag, options)
            .formatToParts(date)
            .map((part) => [part.type, part.value]),
    );
}

export function formatDate(
    value: string | Date | null | undefined,
    locale: AppLocale = 'en',
    options: DateOptions = {},
): string {
    if (value === null || value === undefined || value === '') {
        return EMPTY_VALUE;
    }
    const dateOnly = typeof value === 'string' && DATE_ONLY.test(value);
    let date: Date;
    if (typeof value === 'string') {
        const sql = SQL_DATETIME.exec(value);
        const iso = dateOnly
            ? `${value}T00:00:00Z`
            : sql
              ? `${sql[1]}T${sql[2]}Z`
              : value;
        date = new Date(iso);
    } else {
        date = value;
    }
    if (Number.isNaN(date.getTime())) {
        return EMPTY_VALUE;
    }
    const timeZone = dateOnly ? 'UTC' : options.timeZone;
    const day = parts(date, locale === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US', {
        day: 'numeric',
        month: locale === 'ar' ? 'long' : 'short',
        year: 'numeric',
        timeZone,
    });
    let text = `${day.day} ${day.month} ${day.year}`;
    if (options.withTime && !dateOnly) {
        const time = parts(date, 'en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZone,
        });
        text += `${locale === 'ar' ? '،' : ','} ${time.hour}:${time.minute}`;
    }

    return text;
}
```

- [ ] **Step 4: Run the tests in three timezones and confirm they pass**

Run each of these; each must PASS all 5 tests:

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/format.test.mjs
docker run --rm -e TZ=Pacific/Honolulu -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/format.test.mjs
docker run --rm -e TZ=Asia/Dubai -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/format.test.mjs
```

If the Arabic month assertion fails because ICU produced a different month string, print it with `node -e "console.log(new Intl.DateTimeFormat('ar-AE-u-nu-latn',{month:'long',timeZone:'UTC'}).format(new Date('2026-09-14T00:00:00Z')))"`. Only update the expectation if it is still the standard Gulf month name (سبتمبر).

- [ ] **Step 5: Create `resources/js/composables/useFormat.ts`**

```ts
import { useLocale } from '@/composables/useLocale';
import {
    formatCompact,
    formatDate,
    formatMoney,
    formatNumber,
} from '@/lib/format';
import type { DateOptions, MoneyOptions, NumericInput } from '@/lib/format';

export function useFormat() {
    const { locale } = useLocale();

    return {
        money: (
            value: NumericInput,
            currency = 'AED',
            options: MoneyOptions = {},
        ): string => formatMoney(value, currency, locale.value, options),
        compact: (value: NumericInput): string =>
            formatCompact(value, locale.value),
        number: (value: NumericInput, decimals = 0): string =>
            formatNumber(value, decimals),
        date: (
            value: string | Date | null | undefined,
            options: DateOptions = {},
        ): string => formatDate(value, locale.value, options),
    };
}
```

- [ ] **Step 6: Create `resources/js/components/Money.vue`**

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import type { NumericInput } from '@/lib/format';

const props = withDefaults(
    defineProps<{
        value: NumericInput;
        currency?: string;
        compact?: boolean;
        decimals?: number;
    }>(),
    { currency: 'AED', compact: false, decimals: 2 },
);

const { money } = useFormat();
const text = computed(() =>
    money(props.value, props.currency, {
        compact: props.compact,
        decimals: props.decimals,
    }),
);
</script>

<template>
    <span class="whitespace-nowrap tabular-nums lining-nums">{{ text }}</span>
</template>
```

- [ ] **Step 7: Create `resources/js/components/DateText.vue`**

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';

const props = withDefaults(
    defineProps<{
        value: string | null | undefined;
        withTime?: boolean;
    }>(),
    { withTime: false },
);

const { date } = useFormat();
const text = computed(() => date(props.value, { withTime: props.withTime }));
</script>

<template>
    <time
        :datetime="value ?? undefined"
        class="whitespace-nowrap tabular-nums"
        >{{ text }}</time
    >
</template>
```

- [ ] **Step 8: Typecheck and lint**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -c "npm run typecheck && npm run lint"`

Expected: both exit 0. If the formatter complains, run `npm run check:fix` the same way and re-run.

- [ ] **Step 9: Commit**

```bash
git add resources/js/lib/format.ts resources/js/composables/useFormat.ts resources/js/components/Money.vue resources/js/components/DateText.vue tests/Frontend/format.test.mjs
git commit -m "feat(ui): add locale-aware money and date formatting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Shared status language (`lib/status-tones.ts`, `StatusDot`)

**Files:**

- Create: `resources/js/lib/status-tones.ts`
- Create: `resources/js/components/StatusDot.vue`
- Modify: `resources/js/locales/ar.json` (add missing status labels), then run the sync script (updates `lang/ar.json`)
- Test: `tests/Frontend/status-tones.test.mjs`

**Interfaces:**

- Produces (`@/lib/status-tones`):
    - `type StatusTone = 'success' | 'info' | 'warning' | 'danger' | 'neutral'`
    - `STATUS_TONES: Record<string, StatusTone>`
    - `STATUS_TONE_CLASSES: Record<StatusTone, string>`
    - `normalizeStatus(value: string): string`
    - `statusTone(value: string | null | undefined): StatusTone`
    - `statusLabel(value: string | null | undefined): string`
    - `isStruck(value: string | null | undefined): boolean`
- Produces the component `<StatusDot :status label? tone? />`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Frontend/status-tones.test.mjs`:

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    STATUS_TONES,
    isStruck,
    statusLabel,
    statusTone,
} from '../../resources/js/lib/status-tones.ts';

const arabic = JSON.parse(
    readFileSync(
        new URL('../../resources/js/locales/ar.json', import.meta.url),
        'utf8',
    ),
);

await test('known statuses map to a tone regardless of case, spaces or dashes', () => {
    assert.equal(statusTone('paid'), 'success');
    assert.equal(statusTone('Partially paid'), 'info');
    assert.equal(statusTone('ON-HOLD'), 'warning');
    assert.equal(statusTone('overdue'), 'danger');
    assert.equal(statusTone('draft'), 'neutral');
});

await test('unknown, empty and null statuses fall back to neutral with a readable label', () => {
    assert.equal(statusTone('brand_new_backend_state'), 'neutral');
    assert.equal(
        statusLabel('brand_new_backend_state'),
        'Brand new backend state',
    );
    assert.equal(statusTone(null), 'neutral');
    assert.equal(statusTone(undefined), 'neutral');
    assert.equal(statusLabel(null), '');
    assert.equal(statusLabel(''), '');
});

await test('void statuses are struck through and others are not', () => {
    assert.equal(isStruck('void'), true);
    assert.equal(isStruck('Voided'), true);
    assert.equal(isStruck('cancelled'), false);
    assert.equal(isStruck(null), false);
});

await test('every mapped status has an Arabic label', () => {
    const missing = Object.keys(STATUS_TONES)
        .map((status) => statusLabel(status))
        .filter((label) => !arabic[label]);
    assert.deepEqual(missing, []);
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/status-tones.test.mjs`

Expected: FAIL, module not found.

- [ ] **Step 3: Implement `resources/js/lib/status-tones.ts`**

```ts
export type StatusTone = 'success' | 'info' | 'warning' | 'danger' | 'neutral';

export const STATUS_TONES: Record<string, StatusTone> = {
    paid: 'success',
    posted: 'success',
    completed: 'success',
    approved: 'success',
    active: 'success',
    won: 'success',
    converted: 'success',
    received: 'success',
    deposited: 'success',
    reconciled: 'success',
    matched: 'success',
    signed: 'success',
    resolved: 'success',
    filed: 'success',
    cleared: 'success',
    available: 'success',
    sold: 'success',
    in_progress: 'info',
    partially_paid: 'info',
    submitted: 'info',
    scheduled: 'info',
    issued: 'info',
    sent: 'info',
    open: 'info',
    planned: 'info',
    prepared: 'info',
    local_prepared: 'info',
    registered: 'info',
    ready: 'info',
    reserved: 'info',
    occupied: 'info',
    new: 'info',
    pending: 'warning',
    on_hold: 'warning',
    attention: 'warning',
    unmatched: 'warning',
    queued: 'warning',
    overdue: 'danger',
    failed: 'danger',
    rejected: 'danger',
    bounced: 'danger',
    lost: 'danger',
    expired: 'danger',
    breached: 'danger',
    terminated: 'danger',
    in_arrears: 'danger',
    draft: 'neutral',
    closed: 'neutral',
    cancelled: 'neutral',
    void: 'neutral',
    voided: 'neutral',
    inactive: 'neutral',
    archived: 'neutral',
    vacant: 'neutral',
};

export const STATUS_TONE_CLASSES: Record<StatusTone, string> = {
    success: 'bg-success',
    info: 'bg-info',
    warning: 'bg-warning',
    danger: 'bg-destructive',
    neutral: 'bg-faint',
};

const STRUCK = new Set(['void', 'voided']);

export function normalizeStatus(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');
}

export function statusTone(value: string | null | undefined): StatusTone {
    if (!value) {
        return 'neutral';
    }

    return STATUS_TONES[normalizeStatus(value)] ?? 'neutral';
}

export function statusLabel(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const words = normalizeStatus(value).replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function isStruck(value: string | null | undefined): boolean {
    return value ? STRUCK.has(normalizeStatus(value)) : false;
}
```

- [ ] **Step 4: Run the tests. Expect only the Arabic test to fail**

Run: the same command as Step 2.

Expected: 3 PASS. `every mapped status has an Arabic label` FAILS and lists the labels that are missing.

- [ ] **Step 5: Add the missing Arabic labels to `resources/js/locales/ar.json`**

Add only the keys the failure listed, and never change an existing key. Use these values:

```json
"Paid": "مدفوع", "Posted": "مرحل", "Completed": "مكتمل", "Approved": "معتمد", "Active": "نشط",
"Won": "مكسوب", "Converted": "محوّل", "Received": "مستلم", "Deposited": "مودع", "Reconciled": "تمت المطابقة",
"Matched": "مطابق", "Signed": "موقّع", "Resolved": "تم الحل", "Filed": "تم التقديم", "Cleared": "محصّل",
"Available": "متاح", "Sold": "مباع", "In progress": "قيد التنفيذ", "Partially paid": "مدفوع جزئيًا",
"Submitted": "مقدَّم", "Scheduled": "مجدول", "Issued": "صادر", "Sent": "مرسل", "Open": "مفتوح",
"Planned": "مخطط", "Prepared": "معَدّ", "Local prepared": "معَدّ محليًا", "Registered": "مسجل", "Ready": "جاهز",
"Reserved": "محجوز", "Occupied": "مشغول", "New": "جديد", "Pending": "قيد الانتظار", "On hold": "معلق",
"Attention": "يتطلب انتباهًا", "Unmatched": "غير مطابق", "Queued": "في قائمة الانتظار", "Overdue": "متأخر",
"Failed": "فشل", "Rejected": "مرفوض", "Bounced": "مرتجع", "Lost": "خاسر", "Expired": "منتهي",
"Breached": "متجاوز", "Terminated": "مُنهى", "In arrears": "متأخرات", "Draft": "مسودة", "Closed": "مغلق",
"Cancelled": "ملغى", "Void": "ملغاة", "Voided": "ملغاة", "Inactive": "غير نشط", "Archived": "مؤرشف",
"Vacant": "شاغر"
```

Keep the file valid JSON, matching its existing formatting (one key per line). Then run the sync script:

```bash
docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'
```

- [ ] **Step 6: Run the tests and confirm all four pass**

Run: the same command as Step 2. Expected: 4 PASS.

- [ ] **Step 7: Create `resources/js/components/StatusDot.vue`**

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import {
    STATUS_TONE_CLASSES,
    isStruck,
    statusLabel,
    statusTone,
} from '@/lib/status-tones';
import type { StatusTone } from '@/lib/status-tones';
import { cn } from '@/lib/utils';

const props = defineProps<{
    status: string | null | undefined;
    label?: string;
    tone?: StatusTone;
}>();

const { t } = useLocale();
const resolvedTone = computed(() => props.tone ?? statusTone(props.status));
const text = computed(() => props.label ?? t(statusLabel(props.status)));
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-2 text-xs whitespace-nowrap',
                isStruck(status) && 'text-faint line-through',
            )
        "
    >
        <span
            aria-hidden="true"
            :class="
                cn(
                    'size-1.5 shrink-0 rounded-full',
                    STATUS_TONE_CLASSES[resolvedTone],
                )
            "
        />
        {{ text }}
    </span>
</template>
```

- [ ] **Step 8: Typecheck, lint and run the locale PHP test**

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -c "npm run typecheck && npm run lint"
docker exec z1erp-web sh -lc 'cd /workspace && php artisan test tests/Feature/LocaleTest.php'
```

Expected: all exit 0.

- [ ] **Step 9: Commit**

```bash
git add resources/js/lib/status-tones.ts resources/js/components/StatusDot.vue resources/js/locales/ar.json lang/ar.json tests/Frontend/status-tones.test.mjs
git commit -m "feat(ui): add shared status tones and StatusDot

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Navigation config (`lib/navigation.ts`)

**Files:**

- Create: `resources/js/lib/navigation.ts`
- Modify: `resources/js/locales/ar.json` (missing navigation labels), then run the sync script (updates `lang/ar.json`)
- Test: `tests/Frontend/navigation.test.mjs`

**Interfaces:**

- Produces (`@/lib/navigation`):
    - `type NavIcon` (string union, listed below)
    - `type NavChild = { label: string; href: string }`
    - `type NavLink = { label: string; href: string; icon: NavIcon; children?: NavChild[] }`
    - `type NavGroup = { id: string; label: string; items: NavLink[]; placement?: 'bottom' }`
    - `NAVIGATION: NavGroup[]`
    - `navHrefs(): string[]`
    - `activeHref(url: string): string | null` (the longest nav href that matches the path)
    - `activeGroupId(url: string): string | null`
- Phase 2 renders this in `AppSidebar.vue` and maps each `NavIcon` to a Lucide component. Phase 1 does not change the sidebar.

- [ ] **Step 1: Write the failing tests**

Create `tests/Frontend/navigation.test.mjs`:

```js
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
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/navigation.test.mjs`

Expected: FAIL, module not found.

- [ ] **Step 3: Implement `resources/js/lib/navigation.ts`**

```ts
export type NavIcon =
    | 'dashboard'
    | 'search'
    | 'notifications'
    | 'leads'
    | 'contacts'
    | 'pipelines'
    | 'report'
    | 'assignment'
    | 'hierarchy'
    | 'inventory'
    | 'listings'
    | 'people'
    | 'brokerage'
    | 'reservations'
    | 'agreements'
    | 'handovers'
    | 'compliance'
    | 'invoices'
    | 'bills'
    | 'refunds'
    | 'bank'
    | 'accounting'
    | 'performance'
    | 'statements'
    | 'operations'
    | 'maintenance'
    | 'preventive'
    | 'helpdesk'
    | 'amc'
    | 'parts'
    | 'projects'
    | 'fleet'
    | 'reports'
    | 'scheduled'
    | 'documents'
    | 'signatures'
    | 'procurement'
    | 'organization'
    | 'activity'
    | 'portal'
    | 'tokens'
    | 'settings';

export type NavChild = { label: string; href: string };

export type NavLink = {
    label: string;
    href: string;
    icon: NavIcon;
    children?: NavChild[];
};

export type NavGroup = {
    id: string;
    label: string;
    items: NavLink[];
    placement?: 'bottom';
};

export const NAVIGATION: NavGroup[] = [
    {
        id: 'overview',
        label: 'Overview',
        items: [
            { label: 'Dashboard', href: '/dashboard', icon: 'dashboard' },
            { label: 'Search', href: '/search', icon: 'search' },
            {
                label: 'Notifications',
                href: '/notifications',
                icon: 'notifications',
            },
        ],
    },
    {
        id: 'crm',
        label: 'CRM',
        items: [
            { label: 'Leads', href: '/crm/leads', icon: 'leads' },
            { label: 'Contacts', href: '/crm/contacts', icon: 'contacts' },
            { label: 'Pipelines', href: '/crm/pipelines', icon: 'pipelines' },
            {
                label: 'Pipeline report',
                href: '/crm/pipeline-report',
                icon: 'report',
            },
            {
                label: 'Assignment',
                href: '/crm/assignment',
                icon: 'assignment',
            },
            { label: 'Hierarchy', href: '/crm/hierarchy', icon: 'hierarchy' },
        ],
    },
    {
        id: 'portfolio',
        label: 'Portfolio',
        items: [
            { label: 'Inventory', href: '/inventory', icon: 'inventory' },
            {
                label: 'Listings',
                href: '/real-estate/listings',
                icon: 'listings',
            },
            {
                label: 'Owners, tenants & brokers',
                href: '/real-estate/people',
                icon: 'people',
            },
            {
                label: 'Brokerage',
                href: '/real-estate/brokerage',
                icon: 'brokerage',
            },
        ],
    },
    {
        id: 'leasing',
        label: 'Leasing & sales',
        items: [
            {
                label: 'Reservations',
                href: '/reservations',
                icon: 'reservations',
            },
            { label: 'Agreements', href: '/agreements', icon: 'agreements' },
            { label: 'Handovers', href: '/handovers', icon: 'handovers' },
            {
                label: 'Lease compliance',
                href: '/lease-compliance',
                icon: 'compliance',
            },
        ],
    },
    {
        id: 'finance',
        label: 'Finance',
        items: [
            { label: 'Invoices', href: '/invoices', icon: 'invoices' },
            { label: 'Vendor bills', href: '/vendor-bills', icon: 'bills' },
            {
                label: 'Vendor refunds',
                href: '/finance/vendor-cash-refunds',
                icon: 'refunds',
            },
            {
                label: 'Bank reconciliation',
                href: '/bank-reconciliation',
                icon: 'bank',
            },
            {
                label: 'Accounting',
                href: '/accounting',
                icon: 'accounting',
                children: [
                    { label: 'Accounting overview', href: '/accounting' },
                    {
                        label: 'Journal register',
                        href: '/accounting/journal-register',
                    },
                    {
                        label: 'Financial statements',
                        href: '/accounting/statements',
                    },
                    { label: 'VAT return', href: '/accounting/vat-return' },
                    {
                        label: 'Corporate tax',
                        href: '/accounting/corporate-tax',
                    },
                    { label: 'Budgets', href: '/accounting/budgets' },
                    { label: 'Fixed assets', href: '/accounting/fixed-assets' },
                    {
                        label: 'Outstanding balances',
                        href: '/accounting/outstanding-balances',
                    },
                    { label: 'Account activity', href: '/accounting/activity' },
                    { label: 'Audit trail', href: '/accounting/audit-trail' },
                    {
                        label: 'Legacy mappings',
                        href: '/accounting/legacy-mappings',
                    },
                ],
            },
            {
                label: 'Property performance',
                href: '/reports/property-profitability',
                icon: 'performance',
            },
            {
                label: 'Owner statements',
                href: '/reports/owner-statements',
                icon: 'statements',
            },
        ],
    },
    {
        id: 'operations',
        label: 'Operations',
        items: [
            {
                label: 'Operations overview',
                href: '/operations',
                icon: 'operations',
            },
            { label: 'Maintenance', href: '/maintenance', icon: 'maintenance' },
            {
                label: 'Preventive maintenance',
                href: '/preventive-maintenance',
                icon: 'preventive',
            },
            {
                label: 'Helpdesk',
                href: '/operations/helpdesk',
                icon: 'helpdesk',
            },
            { label: 'AMC contracts', href: '/operations/amc', icon: 'amc' },
            {
                label: 'Spare parts',
                href: '/operations/spare-parts',
                icon: 'parts',
            },
            {
                label: 'Projects',
                href: '/operations/projects',
                icon: 'projects',
            },
            { label: 'Fleet', href: '/operations/fleet', icon: 'fleet' },
            {
                label: 'Operations reports',
                href: '/operations/reports',
                icon: 'reports',
            },
            {
                label: 'Scheduled reports',
                href: '/operations/scheduled-reports',
                icon: 'scheduled',
            },
            {
                label: 'Compliance documents',
                href: '/compliance-documents',
                icon: 'documents',
            },
            {
                label: 'Signatures',
                href: '/documents/signatures',
                icon: 'signatures',
            },
        ],
    },
    {
        id: 'procurement',
        label: 'Procurement',
        items: [
            { label: 'Procurement', href: '/procurement', icon: 'procurement' },
        ],
    },
    {
        id: 'administration',
        label: 'Administration',
        placement: 'bottom',
        items: [
            {
                label: 'Organization',
                href: '/organization',
                icon: 'organization',
            },
            {
                label: 'Organization activity',
                href: '/organization/activity',
                icon: 'activity',
            },
            {
                label: 'Portal access',
                href: '/organization/portal-access',
                icon: 'portal',
            },
            {
                label: 'API tokens',
                href: '/organization/api-tokens',
                icon: 'tokens',
            },
            { label: 'Settings', href: '/settings/profile', icon: 'settings' },
        ],
    },
];

export function navHrefs(): string[] {
    return NAVIGATION.flatMap((group) =>
        group.items.flatMap((item) =>
            item.children
                ? item.children.map((child) => child.href)
                : [item.href],
        ),
    );
}

function pathOf(url: string): string {
    const path = url.split(/[?#]/)[0].replace(/\/+$/, '');

    return path === '' ? '/' : path;
}

function matches(href: string, path: string): boolean {
    return path === href || path.startsWith(`${href}/`);
}

export function activeHref(url: string): string | null {
    const path = pathOf(url);
    let best: string | null = null;
    for (const href of navHrefs()) {
        if (
            matches(href, path) &&
            (best === null || href.length > best.length)
        ) {
            best = href;
        }
    }

    return best;
}

export function activeGroupId(url: string): string | null {
    const href = activeHref(url);
    if (href === null) {
        return null;
    }
    const group = NAVIGATION.find((candidate) =>
        candidate.items.some(
            (item) =>
                item.href === href ||
                (item.children ?? []).some((child) => child.href === href),
        ),
    );

    return group?.id ?? null;
}
```

- [ ] **Step 4: Run the tests. Only the Arabic test should fail**

Run: the same command as Step 2.

Expected: the route, duplicate, group and active-link tests PASS. If the route test lists a path, check `routes/web.php` for the real path and fix the href. Do not add routes. The Arabic test fails and lists the missing labels.

- [ ] **Step 5: Add the missing navigation labels to `resources/js/locales/ar.json`**

Add only the missing keys; never overwrite an existing one. Then run the sync script. Values:

```json
"Overview": "نظرة عامة", "Dashboard": "لوحة التحكم", "Search": "البحث", "Notifications": "الإشعارات",
"CRM": "إدارة علاقات العملاء", "Leads": "العملاء المحتملون", "Contacts": "جهات الاتصال",
"Pipelines": "مسارات المبيعات", "Pipeline report": "تقرير المسارات", "Assignment": "التوزيع",
"Hierarchy": "الهيكل التنظيمي", "Portfolio": "المحفظة", "Inventory": "المخزون العقاري",
"Listings": "العروض", "Owners, tenants & brokers": "الملاك والمستأجرون والوسطاء", "Brokerage": "الوساطة",
"Leasing & sales": "التأجير والمبيعات", "Reservations": "الحجوزات", "Agreements": "العقود",
"Handovers": "التسليم", "Lease compliance": "امتثال العقود", "Finance": "المالية", "Invoices": "الفواتير",
"Vendor bills": "فواتير الموردين", "Vendor refunds": "مستردات الموردين",
"Bank reconciliation": "المطابقة البنكية", "Accounting": "المحاسبة",
"Accounting overview": "نظرة عامة على المحاسبة", "Journal register": "سجل القيود",
"Financial statements": "القوائم المالية", "VAT return": "إقرار ضريبة القيمة المضافة",
"Corporate tax": "ضريبة الشركات", "Budgets": "الموازنات", "Fixed assets": "الأصول الثابتة",
"Outstanding balances": "الأرصدة المستحقة", "Account activity": "حركة الحسابات",
"Audit trail": "سجل التدقيق", "Legacy mappings": "ربط الحسابات السابقة",
"Property performance": "أداء العقارات", "Owner statements": "كشوف الملاك", "Operations": "العمليات",
"Operations overview": "نظرة عامة على العمليات", "Maintenance": "الصيانة",
"Preventive maintenance": "الصيانة الوقائية", "Helpdesk": "مكتب المساعدة",
"AMC contracts": "عقود الصيانة السنوية", "Spare parts": "قطع الغيار", "Projects": "المشاريع",
"Fleet": "الأسطول", "Operations reports": "تقارير العمليات", "Scheduled reports": "التقارير المجدولة",
"Compliance documents": "مستندات الامتثال", "Signatures": "التوقيعات", "Procurement": "المشتريات",
"Administration": "الإدارة", "Organization": "المؤسسة", "Organization activity": "سجل نشاط المؤسسة",
"Portal access": "الوصول إلى البوابة", "API tokens": "رموز الواجهة البرمجية", "Settings": "الإعدادات"
```

```bash
docker exec z1erp-web sh -lc 'cd /workspace && php scripts/sync-arabic-catalog.php'
```

- [ ] **Step 6: Run the tests and confirm all five pass**

Run: the same command as Step 2. Expected: 5 PASS.

- [ ] **Step 7: Typecheck and lint, then commit**

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -c "npm run typecheck && npm run lint"
git add resources/js/lib/navigation.ts resources/js/locales/ar.json lang/ar.json tests/Frontend/navigation.test.mjs
git commit -m "feat(ui): add grouped navigation config with route integrity tests

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Restyle existing primitives, `InputError` and `EmptyState`

**Files (modify):**

- `resources/js/components/ui/button/index.ts` (full `buttonVariants` replacement)
- `resources/js/components/ui/badge/index.ts` (full `badgeVariants` replacement)
- `resources/js/components/ui/input/Input.vue`, `resources/js/components/ui/select/SelectTrigger.vue`, `resources/js/components/ui/checkbox/Checkbox.vue`, `resources/js/components/ui/card/Card.vue`, `resources/js/components/ui/card/CardTitle.vue`, `resources/js/components/ui/dialog/DialogContent.vue`, `resources/js/components/ui/dialog/DialogTitle.vue`, `resources/js/components/ui/dropdown-menu/DropdownMenuContent.vue`, `resources/js/components/ui/skeleton/Skeleton.vue`, `resources/js/components/ui/alert/index.ts` (class strings as specified below)
- `resources/js/components/InputError.vue`, `resources/js/components/EmptyState.vue` (full replacements)

**Interfaces:**

- Produces:
    - Button variants `default | destructive | destructive-outline | outline | secondary | ghost | link`
    - Button sizes `default (36px) | sm (30px) | lg (44px) | icon | icon-sm | icon-lg`
    - `EmptyState` keeps its props `title` and `description?`, plus its default slot, and gains an optional `#icon` slot
    - `InputError` keeps its `message?` prop

The test for this task is the build plus the visual styleguide (Task 9), because these files are markup and classes only. No behavior changes.

- [ ] **Step 1: Replace `buttonVariants` in `ui/button/index.ts`**

```ts
export const buttonVariants = cva(
    "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-sm text-[13px] font-medium tracking-[0.01em] transition-[color,background-color,border-color,box-shadow] duration-150 disabled:pointer-events-none disabled:opacity-45 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-3.5 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:border-destructive",
    {
        variants: {
            variant: {
                default:
                    'bg-primary text-primary-foreground shadow-[inset_0_1px_0_rgb(255_255_255/0.08)] hover:bg-primary-hover',
                destructive:
                    'bg-destructive text-destructive-foreground hover:bg-destructive/90',
                'destructive-outline':
                    'border border-destructive/40 bg-transparent text-destructive hover:bg-destructive/5',
                outline:
                    'border border-input bg-card hover:bg-accent hover:text-accent-foreground',
                secondary:
                    'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                link: 'text-accent-text underline decoration-accent-text/35 underline-offset-4 hover:decoration-accent-text',
            },
            size: {
                default: 'h-9 px-4 has-[>svg]:px-3.5',
                sm: 'h-[30px] gap-1.5 px-3 text-xs has-[>svg]:px-2.5',
                lg: 'h-11 px-6 text-[13.5px] has-[>svg]:px-5',
                icon: 'size-9',
                'icon-sm': 'size-[30px]',
                'icon-lg': 'size-11',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);
```

- [ ] **Step 2: Replace `badgeVariants` in `ui/badge/index.ts`**

```ts
export const badgeVariants = cva(
    'inline-flex items-center justify-center rounded-full border px-2.5 py-0.5 text-[11px] font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1.5 [&>svg]:pointer-events-none focus-visible:ring-2 focus-visible:ring-ring aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden',
    {
        variants: {
            variant: {
                default:
                    'border-transparent bg-primary/10 text-accent-text dark:bg-champagne/12',
                secondary:
                    'border-transparent bg-secondary text-secondary-foreground',
                destructive:
                    'border-transparent bg-destructive/10 text-destructive',
                outline: 'border-input text-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);
```

- [ ] **Step 3: Replace class strings in the remaining primitives**

In each file, replace the first argument of `cn(...)` (or the class attribute shown) exactly:

`ui/input/Input.vue`: replace the three class-string lines inside `cn(` with:

```ts
      'file:text-foreground placeholder:text-muted-foreground selection:bg-champagne/35 border-input bg-card h-[38px] w-full min-w-0 rounded-sm border px-3 py-1 text-base transition-[color,box-shadow,border-color] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-[13px]',
      'focus-visible:border-primary dark:focus-visible:border-ring focus-visible:ring-ring/35 focus-visible:ring-[3px]',
      'aria-invalid:border-destructive aria-invalid:ring-destructive/15 aria-invalid:ring-[3px]',
```

`ui/select/SelectTrigger.vue`, first string in `cn(`:

```ts
      'border-input bg-card data-[placeholder]:text-muted-foreground [&_svg:not([class*=\'text-\'])]:text-muted-foreground focus-visible:border-primary dark:focus-visible:border-ring focus-visible:ring-ring/35 aria-invalid:border-destructive aria-invalid:ring-destructive/15 flex w-fit items-center justify-between gap-2 rounded-sm border px-3 py-2 text-[13px] whitespace-nowrap transition-[color,box-shadow,border-color] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 data-[size=default]:h-[38px] data-[size=sm]:h-[30px] *:data-[slot=select-value]:line-clamp-1 *:data-[slot=select-value]:flex *:data-[slot=select-value]:items-center *:data-[slot=select-value]:gap-2 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4',
```

`ui/checkbox/Checkbox.vue`, first string in `cn(`:

```ts
'peer border-input bg-card data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-primary data-[state=indeterminate]:bg-primary data-[state=indeterminate]:text-primary-foreground focus-visible:ring-ring aria-invalid:border-destructive size-4 shrink-0 rounded-[3px] border transition-shadow outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50';
```

`ui/card/Card.vue`, first string: `'bg-card text-card-foreground flex flex-col gap-6 rounded-lg border py-6 shadow-panel'`

`ui/card/CardTitle.vue`: `cn('font-display text-[22px] leading-tight font-medium', props.class)`

`ui/dialog/DialogContent.vue`, first string: in the existing value, change `bg-background` to `bg-card`, `rounded-lg` to `rounded-xl`, `shadow-lg` to `shadow-overlay`, and `p-6` to `p-7`. Leave everything else unchanged.

`ui/dialog/DialogTitle.vue`: `cn('font-display text-[26px] leading-tight font-medium', props.class)`

`ui/dropdown-menu/DropdownMenuContent.vue`: in the class string, change `rounded-md border p-1 shadow-md` to `rounded-lg border p-1 shadow-overlay`.

`ui/skeleton/Skeleton.vue`: `cn('animate-pulse rounded-sm bg-surface-sunken', props.class)`

`ui/alert/index.ts`: change the base `rounded-lg border px-4 py-3` to `rounded-lg border px-4 py-3.5`. Replace the `destructive` variant with:

```ts
        destructive:
          "border-destructive/30 bg-destructive/5 text-foreground [&>svg]:text-destructive *:data-[slot=alert-description]:text-muted-foreground",
```

- [ ] **Step 4: Replace `resources/js/components/InputError.vue`**

```vue
<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';

defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="text-destructive flex items-center gap-1.5 text-xs">
            <CircleAlert class="size-3.5 shrink-0" aria-hidden="true" />
            {{ message }}
        </p>
    </div>
</template>
```

- [ ] **Step 5: Replace `resources/js/components/EmptyState.vue`**

```vue
<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
};

defineProps<Props>();
</script>

<template>
    <section
        class="flex min-h-48 flex-col items-center justify-center px-6 py-11 text-center"
    >
        <div
            v-if="$slots.icon"
            class="border-input text-accent-text mb-4 grid size-14 place-items-center rounded-full border [&_svg]:size-5"
        >
            <slot name="icon" />
        </div>
        <h2 class="font-display text-2xl font-medium">{{ title }}</h2>
        <p
            v-if="description"
            class="text-muted-foreground mx-auto mt-1.5 max-w-sm text-sm"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-5">
            <slot />
        </div>
    </section>
</template>
```

- [ ] **Step 6: Typecheck, lint, build and run the full PHP suite**

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -c "npm run typecheck && npm run lint"
docker exec z1erp-web sh -lc 'cd /workspace && php artisan wayfinder:generate --with-form'
docker run --rm -v "$PWD":/workspace -w /workspace -e WAYFINDER_GENERATED=1 node:22 npm run build
docker exec z1erp-web sh -lc 'cd /workspace && php artisan test'
```

Expected: all exit 0. The PHP suite proves every page still renders. If a test asserted the old red `InputError` classes or the dashed `EmptyState`, report it rather than silently changing the test.

- [ ] **Step 7: Commit**

```bash
git add resources/js/components/ui resources/js/components/InputError.vue resources/js/components/EmptyState.vue
git commit -m "feat(ui): restyle base primitives to Bordeaux

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Add the new shadcn-vue primitives

**Files:**

- Create (via the CLI): `resources/js/components/ui/{table,tabs,popover,command,textarea,switch,progress,scroll-area,hover-card}/*`
- Then modify the class strings listed in Step 3.

**Interfaces:**

- Produces imports used by Tasks 8–9:
    - `Table, TableHeader, TableBody, TableRow, TableHead, TableCell` from `@/components/ui/table`
    - `Tabs, TabsList, TabsTrigger, TabsContent` from `@/components/ui/tabs`
    - `Popover, PopoverTrigger, PopoverContent` from `@/components/ui/popover`
    - `Command, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem` from `@/components/ui/command`
    - `Textarea` from `@/components/ui/textarea`
    - `Switch` from `@/components/ui/switch`
    - `Progress` from `@/components/ui/progress`
    - `ScrollArea` from `@/components/ui/scroll-area`
    - `HoverCard, HoverCardTrigger, HoverCardContent` from `@/components/ui/hover-card`

- [ ] **Step 1: Run the shadcn-vue CLI**

```bash
docker run --rm -it -v "$PWD":/workspace -w /workspace node:22 npx --yes shadcn-vue@latest add table tabs popover command textarea switch progress scroll-area hover-card
```

If it asks to overwrite an existing file, answer **No**. If it asks about the CSS file, answer **No** / skip. The tokens are already final.

- [ ] **Step 2: Confirm no dependency or CSS changes slipped in**

```bash
git diff --stat package.json package-lock.json resources/css/app.css components.json
```

Expected: empty output.

- If `package.json` or the lock file changed, stop and report the added packages to the owner before continuing.
- If `app.css` or `components.json` changed, restore just the CLI's additions by hand with Edit, so the file matches the Task 1 version. Show the diff to confirm.

- [ ] **Step 3: Apply the Bordeaux class strings**

Replace the class string passed to `cn(` (first argument) in each generated file:

- `table/Table.vue`, the `<table>` element: `'w-full caption-bottom text-[13px]'`
- `table/TableRow.vue`: `'hover:bg-primary/[0.035] dark:hover:bg-ring/[0.05] data-[state=selected]:bg-champagne/10 border-b transition-colors'`
- `table/TableHead.vue`: `'text-muted-foreground h-11 px-3.5 text-start align-middle text-[10px] font-medium tracking-[0.16em] whitespace-nowrap uppercase first:ps-5 last:pe-5 [&:has([role=checkbox])]:w-10 [&:has([role=checkbox])]:pe-0'`
- `table/TableCell.vue`: `'px-3.5 py-3 align-middle tabular-nums first:ps-5 last:pe-5 [&:has([role=checkbox])]:pe-0'`
- `tabs/TabsList.vue`: `'inline-flex w-full items-center gap-6 border-b'`
- `tabs/TabsTrigger.vue`: `'text-muted-foreground data-[state=active]:text-foreground focus-visible:ring-ring relative -mb-px inline-flex items-center gap-1.5 pb-3 text-[13px] font-medium whitespace-nowrap transition-colors outline-none after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:bg-transparent focus-visible:rounded-sm focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:after:bg-primary dark:data-[state=active]:after:bg-ring'`
- `textarea/Textarea.vue`: `'border-input placeholder:text-muted-foreground bg-card flex min-h-21 w-full rounded-sm border px-3 py-2.5 text-[13px] transition-[color,box-shadow,border-color] outline-none focus-visible:border-primary dark:focus-visible:border-ring focus-visible:ring-ring/35 focus-visible:ring-[3px] aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50'`
- `switch/Switch.vue`, root: `'peer focus-visible:ring-ring focus-visible:ring-offset-background data-[state=checked]:bg-primary data-[state=unchecked]:bg-input inline-flex h-5 w-[34px] shrink-0 items-center rounded-full border border-transparent transition-colors outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50'`
- `switch/Switch.vue`, the thumb (`SwitchThumb`): `'bg-primary-foreground pointer-events-none block size-4 rounded-full shadow-sm ring-0 transition-transform data-[state=unchecked]:translate-x-0.5 data-[state=checked]:translate-x-[15px] rtl:data-[state=unchecked]:-translate-x-0.5 rtl:data-[state=checked]:-translate-x-[15px]'`
- `popover/PopoverContent.vue` and `hover-card/HoverCardContent.vue`: in the existing string, change `rounded-md` to `rounded-lg` and `shadow-md` to `shadow-overlay`.
- `command/CommandItem.vue`: in the existing string, replace every `data-[highlighted]:bg-accent` with `data-[highlighted]:bg-primary/[0.07] dark:data-[highlighted]:bg-ring/[0.12]`.
- `command/CommandInput.vue`: replace the input's height class `h-10` (or `h-9`) with `h-12` and its text size with `text-[15px]`.

After editing, check that no generated file uses physical direction classes:

```bash
grep -rnE "\b(ml|mr|pl|pr)-[0-9]|\b(left|right)-[0-9]|text-(left|right)\b" resources/js/components/ui/{table,tabs,popover,command,textarea,switch,progress,scroll-area,hover-card} || echo "clean"
```

Expected: `clean`. Otherwise convert each hit to its logical equivalent (`ms/me/ps/pe/start/end/text-start/text-end`). `left-[50%]`-style centring of popovers is allowed; leave it.

- [ ] **Step 4: Typecheck, lint and build**

Run the same three Node commands as Task 5 Step 6 (skip the PHP suite). Expected: all exit 0.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/ui
git commit -m "feat(ui): add table, tabs, popover, command, textarea, switch, progress, scroll-area and hover-card primitives

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Page building blocks (charts, `PageHeader`, stats, detail and form layouts)

**Files:**

- Create: `resources/js/lib/chart-paths.ts`
- Create: `resources/js/components/Sparkline.vue`, `AreaChart.vue`, `PageHeader.vue`, `StatGrid.vue`, `StatTile.vue`, `DetailLayout.vue`, `FormSection.vue`, `FormField.vue`
- Modify: `resources/js/locales/ar.json` (key `optional`), then run the sync script (updates `lang/ar.json`)
- Test: `tests/Frontend/chart-paths.test.mjs`

**Interfaces:**

- Consumes:
    - `formatCompact`, `formatNumber`, `currencySymbol`, `NumericInput` (Task 2)
    - `StatusDot` (Task 3)
    - `Label` from `@/components/ui/label`
    - `InputError` (Task 5)
- Produces:
    - `chartPoints(values: number[], width: number, height: number, padding = 0): [number, number][]`
    - `linePath(values: number[], width: number, height: number, padding = 0): string`
    - `areaPath(values: number[], width: number, height: number, padding = 0): string`
    - `<Sparkline :values width? height? />`
    - `<AreaChart :values labels? height? label />`
    - `<PageHeader title eyebrow? description?>` with `#actions` and `#meta` slots
    - `<StatGrid>` wrapping `<StatTile label :value currency? unit? decimals? compact? trend? trend-tone? series? />`
    - `<DetailLayout title eyebrow? status? facts?>` with `#actions` and default slots
    - `<FormSection title description?>`
    - `<FormField id label optional? help? error? full?>`

- [ ] **Step 1: Write the failing chart tests**

Create `tests/Frontend/chart-paths.test.mjs`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    areaPath,
    chartPoints,
    linePath,
} from '../../resources/js/lib/chart-paths.ts';

await test('points span the full width and respect padding', () => {
    const points = chartPoints([1, 3, 2], 100, 24, 2);
    assert.deepEqual(points[0], [0, 22]);
    assert.deepEqual(points[1], [50, 2]);
    assert.equal(points[2][0], 100);
});

await test('flat, single and empty series never produce NaN', () => {
    assert.equal(linePath([], 86, 24), '');
    assert.equal(areaPath([], 86, 24), '');
    assert.equal(linePath([5], 86, 24), 'M0,12 L86,12');
    for (const path of [
        linePath([5, 5, 5], 86, 24, 2),
        linePath([1, Number.NaN, 3], 86, 24),
    ]) {
        assert.ok(!path.includes('NaN'), path);
    }
    assert.deepEqual(
        chartPoints([5, 5, 5], 86, 24).map(([, y]) => y),
        [12, 12, 12],
    );
});

await test('line paths are smooth curves and areas close to the baseline', () => {
    const line = linePath([1, 2, 3], 100, 50);
    assert.match(line, /^M0,50 C/);
    assert.equal((line.match(/C/g) ?? []).length, 2);
    assert.ok(areaPath([1, 2, 3], 100, 50).endsWith('L100,50 L0,50 Z'));
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/chart-paths.test.mjs`

Expected: FAIL, module not found.

- [ ] **Step 3: Implement `resources/js/lib/chart-paths.ts`**

```ts
export type Point = [number, number];

function round(value: number): number {
    return Math.round(value * 100) / 100;
}

export function chartPoints(
    values: number[],
    width: number,
    height: number,
    padding = 0,
): Point[] {
    const clean = values.filter((value) => Number.isFinite(value));
    if (clean.length === 0) {
        return [];
    }
    const min = Math.min(...clean);
    const range = Math.max(...clean) - min;
    const inner = height - padding * 2;
    const step = clean.length > 1 ? width / (clean.length - 1) : 0;

    return clean.map((value, index) => [
        round(index * step),
        round(
            range === 0
                ? height / 2
                : padding + (1 - (value - min) / range) * inner,
        ),
    ]);
}

export function linePath(
    values: number[],
    width: number,
    height: number,
    padding = 0,
): string {
    const points = chartPoints(values, width, height, padding);
    if (points.length === 0) {
        return '';
    }
    if (points.length === 1) {
        return `M0,${points[0][1]} L${width},${points[0][1]}`;
    }

    return points
        .map(([x, y], index) => {
            if (index === 0) {
                return `M${x},${y}`;
            }
            const [previousX, previousY] = points[index - 1];
            const middle = round((previousX + x) / 2);

            return `C${middle},${previousY} ${middle},${y} ${x},${y}`;
        })
        .join(' ');
}

export function areaPath(
    values: number[],
    width: number,
    height: number,
    padding = 0,
): string {
    const line = linePath(values, width, height, padding);

    return line === '' ? '' : `${line} L${width},${height} L0,${height} Z`;
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: the same command as Step 2. Expected: 3 PASS.

- [ ] **Step 5: Create `Sparkline.vue` and `AreaChart.vue`**

`resources/js/components/Sparkline.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { linePath } from '@/lib/chart-paths';

const props = withDefaults(
    defineProps<{ values: number[]; width?: number; height?: number }>(),
    { width: 86, height: 24 },
);

const path = computed(() =>
    linePath(props.values, props.width, props.height, 2),
);
</script>

<template>
    <svg
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        fill="none"
        aria-hidden="true"
        class="shrink-0"
    >
        <path
            :d="path"
            stroke="currentColor"
            stroke-width="1.3"
            stroke-linecap="round"
        />
    </svg>
</template>
```

`resources/js/components/AreaChart.vue`:

```vue
<script setup lang="ts">
import { computed, useId } from 'vue';
import { areaPath, linePath } from '@/lib/chart-paths';

const props = withDefaults(
    defineProps<{
        values: number[];
        label: string;
        labels?: string[];
        height?: number;
    }>(),
    { labels: () => [], height: 190 },
);

const width = 620;
const gradientId = useId();
const line = computed(() => linePath(props.values, width, props.height, 22));
const area = computed(() => areaPath(props.values, width, props.height, 22));

function labelX(index: number): number {
    return props.labels.length > 1
        ? (index * width) / (props.labels.length - 1)
        : 0;
}

function labelAnchor(index: number): 'start' | 'middle' | 'end' {
    if (index === 0) {
        return 'start';
    }

    return index === props.labels.length - 1 ? 'end' : 'middle';
}
</script>

<template>
    <figure class="text-chart-1">
        <svg
            :viewBox="`0 -4 ${width} ${height + 26}`"
            class="block h-auto w-full"
            role="img"
            :aria-label="label"
        >
            <defs>
                <linearGradient :id="gradientId" x1="0" x2="0" y1="0" y2="1">
                    <stop
                        offset="0"
                        stop-color="currentColor"
                        stop-opacity="0.2"
                    />
                    <stop
                        offset="1"
                        stop-color="currentColor"
                        stop-opacity="0"
                    />
                </linearGradient>
            </defs>
            <line
                v-for="fraction in [0.25, 0.5, 0.75]"
                :key="fraction"
                x1="0"
                :x2="width"
                :y1="height * fraction"
                :y2="height * fraction"
                class="stroke-border"
                stroke-dasharray="2 4"
            />
            <path :d="area" :fill="`url(#${gradientId})`" />
            <path
                :d="line"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
            />
            <text
                v-for="(text, index) in labels"
                :key="index"
                :x="labelX(index)"
                :y="height + 18"
                :text-anchor="labelAnchor(index)"
                class="fill-muted-foreground text-[10.5px]"
            >
                {{ text }}
            </text>
        </svg>
    </figure>
</template>
```

- [ ] **Step 6: Create `PageHeader.vue`, `StatGrid.vue` and `StatTile.vue`**

`resources/js/components/PageHeader.vue`:

```vue
<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{
        title: string;
        eyebrow?: string;
        description?: string;
        translate?: boolean;
    }>(),
    { eyebrow: undefined, description: undefined, translate: true },
);

const { t } = useLocale();
</script>

<template>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p v-if="eyebrow" class="text-eyebrow">
                {{ translate ? t(eyebrow) : eyebrow }}
            </p>
            <h1
                class="font-display mt-2 text-4xl leading-[1.05] font-medium tracking-[-0.01em] md:text-[44px]"
            >
                {{ translate ? t(title) : title }}
            </h1>
            <p
                v-if="description"
                class="text-muted-foreground mt-2 max-w-2xl text-sm"
            >
                {{ translate ? t(description) : description }}
            </p>
            <slot name="meta" />
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
```

`resources/js/components/StatGrid.vue`:

```vue
<template>
    <div
        class="bg-border grid grid-cols-1 gap-px border-y sm:grid-cols-2 xl:grid-cols-4"
    >
        <slot />
    </div>
</template>
```

`resources/js/components/StatTile.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import Sparkline from '@/components/Sparkline.vue';
import { useLocale } from '@/composables/useLocale';
import { currencySymbol, formatCompact, formatNumber } from '@/lib/format';
import type { NumericInput } from '@/lib/format';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        label: string;
        value: NumericInput;
        currency?: string;
        unit?: string;
        decimals?: number;
        compact?: boolean;
        trend?: string;
        trendTone?: 'positive' | 'negative' | 'neutral';
        series?: number[];
    }>(),
    {
        currency: undefined,
        unit: undefined,
        decimals: 0,
        compact: true,
        trend: undefined,
        trendTone: 'neutral',
        series: undefined,
    },
);

const { t, locale } = useLocale();
const figure = computed(() =>
    props.compact
        ? formatCompact(props.value, locale.value)
        : formatNumber(props.value, props.decimals),
);
const prefix = computed(() =>
    props.currency && locale.value === 'en' ? props.currency : null,
);
const suffix = computed(() => {
    if (props.currency && locale.value === 'ar') {
        return currencySymbol(props.currency, 'ar');
    }

    return props.unit ?? null;
});
const trendClass = computed(
    () =>
        ({
            positive: 'text-success',
            negative: 'text-destructive',
            neutral: 'text-muted-foreground',
        })[props.trendTone],
);
</script>

<template>
    <div class="bg-background flex flex-col px-6 py-5">
        <span class="text-label">{{ t(label) }}</span>
        <span
            class="font-display mt-3 mb-2.5 text-[38px] leading-none font-medium tracking-[-0.02em] whitespace-nowrap tabular-nums lining-nums"
        >
            <span
                v-if="prefix"
                class="text-muted-foreground me-1.5 align-[0.9em] font-sans text-[11px] tracking-[0.12em]"
                >{{ prefix }}</span
            >{{ figure
            }}<span
                v-if="suffix"
                class="text-muted-foreground ms-1 text-[0.5em]"
                >{{ suffix }}</span
            >
        </span>
        <div class="flex min-h-6 items-center justify-between gap-2 text-xs">
            <span v-if="trend" :class="trendClass">{{ trend }}</span>
            <Sparkline
                v-if="series?.length"
                :values="series"
                :class="cn('ms-auto', trendClass)"
            />
        </div>
    </div>
</template>
```

- [ ] **Step 7: Create `DetailLayout.vue`, `FormSection.vue` and `FormField.vue`**

`resources/js/components/DetailLayout.vue`:

```vue
<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{
        title: string;
        eyebrow?: string;
        status?: string | null;
        facts?: { label: string; value: string }[];
    }>(),
    { eyebrow: undefined, status: null, facts: () => [] },
);

const { t } = useLocale();
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p v-if="eyebrow" class="text-eyebrow">{{ t(eyebrow) }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-4">
                    <h1
                        class="font-display text-4xl leading-[1.05] font-medium tracking-[-0.01em] md:text-[44px]"
                    >
                        {{ title }}
                    </h1>
                    <StatusDot
                        v-if="status"
                        :status="status"
                        class="border-input rounded-full border px-3 py-1"
                    />
                </div>
            </div>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </header>
        <dl
            v-if="facts.length"
            class="bg-border grid grid-cols-2 gap-px border-y md:grid-cols-3 xl:grid-cols-6"
        >
            <div
                v-for="fact in facts"
                :key="fact.label"
                class="bg-background py-4 pe-4"
            >
                <dt class="text-label">{{ t(fact.label) }}</dt>
                <dd class="mt-1.5 font-medium tabular-nums">
                    {{ fact.value }}
                </dd>
            </div>
        </dl>
        <slot />
    </div>
</template>
```

`resources/js/components/FormSection.vue`:

```vue
<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';

defineProps<{ title: string; description?: string }>();

const { t } = useLocale();
</script>

<template>
    <section class="grid gap-6 border-b py-7 md:grid-cols-[240px_1fr] md:gap-8">
        <div>
            <h2 class="font-display text-[22px] leading-tight font-medium">
                {{ t(title) }}
            </h2>
            <p
                v-if="description"
                class="text-muted-foreground mt-1.5 text-[13px]"
            >
                {{ t(description) }}
            </p>
        </div>
        <div class="grid content-start gap-x-4 gap-y-5 sm:grid-cols-2">
            <slot />
        </div>
    </section>
</template>
```

`resources/js/components/FormField.vue`:

```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        id: string;
        label: string;
        optional?: boolean;
        help?: string;
        error?: string;
        full?: boolean;
    }>(),
    { optional: false, help: undefined, error: undefined, full: false },
);

const { t } = useLocale();
</script>

<template>
    <div :class="cn('flex flex-col gap-1.5', full && 'sm:col-span-2')">
        <Label :for="id" class="text-xs font-medium">
            {{ t(label) }}
            <span v-if="optional" class="text-muted-foreground font-normal"
                >({{ t('optional') }})</span
            >
        </Label>
        <slot />
        <p
            v-if="help && !error"
            :id="`${id}-help`"
            class="text-muted-foreground text-xs"
        >
            {{ t(help) }}
        </p>
        <InputError :message="error" />
    </div>
</template>
```

- [ ] **Step 8: Add Arabic for `optional`, then typecheck and lint**

If it is missing, add `"optional": "اختياري"` to `resources/js/locales/ar.json`, then run the sync script. Then:

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -c "npm run typecheck && npm run lint"
```

Expected: exit 0. Run `npm run check:fix` the same way if the formatter rewraps markup, then re-run.

- [ ] **Step 9: Commit**

```bash
git add resources/js/lib/chart-paths.ts resources/js/components/{Sparkline,AreaChart,PageHeader,StatGrid,StatTile,DetailLayout,FormSection,FormField}.vue resources/js/locales/ar.json lang/ar.json tests/Frontend/chart-paths.test.mjs
git commit -m "feat(ui): add page header, stat, chart, detail and form building blocks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: `DataTable`, `FilterBar` and `FilterChip`

**Files:**

- Create: `resources/js/lib/data-table.ts`
- Create: `resources/js/components/DataTable.vue`, `FilterBar.vue`, `FilterChip.vue`
- Modify: `resources/js/locales/ar.json` (keys listed in Step 7), then run the sync script (updates `lang/ar.json`)
- Test: `tests/Frontend/data-table.test.mjs`

**Interfaces:**

- Consumes:
    - `Table*`, `Checkbox`, `Skeleton`, `Alert*`, `Button`, `Input` primitives
    - `EmptyState` (Task 5)
- Produces (`@/lib/data-table`):
    - `type RowKey = string | number`
    - `type SortDirection = 'asc' | 'desc'`
    - `type SortState = { key: string; direction: SortDirection } | null`
    - `type DataTableColumn = { key: string; label: string; align?: 'start' | 'end'; sortable?: boolean; class?: string }`
    - `nextSort(current: SortState, key: string): SortState`
    - `ariaSort(current: SortState, key: string): 'ascending' | 'descending' | 'none'`
    - `toggleOne(selected: RowKey[], key: RowKey): RowKey[]`
    - `toggleAll(selected: RowKey[], visible: RowKey[]): RowKey[]`
    - `selectionState(selected: RowKey[], visible: RowKey[]): boolean | 'indeterminate'`
- Produces components:
    - `<DataTable :columns :rows :row-key loading? error? empty-title? empty-description? selectable? caption? v-model:sort v-model:selected @retry>` with slots `#toolbar`, `#bulk`, `#cell-<key>="{ row, value }"`, `#empty-action`, `#footer`
    - `<FilterBar v-model placeholder? result-label? clearable? @clear>` with a default slot for chips
    - `<FilterChip label value? removable? @remove>`

- [ ] **Step 1: Write the failing tests**

Create `tests/Frontend/data-table.test.mjs`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    ariaSort,
    nextSort,
    selectionState,
    toggleAll,
    toggleOne,
} from '../../resources/js/lib/data-table.ts';

await test('sorting cycles ascending, descending, then off, and resets on a new column', () => {
    let sort = nextSort(null, 'amount');
    assert.deepEqual(sort, { key: 'amount', direction: 'asc' });
    sort = nextSort(sort, 'amount');
    assert.deepEqual(sort, { key: 'amount', direction: 'desc' });
    assert.equal(nextSort(sort, 'amount'), null);
    assert.deepEqual(nextSort(sort, 'due'), { key: 'due', direction: 'asc' });
    assert.equal(
        ariaSort({ key: 'amount', direction: 'desc' }, 'amount'),
        'descending',
    );
    assert.equal(ariaSort({ key: 'amount', direction: 'asc' }, 'due'), 'none');
    assert.equal(ariaSort(null, 'due'), 'none');
});

await test('row selection toggles single rows and all visible rows', () => {
    assert.deepEqual(toggleOne([1, 2], 2), [1]);
    assert.deepEqual(toggleOne([1], 3), [1, 3]);
    assert.deepEqual(toggleAll([9], [1, 2]), [9, 1, 2]);
    assert.deepEqual(toggleAll([9, 1, 2], [1, 2]), [9]);
    assert.deepEqual(toggleAll([], []), []);
});

await test('the header checkbox reflects none, some or all visible rows', () => {
    assert.equal(selectionState([], [1, 2]), false);
    assert.equal(selectionState([1], [1, 2]), 'indeterminate');
    assert.equal(selectionState([1, 2, 7], [1, 2]), true);
    assert.equal(selectionState([7], []), false);
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/data-table.test.mjs`

Expected: FAIL, module not found.

- [ ] **Step 3: Implement `resources/js/lib/data-table.ts`**

```ts
export type RowKey = string | number;

export type SortDirection = 'asc' | 'desc';

export type SortState = { key: string; direction: SortDirection } | null;

export type DataTableColumn = {
    key: string;
    label: string;
    align?: 'start' | 'end';
    sortable?: boolean;
    class?: string;
};

export function nextSort(current: SortState, key: string): SortState {
    if (!current || current.key !== key) {
        return { key, direction: 'asc' };
    }

    return current.direction === 'asc' ? { key, direction: 'desc' } : null;
}

export function ariaSort(
    current: SortState,
    key: string,
): 'ascending' | 'descending' | 'none' {
    if (!current || current.key !== key) {
        return 'none';
    }

    return current.direction === 'asc' ? 'ascending' : 'descending';
}

export function toggleOne(selected: RowKey[], key: RowKey): RowKey[] {
    return selected.includes(key)
        ? selected.filter((item) => item !== key)
        : [...selected, key];
}

export function toggleAll(selected: RowKey[], visible: RowKey[]): RowKey[] {
    const allSelected =
        visible.length > 0 && visible.every((key) => selected.includes(key));
    if (allSelected) {
        return selected.filter((key) => !visible.includes(key));
    }

    return [...new Set([...selected, ...visible])];
}

export function selectionState(
    selected: RowKey[],
    visible: RowKey[],
): boolean | 'indeterminate' {
    const count = visible.filter((key) => selected.includes(key)).length;
    if (count === 0) {
        return false;
    }

    return count === visible.length ? true : 'indeterminate';
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: the same command as Step 2. Expected: 3 PASS.

- [ ] **Step 5: Create `resources/js/components/DataTable.vue`**

```vue
<script setup lang="ts" generic="Row">
import { ArrowDown, ArrowUp, ArrowUpDown, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLocale } from '@/composables/useLocale';
import {
    ariaSort,
    nextSort,
    selectionState,
    toggleAll,
    toggleOne,
} from '@/lib/data-table';
import type { DataTableColumn, RowKey, SortState } from '@/lib/data-table';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        columns: DataTableColumn[];
        rows: Row[];
        rowKey: (row: Row) => RowKey;
        loading?: boolean;
        error?: string | null;
        emptyTitle?: string;
        emptyDescription?: string;
        selectable?: boolean;
        caption?: string;
    }>(),
    {
        loading: false,
        error: null,
        emptyTitle: 'Nothing here yet',
        emptyDescription: undefined,
        selectable: false,
        caption: undefined,
    },
);

const sort = defineModel<SortState>('sort', { default: null });
const selected = defineModel<RowKey[]>('selected', { default: () => [] });
const emit = defineEmits<{ retry: [] }>();
const { t } = useLocale();

const visibleKeys = computed(() => props.rows.map((row) => props.rowKey(row)));
const headerState = computed(() =>
    selectionState(selected.value, visibleKeys.value),
);
const columnCount = computed(
    () => props.columns.length + (props.selectable ? 1 : 0),
);

function cellValue(row: Row, key: string): unknown {
    return (row as Record<string, unknown>)[key];
}

function display(value: unknown): string {
    return value === null || value === undefined || value === ''
        ? '—'
        : String(value);
}

function alignClass(column: DataTableColumn): string | undefined {
    return column.align === 'end' ? 'text-end' : undefined;
}
</script>

<template>
    <div class="bg-card shadow-panel overflow-hidden rounded-lg border">
        <slot name="toolbar" />
        <div
            v-if="selectable && selected.length"
            class="bg-champagne/10 flex flex-wrap items-center gap-3 border-b px-5 py-2.5 text-xs"
        >
            <span class="font-medium">{{
                t(':count selected', { count: selected.length })
            }}</span>
            <slot name="bulk" :selected="selected" />
        </div>
        <Table>
            <caption v-if="caption" class="sr-only">
                {{
                    caption
                }}
            </caption>
            <TableHeader class="bg-card sticky top-0 z-10">
                <TableRow class="hover:bg-transparent">
                    <TableHead v-if="selectable">
                        <Checkbox
                            :model-value="headerState"
                            :aria-label="t('Select all rows')"
                            @update:model-value="
                                selected = toggleAll(selected, visibleKeys)
                            "
                        />
                    </TableHead>
                    <TableHead
                        v-for="column in columns"
                        :key="column.key"
                        :aria-sort="
                            column.sortable
                                ? ariaSort(sort, column.key)
                                : undefined
                        "
                        :class="cn(alignClass(column), column.class)"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            :class="
                                cn(
                                    'hover:text-foreground focus-visible:ring-ring inline-flex items-center gap-1 rounded-sm uppercase outline-none focus-visible:ring-2',
                                    sort?.key === column.key &&
                                        'text-foreground',
                                )
                            "
                            @click="sort = nextSort(sort, column.key)"
                        >
                            {{ t(column.label) }}
                            <ArrowUp
                                v-if="
                                    sort?.key === column.key &&
                                    sort.direction === 'asc'
                                "
                                class="size-3"
                            />
                            <ArrowDown
                                v-else-if="sort?.key === column.key"
                                class="size-3"
                            />
                            <ArrowUpDown v-else class="size-3 opacity-40" />
                        </button>
                        <template v-else>{{ t(column.label) }}</template>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <template v-if="loading">
                    <TableRow
                        v-for="n in 5"
                        :key="`skeleton-${n}`"
                        class="hover:bg-transparent"
                    >
                        <TableCell v-if="selectable">
                            <Skeleton class="size-4" />
                        </TableCell>
                        <TableCell v-for="column in columns" :key="column.key">
                            <Skeleton
                                class="h-3"
                                :style="{ width: `${50 + ((n * 17) % 40)}%` }"
                            />
                        </TableCell>
                    </TableRow>
                </template>
                <TableRow v-else-if="error" class="hover:bg-transparent">
                    <TableCell :colspan="columnCount" class="p-5">
                        <Alert variant="destructive">
                            <TriangleAlert />
                            <AlertTitle>{{
                                t('Something went wrong')
                            }}</AlertTitle>
                            <AlertDescription>
                                <p>{{ error }}</p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="mt-2"
                                    @click="emit('retry')"
                                    >{{ t('Try again') }}</Button
                                >
                            </AlertDescription>
                        </Alert>
                    </TableCell>
                </TableRow>
                <TableRow
                    v-else-if="rows.length === 0"
                    class="hover:bg-transparent"
                >
                    <TableCell :colspan="columnCount">
                        <EmptyState
                            :title="t(emptyTitle)"
                            :description="
                                emptyDescription
                                    ? t(emptyDescription)
                                    : undefined
                            "
                        >
                            <slot name="empty-action" />
                        </EmptyState>
                    </TableCell>
                </TableRow>
                <template v-else>
                    <TableRow
                        v-for="row in rows"
                        :key="rowKey(row)"
                        :data-state="
                            selected.includes(rowKey(row))
                                ? 'selected'
                                : undefined
                        "
                    >
                        <TableCell v-if="selectable">
                            <Checkbox
                                :model-value="selected.includes(rowKey(row))"
                                :aria-label="t('Select row')"
                                @update:model-value="
                                    selected = toggleOne(selected, rowKey(row))
                                "
                            />
                        </TableCell>
                        <TableCell
                            v-for="column in columns"
                            :key="column.key"
                            :class="cn(alignClass(column), column.class)"
                        >
                            <slot
                                :name="`cell-${column.key}`"
                                :row="row"
                                :value="cellValue(row, column.key)"
                                >{{ display(cellValue(row, column.key)) }}</slot
                            >
                        </TableCell>
                    </TableRow>
                </template>
            </TableBody>
        </Table>
        <slot name="footer" />
    </div>
</template>
```

- [ ] **Step 6: Create `FilterBar.vue` and `FilterChip.vue`**

`resources/js/components/FilterBar.vue`:

```vue
<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{
        placeholder?: string;
        resultLabel?: string;
        clearable?: boolean;
    }>(),
    { placeholder: 'Search', resultLabel: undefined, clearable: false },
);

const search = defineModel<string>({ default: '' });
const emit = defineEmits<{ clear: [] }>();
const { t } = useLocale();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 border-b px-5 py-3.5">
        <label class="relative w-full sm:w-72">
            <span class="sr-only">{{ t(placeholder) }}</span>
            <Search
                class="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
            />
            <Input
                v-model="search"
                type="search"
                :placeholder="t(placeholder)"
                class="bg-surface-sunken border-border h-[34px] ps-9"
            />
        </label>
        <slot />
        <Button
            v-if="clearable"
            variant="ghost"
            size="sm"
            @click="emit('clear')"
        >
            <X />
            {{ t('Clear filters') }}
        </Button>
        <span v-if="resultLabel" class="text-muted-foreground ms-auto text-xs">
            {{ resultLabel }}
        </span>
    </div>
</template>
```

`resources/js/components/FilterChip.vue`:

```vue
<script setup lang="ts">
import { X } from '@lucide/vue';
import { useLocale } from '@/composables/useLocale';

withDefaults(
    defineProps<{ label: string; value?: string; removable?: boolean }>(),
    { value: undefined, removable: false },
);

const emit = defineEmits<{ remove: [] }>();
const { t } = useLocale();
</script>

<template>
    <span
        class="border-input inline-flex h-[30px] items-center gap-1.5 rounded-full border px-3 text-xs"
    >
        <span>
            {{ t(label)
            }}<template v-if="value"
                >:
                <b class="text-accent-text font-medium">{{
                    value
                }}</b></template
            >
        </span>
        <button
            v-if="removable"
            type="button"
            class="text-muted-foreground hover:text-foreground focus-visible:ring-ring -me-1 rounded-full p-0.5 outline-none focus-visible:ring-2"
            :aria-label="t('Remove filter')"
            @click="emit('remove')"
        >
            <X class="size-3" />
        </button>
    </span>
</template>
```

- [ ] **Step 7: Add the Arabic keys, then typecheck and lint**

Add any of these keys that are missing to `resources/js/locales/ar.json`, then run the sync script:

```json
":count selected": "تم تحديد :count", "Select all rows": "تحديد كل الصفوف", "Select row": "تحديد الصف",
"Something went wrong": "حدث خطأ ما", "Try again": "إعادة المحاولة", "Nothing here yet": "لا يوجد شيء هنا بعد",
"Clear filters": "مسح عوامل التصفية", "Remove filter": "إزالة عامل التصفية"
```

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -c "npm run typecheck && npm run lint"
```

Expected: exit 0. If `vue-tsc` rejects the `:model-value` type on `Checkbox`, check `ui/checkbox/Checkbox.vue`'s props. reka-ui v2 `CheckboxRoot` accepts `modelValue: boolean | 'indeterminate'`. Adapt only the DataTable binding, never the primitive's public API.

- [ ] **Step 8: Commit**

```bash
git add resources/js/lib/data-table.ts resources/js/components/{DataTable,FilterBar,FilterChip}.vue resources/js/locales/ar.json lang/ar.json tests/Frontend/data-table.test.mjs
git commit -m "feat(ui): add DataTable with sorting, selection and states, plus FilterBar

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Local-only `/styleguide` page

**Files:**

- Create: `app/Http/Controllers/StyleguideController.php`
- Modify: `routes/web.php` (add a `use` import and one route inside the `auth`/`verified` group, next to `dashboard`)
- Create: `resources/js/pages/Styleguide.vue`
- Test: `tests/Feature/StyleguideTest.php`

**Interfaces:**

- Consumes every component from Tasks 2–8.
- Produces the route `GET /styleguide` (name `styleguide`). It returns 404 unless the environment is `local` or `testing`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/StyleguideTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StyleguideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/styleguide')->assertRedirect(route('login'));
    }

    public function test_the_styleguide_renders_outside_production(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/styleguide')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Styleguide'));
    }

    public function test_the_styleguide_is_hidden_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->actingAs(User::factory()->create())
            ->get('/styleguide')
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `docker exec z1erp-web sh -lc 'cd /workspace && php artisan test tests/Feature/StyleguideTest.php'`

Expected: the guest test and the render test FAIL with 404, because the route doesn't exist yet.

- [ ] **Step 3: Add the controller and route**

`app/Http/Controllers/StyleguideController.php`:

```php
<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class StyleguideController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        return Inertia::render('Styleguide');
    }
}
```

In `routes/web.php`:

- Add `use App\Http\Controllers\StyleguideController;` to the alphabetised `use` block. It goes after `SparePartsController`.
- Directly after the line `Route::get('dashboard', DashboardController::class)->name('dashboard');`, add:

```php
    Route::get('styleguide', StyleguideController::class)->name('styleguide');
```

- [ ] **Step 4: Create `resources/js/pages/Styleguide.vue`**

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, FileText, Inbox, Plus, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import AreaChart from '@/components/AreaChart.vue';
import DataTable from '@/components/DataTable.vue';
import DateText from '@/components/DateText.vue';
import DetailLayout from '@/components/DetailLayout.vue';
import EmptyState from '@/components/EmptyState.vue';
import FilterBar from '@/components/FilterBar.vue';
import FilterChip from '@/components/FilterChip.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatGrid from '@/components/StatGrid.vue';
import StatTile from '@/components/StatTile.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import type { DataTableColumn, RowKey, SortState } from '@/lib/data-table';
import { STATUS_TONES } from '@/lib/status-tones';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Styleguide', href: '/styleguide' }],
    },
});

type Invoice = {
    id: number;
    reference: string;
    tenant: string;
    unit: string;
    due: string;
    amount: number;
    status: string;
};

const invoices: Invoice[] = [
    {
        id: 1,
        reference: 'INV-2294',
        tenant: 'Al Noor Trading LLC',
        unit: 'A-1204',
        due: '2026-09-15',
        amount: 36250,
        status: 'paid',
    },
    {
        id: 2,
        reference: 'INV-2293',
        tenant: 'Sara Al Mansoori',
        unit: 'B-0310',
        due: '2026-09-30',
        amount: 24125,
        status: 'pending',
    },
    {
        id: 3,
        reference: 'INV-2291',
        tenant: 'Gulf Line Logistics',
        unit: 'C-0701',
        due: '2026-09-14',
        amount: 212000,
        status: 'overdue',
    },
    {
        id: 4,
        reference: 'INV-2288',
        tenant: 'Hamdan Family Office',
        unit: 'PH-02',
        due: '2026-09-28',
        amount: 1140000,
        status: 'partially_paid',
    },
    {
        id: 5,
        reference: 'INV-2287',
        tenant: 'Rania Khoury',
        unit: 'B-1102',
        due: '',
        amount: 8400,
        status: 'draft',
    },
    {
        id: 6,
        reference: 'INV-2280',
        tenant: 'Omar Tahir',
        unit: 'P-114',
        due: '2026-08-20',
        amount: 1500,
        status: 'void',
    },
];

const columns: DataTableColumn[] = [
    { key: 'reference', label: 'Invoice', sortable: true },
    { key: 'tenant', label: 'Tenant', sortable: true },
    { key: 'unit', label: 'Unit' },
    { key: 'due', label: 'Due', sortable: true },
    { key: 'amount', label: 'Amount', align: 'end', sortable: true },
    { key: 'status', label: 'Status' },
];

const tableStates = ['data', 'loading', 'empty', 'error'] as const;
const tableState = ref<(typeof tableStates)[number]>('data');
const search = ref('');
const sort = ref<SortState>({ key: 'reference', direction: 'desc' });
const selected = ref<RowKey[]>([2, 3]);
const welcomePack = ref(true);

const rows = computed(() => {
    const term = search.value.trim().toLowerCase();
    const filtered = invoices.filter(
        (invoice) =>
            term === '' ||
            `${invoice.reference} ${invoice.tenant} ${invoice.unit}`
                .toLowerCase()
                .includes(term),
    );
    const current = sort.value;
    if (!current) {
        return filtered;
    }

    return [...filtered].sort((a, b) => {
        const key = current.key as keyof Invoice;
        const order = String(a[key]).localeCompare(String(b[key]), undefined, {
            numeric: true,
        });

        return current.direction === 'asc' ? order : -order;
    });
});

const { money, date } = useFormat();
const facts = computed(() => [
    { label: 'Tenant', value: 'Gulf Line Logistics' },
    { label: 'Unit', value: 'C-0701 · Marina Heights' },
    { label: 'Issued', value: date('2026-09-01') },
    { label: 'Due', value: date('2026-09-14') },
    { label: 'Amount', value: money(212000) },
    { label: 'Balance', value: money(212000) },
]);

const collection = [
    1.21, 1.34, 1.3, 1.42, 1.39, 1.55, 1.61, 1.58, 1.72, 1.79, 1.86, 2.02,
];
const months = [
    'Oct',
    'Nov',
    'Dec',
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
];
</script>

<template>
    <Head title="Styleguide" />

    <div class="flex flex-1 flex-col gap-14 p-4 md:p-8">
        <PageHeader
            eyebrow="Internal · Local only"
            title="Bordeaux styleguide"
            description="Every building block in one place. Switch appearance and language to check light, dark and Arabic."
        >
            <template #actions>
                <Button variant="outline"><Download />Export</Button>
                <Button><Plus />New invoice</Button>
            </template>
        </PageHeader>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Stat tiles</h2>
            <StatGrid>
                <StatTile
                    label="Occupancy"
                    :value="94.2"
                    unit="%"
                    :compact="false"
                    :decimals="1"
                    trend="▲ 1.8 pts"
                    trend-tone="positive"
                    :series="[3, 4, 3.6, 4.4, 4.2, 5, 5.4, 6]"
                />
                <StatTile
                    label="Collected"
                    :value="2418500"
                    currency="AED"
                    trend="▲ 6.4%"
                    trend-tone="positive"
                    :series="[2, 2.6, 2.4, 3.2, 3.4, 4.1, 4.3, 5]"
                />
                <StatTile
                    label="Overdue"
                    :value="186200"
                    currency="AED"
                    trend="12 invoices"
                    trend-tone="negative"
                    :series="[3, 3.4, 3.1, 4, 4.6, 4.4, 5.2, 5.6]"
                />
                <StatTile
                    label="Open work orders"
                    :value="37"
                    trend="5 past SLA"
                    trend-tone="negative"
                />
            </StatGrid>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Data table</h2>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="state in tableStates"
                    :key="state"
                    size="sm"
                    :variant="tableState === state ? 'default' : 'outline'"
                    @click="tableState = state"
                    >{{ state }}</Button
                >
            </div>
            <DataTable
                v-model:sort="sort"
                v-model:selected="selected"
                :columns="columns"
                :rows="tableState === 'empty' ? [] : rows"
                :row-key="(row) => row.id"
                :loading="tableState === 'loading'"
                :error="
                    tableState === 'error'
                        ? 'The bank feed did not respond.'
                        : null
                "
                selectable
                empty-title="No invoices yet"
                empty-description="Invoices appear here when a lease or sale generates them."
                caption="Sample invoices"
            >
                <template #toolbar>
                    <FilterBar
                        v-model="search"
                        placeholder="Search invoice, tenant or unit"
                        :result-label="`${rows.length} invoices`"
                        clearable
                        @clear="search = ''"
                    >
                        <FilterChip label="Status" value="All" />
                        <FilterChip
                            label="Property"
                            value="Marina Heights"
                            removable
                        />
                    </FilterBar>
                </template>
                <template #bulk>
                    <Button size="sm" variant="outline"
                        ><Send />Send reminders</Button
                    >
                </template>
                <template #cell-reference="{ row }">
                    <span class="font-medium tracking-[0.02em]">{{
                        row.reference
                    }}</span>
                </template>
                <template #cell-due="{ row }"
                    ><DateText :value="row.due"
                /></template>
                <template #cell-amount="{ row }"
                    ><Money :value="row.amount"
                /></template>
                <template #cell-status="{ row }"
                    ><StatusDot :status="row.status"
                /></template>
                <template #empty-action
                    ><Button><Plus />New invoice</Button></template
                >
            </DataTable>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Status language</h2>
            <div
                class="bg-card shadow-panel flex flex-wrap gap-x-7 gap-y-3 rounded-lg border p-5"
            >
                <StatusDot
                    v-for="status in Object.keys(STATUS_TONES)"
                    :key="status"
                    :status="status"
                />
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Record page</h2>
            <DetailLayout
                eyebrow="Invoice · Commercial lease"
                title="INV-2291"
                status="overdue"
                :facts="facts"
            >
                <template #actions>
                    <Button variant="outline"><Send />Send reminder</Button>
                    <Button><FileText />Record payment</Button>
                </template>
                <Tabs default-value="overview">
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="payments">Payments</TabsTrigger>
                        <TabsTrigger value="activity">Activity</TabsTrigger>
                    </TabsList>
                    <TabsContent value="overview" class="pt-6">
                        <div class="bg-card shadow-panel rounded-lg border p-6">
                            <h3
                                class="font-display mb-2 text-[22px] font-medium"
                            >
                                Rent collection
                            </h3>
                            <AreaChart
                                :values="collection"
                                :labels="months"
                                label="Rent collection over twelve months"
                            />
                        </div>
                    </TabsContent>
                    <TabsContent value="payments" class="pt-6">
                        <div class="bg-card shadow-panel rounded-lg border">
                            <EmptyState
                                title="No payments yet"
                                description="Payments recorded against this invoice appear here."
                            >
                                <template #icon><Inbox /></template>
                                <Button><Plus />Record payment</Button>
                            </EmptyState>
                        </div>
                    </TabsContent>
                    <TabsContent value="activity" class="pt-6">
                        <p class="text-muted-foreground text-sm">
                            The activity timeline arrives with the finance
                            phase.
                        </p>
                    </TabsContent>
                </Tabs>
            </DetailLayout>
        </section>

        <section class="flex max-w-4xl flex-col">
            <h2 class="text-eyebrow">Form</h2>
            <FormSection
                title="Tenant & unit"
                description="Who is leasing, and which unit."
            >
                <FormField id="sg-tenant" label="Tenant" full>
                    <Input id="sg-tenant" model-value="Al Noor Trading LLC" />
                </FormField>
                <FormField
                    id="sg-unit"
                    label="Unit"
                    help="Only vacant or reserved units are listed."
                    full
                >
                    <Input
                        id="sg-unit"
                        model-value="Marina Heights · A-1204"
                        aria-describedby="sg-unit-help"
                    />
                </FormField>
            </FormSection>
            <FormSection
                title="Term & rent"
                description="Dates, rent and payment schedule."
            >
                <FormField id="sg-start" label="Start date">
                    <Input id="sg-start" type="date" model-value="2026-10-01" />
                </FormField>
                <FormField id="sg-end" label="End date">
                    <Input id="sg-end" type="date" model-value="2029-09-30" />
                </FormField>
                <FormField
                    id="sg-rent"
                    label="Annual rent"
                    error="Annual rent must be greater than zero."
                    full
                >
                    <Input id="sg-rent" model-value="0" aria-invalid="true" />
                </FormField>
                <FormField id="sg-notes" label="Notes" optional full>
                    <Textarea
                        id="sg-notes"
                        placeholder="Anything the leasing team should know…"
                    />
                </FormField>
            </FormSection>
            <FormSection
                title="Notifications"
                description="What happens when the lease is created."
            >
                <FormField
                    id="sg-welcome"
                    label="Email the tenant a welcome pack"
                    help="Includes the signed agreement, payment schedule and portal invitation."
                    full
                >
                    <Switch id="sg-welcome" v-model="welcomePack" />
                </FormField>
            </FormSection>
            <div class="flex justify-end gap-2 pt-6">
                <Button variant="ghost">Cancel</Button>
                <Button variant="outline">Save as draft</Button>
                <Button>Create lease</Button>
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Command palette</h2>
            <Command class="shadow-overlay max-w-xl rounded-xl border">
                <CommandInput placeholder="Search or jump to…" />
                <CommandList>
                    <CommandEmpty>No results.</CommandEmpty>
                    <CommandGroup heading="Pages">
                        <CommandItem value="invoices"
                            ><FileText />Invoices</CommandItem
                        >
                        <CommandItem value="bank"
                            >Bank reconciliation</CommandItem
                        >
                        <CommandItem value="vat">VAT return</CommandItem>
                    </CommandGroup>
                    <CommandGroup heading="Actions">
                        <CommandItem value="lease"
                            ><Plus />New lease</CommandItem
                        >
                    </CommandGroup>
                </CommandList>
            </Command>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-eyebrow">Buttons</h2>
            <div class="flex flex-wrap items-center gap-3">
                <Button size="lg"><Plus />Primary large</Button>
                <Button>Primary</Button>
                <Button size="sm">Small</Button>
                <Button variant="outline">Outline</Button>
                <Button variant="secondary">Secondary</Button>
                <Button variant="ghost">Ghost</Button>
                <Button variant="destructive-outline">Void</Button>
                <Button variant="destructive">Delete</Button>
                <Button variant="link">Link</Button>
                <Button disabled>Disabled</Button>
            </div>
        </section>
    </div>
</template>
```

- [ ] **Step 5: Run the feature test and confirm it passes**

Run: `docker exec z1erp-web sh -lc 'cd /workspace && php artisan test tests/Feature/StyleguideTest.php'`

Expected: 3 PASS. `ensure_pages_exist` confirms that `resources/js/pages/Styleguide.vue` exists.

- [ ] **Step 6: Run the PHP static analysis and style checks, then the Node gates**

```bash
docker exec z1erp-web sh -lc 'cd /workspace && vendor/bin/pint --test app/Http/Controllers/StyleguideController.php tests/Feature/StyleguideTest.php routes/web.php && vendor/bin/phpstan analyse --memory-limit=1G'
docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -c "npm run check:fix && npm run typecheck && npm run lint"
```

Expected: all exit 0. `check:fix` reflows the long single-line props in `Styleguide.vue`; that's expected.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/StyleguideController.php routes/web.php resources/js/pages/Styleguide.vue tests/Feature/StyleguideTest.php
git commit -m "feat(ui): add local-only Bordeaux styleguide page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Phase verification and visual check

**Files:** none created. This task verifies and reports.

- [ ] **Step 1: Run every frontend unit test**

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 node --experimental-strip-types --test tests/Frontend/
```

Expected: all suites pass. These are `bordeaux-contrast`, `format`, `status-tones`, `navigation`, `chart-paths`, `data-table`, and the existing `crm-pipeline-board`.

- [ ] **Step 2: Run the full quality gates**

```bash
docker run --rm -v "$PWD":/workspace -w /workspace node:22 sh -c "npm run lint && npm run typecheck"
docker exec z1erp-web sh -lc 'cd /workspace && php artisan wayfinder:generate --with-form'
docker run --rm -v "$PWD":/workspace -w /workspace -e WAYFINDER_GENERATED=1 node:22 npm run build
docker exec z1erp-web sh -lc 'cd /workspace && php artisan test'
```

Expected: every command exits 0. Record the PHP test count, then compare it to the baseline. Before any code change, `php artisan test` on commit `39ecd7a` gives the baseline; run it first if it hasn't been recorded. The new count should be the baseline plus 3.

- [ ] **Step 3: Capture screenshots of `/styleguide` for self-review**

The app runs at `http://localhost:8000`. The Playwright image `mcr.microsoft.com/playwright/python:v1.49.0-jammy` is already present locally. Write `/private/tmp/claude-503/-Users-RR-Docker-z1-erp/bf8a1021-d45c-42c3-8ba0-ef840655677b/scratchpad/shots.py`:

```python
from playwright.sync_api import sync_playwright

BASE = "http://host.docker.internal:8000"
EMAIL, PASSWORD = "styleguide@example.test", "password"

with sync_playwright() as p:
    browser = p.chromium.launch()
    for lang in ("ar", "en"):
        for theme in ("light", "dark"):
            context = browser.new_context(viewport={"width": 1440, "height": 900}, color_scheme=theme)
            page = context.new_page()
            page.goto(f"{BASE}/login")
            page.fill("input[name=email]", EMAIL)
            page.fill("input[name=password]", PASSWORD)
            page.click("button[type=submit]")
            page.wait_for_url("**/dashboard")
            page.evaluate(
                """async (locale) => {
                    const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
                    await fetch('/locale', {
                        method: 'POST',
                        headers: { 'X-XSRF-TOKEN': token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ locale }),
                    });
                }""",
                lang,
            )
            page.goto(f"{BASE}/styleguide")
            page.wait_for_load_state("networkidle")
            page.screenshot(path=f"/shots/styleguide-{theme}-{lang}.png", full_page=True)
            context.close()
    browser.close()
```

The script sets the language through the existing `POST /locale` route (`LocaleController`), which also saves the choice on that user.

Before running it, the script needs a login. Ask the owner which existing local user to use, or ask for consent to create one in the local `z1_erp` database. Creating a user, or changing a user's saved language, is a write to a non-test database, so it needs explicit consent under the owner's CLAUDE.md. Never do either without that consent. Put the agreed credentials in `EMAIL`/`PASSWORD`. The script sets English last, so an existing user ends on the language they most likely had; confirm that with the owner.

Then run it:

```bash
docker run --rm --add-host=host.docker.internal:host-gateway -v /private/tmp/claude-503/-Users-RR-Docker-z1-erp/bf8a1021-d45c-42c3-8ba0-ef840655677b/scratchpad:/shots mcr.microsoft.com/playwright/python:v1.49.0-jammy sh -c "pip install -q playwright==1.49.0 && python /shots/shots.py"
```

Open the four PNGs with the Read tool and compare them with `.superpowers/brainstorm/components-gallery.html`. Fix any visual defect in the owning task's files, re-run that task's checks, and amend with a new commit (never `--amend`).

If the owner declines a login, skip the screenshots and say so in the report.

- [ ] **Step 4: Report to the owner**

The report includes:

- the command outputs (pass counts)
- the commit list: `git log --oneline 39ecd7a..HEAD`
- what visibly changes on existing pages: colors, fonts, buttons, cards, inputs, error text, empty states. Page structure does not change until phase 2+.
- the URL to review: `http://localhost:8000/styleguide`, in light, dark and Arabic
- known gaps:
    - the sidebar is still the old flat list until phase 2
    - Arabic wording needs review by a native speaker
- the next step: ask for approval to plan phase 2 (app shell and dashboard)
