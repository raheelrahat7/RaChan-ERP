# Bordeaux frontend redesign: design spec

- **Date:** 2026-09-28
- **Status:** Awaiting owner review
- **Scope:** Frontend redesign of the Z1 ERP application, customer portal and public listing page, and a new marketing website. The only backend change is one small endpoint: the website's demo request creates a CRM lead.
- **Visual references:** `.superpowers/brainstorm/design-directions-v3.html` (the Bordeaux direction) and `.superpowers/brainstorm/components-gallery.html` (components and page patterns). These approved mockups are the visual source of truth. Where this document and a mockup disagree, raise it with the owner before building.

## 1. Context and goals

The backend of every module is complete. The frontend is still the stock Laravel Vue starter:

- a grayscale `neutral` theme with Instrument Sans
- a flat 24-link sidebar in which the same four icons repeat
- 74 Inertia pages, about 20,000 lines in total, several over 800 lines
- 22 shadcn-vue components
- a placeholder `Welcome.vue`

Z1 ERP is sold as SaaS to real-estate companies in the Gulf. Arabic ranks equally with English.

**Goals**

1. A premium, luxury, clean and sleek brand called **Bordeaux**, first-class in both light and dark.
2. A consistent design system applied to all 74 pages, with no change to behavior.
3. Full English/Arabic parity, including RTL.
4. A marketing website that sells Z1 ERP and turns visitors into CRM leads.
5. WCAG 2.2 AA throughout, with every quality gate green.

**Non-goals**

- Changing business logic, workflows, permissions, database schema or data.
- Inventing product facts: customer logos, testimonials, statistics or prices.
- Adding npm dependencies without separate approval. This includes calendar/date libraries and chart libraries.
- Enabling SSR. It is recommended as a later step.

## 2. Brand: the Bordeaux direction

Deep burgundy carries the brand: primary actions, the light-mode navigation rail and charts. Champagne gold is kept for the finest details: the logo mark, the active navigation marker, the notification dot and focus rings. The base is porcelain with a blush tone in light mode, and black with a wine undertone in dark mode. Luxury comes from restraint:

- generous whitespace
- hairline dividers instead of boxed cards
- spaced-out small-caps labels
- serif display type
- status shown as a dot plus text rather than a colored pill
- tight corners

### 2.1 Color tokens

The tokens are defined in `resources/css/app.css` as CSS variables on `:root` and `.dark`, then exposed to Tailwind through `@theme inline`. The existing shadcn-vue token names are kept, so current components pick up the theme without being rewritten.

| Token                      | Light                                                                                    | Dark                                                  | Use                                                                                                               |
| -------------------------- | ---------------------------------------------------------------------------------------- | ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `--background`             | `#F8F4F1`                                                                                | `#0E0A0B`                                             | Page                                                                                                              |
| `--surface-sunken` (new)   | `#F0EAE6`                                                                                | `#150F11`                                             | Inputs, segmented controls, search                                                                                |
| `--card` / `--popover`     | `#FDFBFA`                                                                                | `#181214`                                             | Panels, menus, dialogs                                                                                            |
| `--border`                 | `rgba(60,16,24,.10)`                                                                     | `rgba(245,225,228,.09)`                               | Hairlines                                                                                                         |
| `--input`                  | `rgba(60,16,24,.18)`                                                                     | `rgba(245,225,228,.16)`                               | Control borders                                                                                                   |
| `--foreground`             | `#1E1215`                                                                                | `#F1E9E7`                                             | Text                                                                                                              |
| `--muted-foreground`       | `#6F6064`                                                                                | `#A8989B`                                             | Secondary text                                                                                                    |
| `--faint` (new)            | `#A6989B`                                                                                | `#6A5C5F`                                             | Decoration only: disabled marks, neutral status dots. Never readable text (placeholders use `--muted-foreground`) |
| `--primary`                | `#6B1B2A`                                                                                | `#9E2F45`                                             | Primary buttons, brand                                                                                            |
| `--primary-hover` (new)    | `#58141F`                                                                                | `#B03750`                                             | Primary hover                                                                                                     |
| `--primary-foreground`     | `#FBF6F3`                                                                                | `#FFF4F1`                                             | Text on primary                                                                                                   |
| `--champagne` (new)        | `#C9A27A`                                                                                | `#D2AE86`                                             | Fine details                                                                                                      |
| `--accent-text` (new)      | `#7A2233`                                                                                | `#D9B892`                                             | Eyebrows, links                                                                                                   |
| `--ring`                   | `#9A7440`                                                                                | `#D2AE86`                                             | Focus rings (light mode uses a darker bronze to reach 3:1 against the background)                                 |
| `--success` (new)          | `#3E6E52`                                                                                | `#8DC3A1`                                             | Paid, active                                                                                                      |
| `--info` (new)             | `#2F5E86`                                                                                | `#8DB5DC`                                             | Partial, in progress                                                                                              |
| `--warning` (new)          | `#8C5F18`                                                                                | `#DDB46E`                                             | Due soon, pending                                                                                                 |
| `--destructive`            | `#B23A1A`                                                                                | `#F08A70`                                             | Overdue, errors. Orange-red, so it is never confused with the brand burgundy                                      |
| `--destructive-foreground` | `#FFFFFF`                                                                                | `#1E1215`                                             | Text on destructive buttons (dark text in dark mode for contrast)                                                 |
| `--sidebar`                | `#4A1320`                                                                                | `#140C0E`                                             | Navigation rail                                                                                                   |
| `--sidebar-foreground`     | `#F6ECE8`                                                                                | `#F1E9E7`                                             |                                                                                                                   |
| `--sidebar-muted` (new)    | `#C49CA3`                                                                                | `#9A878B`                                             | Group labels                                                                                                      |
| `--sidebar-accent`         | `rgba(255,255,255,.09)`                                                                  | `rgba(255,255,255,.05)`                               | Active item                                                                                                       |
| `--sidebar-border`         | `rgba(255,255,255,.10)`                                                                  | `rgba(255,255,255,.06)`                               |                                                                                                                   |
| `--chart-1…5`              | burgundy `#7A2233`, champagne `#B08D57`, slate `#5B6B7A`, sage `#6E8B74`, rose `#C27C8A` | `#C75A6E`, `#D2AE86`, `#9AA8B6`, `#9DBBA3`, `#E3A5B1` | Charts                                                                                                            |

Shadow tokens are `--shadow-panel` and `--shadow-overlay`, with values as in the mockups. Before merging, every text/background pair used for readable text is checked for WCAG AA contrast (4.5:1 for body text, 3:1 for large text and UI outlines) in both themes. Any failure is fixed in the token, never page by page.

### 2.2 Typography

Fonts load through the existing `laravel-vite-plugin/fonts` (Bunny) setup in `vite.config.ts`. No new dependency is needed.

| Role      | Latin                  | Arabic               | Where                                                             |
| --------- | ---------------------- | -------------------- | ----------------------------------------------------------------- |
| Interface | Geist                  | IBM Plex Sans Arabic | All UI text                                                       |
| Display   | Cormorant Garamond 500 | Noto Naskh Arabic    | Page and section titles, KPI figures, totals, marketing headlines |

The following utilities are added in `app.css`:

- `font-display`
- `text-eyebrow`: 10.5px, 0.2em letter spacing, uppercase, `--accent-text`
- `text-label`: 10.5px, 0.16em letter spacing, uppercase, muted
- `tabular-nums lining-nums` for all money and quantities

Arabic text gets a line height of about 1.7. Uppercase and letter spacing are switched off under `[dir=rtl]`, because they don't apply to Arabic script.

### 2.3 Shape, motion and focus

- **Radii:** panels 6px, controls 3–4px, dialogs 8px, avatars and status dots round.
- **Motion:** 150–200ms ease-out for hover and open states, with no decorative animation. Everything respects `prefers-reduced-motion`.
- **Focus:** a visible champagne focus ring on every interactive element in both themes.

### 2.4 Theming and direction

- The existing light/dark/system switcher (`useAppearance`, cookie, no-flash inline script) is unchanged. Only the inline `html` background colors in `resources/views/app.blade.php` are updated to Bordeaux.
- Direction-dependent styles use logical properties (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`). Chevrons and arrows mirror under RTL.
- Numbers use Western digits in both languages, the Gulf business norm. Currency formats as "AED 212,000" in English and "212,000 د.إ" in Arabic.

## 3. App shell and navigation

### 3.1 Grouped navigation

The navigation config moves to `resources/js/lib/navigation.ts` as typed data, so it can be tested. `AppSidebar.vue` renders it with shadcn `Collapsible`. Each item gets a distinct Lucide icon.

| Group                                 | Items (route)                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Overview                              | Dashboard (`/dashboard`), Search (`/search`), Notifications (`/notifications`)                                                                                                                                                                                                                                                                                                                                                                         |
| CRM                                   | Leads (`/crm/leads`), Contacts (`/crm/contacts`), Pipelines (`/crm/pipelines`), Pipeline report (`/crm/pipeline-report`), Assignment (`/crm/assignment`), Hierarchy (`/crm/hierarchy`)                                                                                                                                                                                                                                                                 |
| Portfolio                             | Inventory (`/inventory`), Listings (`/real-estate/listings`), Owners, tenants & brokers (`/real-estate/people`), Brokerage (`/real-estate/brokerage`)                                                                                                                                                                                                                                                                                                  |
| Leasing & sales                       | Reservations (`/reservations`), Agreements (`/agreements`), Handovers (`/handovers`), Lease compliance (`/lease-compliance`)                                                                                                                                                                                                                                                                                                                           |
| Finance                               | Invoices (`/invoices`), Vendor bills (`/vendor-bills`), Vendor refunds (`/finance/vendor-cash-refunds`), Bank reconciliation (`/bank-reconciliation`), Accounting ▸ [Overview (`/accounting`), Journal register, Statements, VAT return, Corporate tax, Budgets, Fixed assets, Outstanding balances, Activity, Audit trail, Legacy mappings], Property performance (`/reports/property-profitability`), Owner statements (`/reports/owner-statements`) |
| Operations                            | Overview (`/operations`), Maintenance, Preventive maintenance, Helpdesk, AMC, Spare parts, Projects, Fleet, Reports, Scheduled reports, Compliance documents, Signatures                                                                                                                                                                                                                                                                               |
| Procurement                           | Procurement (`/procurement`)                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Administration (pinned at the bottom) | Organization, Activity, Portal access, API tokens, Settings                                                                                                                                                                                                                                                                                                                                                                                            |

Sub-routes under Accounting and Operations map to their existing `/accounting/*` and `/operations/*` paths. The implementation plan lists each one against `routes/web.php`.

### 3.2 Behavior

- **Light mode:** burgundy rail. **Dark mode:** wine-black rail.
- The header shows the Z1 mark and the current organization name, from the shared `organization` prop.
- The group containing the current URL opens automatically, and the other groups' open state is remembered in `localStorage`.
- Collapsed icon-only mode shows labels as tooltips. On mobile the navigation opens as a sheet.
- In Arabic the sidebar sits on the right. This works today and must be kept.
- **Top bar:** breadcrumbs, a ⌘K / Ctrl+K `CommandPalette`, a notifications bell and the user menu.
    - The command palette searches navigation items and a few quick actions, then falls through to `/search?q=`.
    - The user menu gains the appearance and language switches.

### 3.3 Known backend gaps (flagged, not built)

- **Permissions** aren't shared with the frontend, so every user sees every link. The server still enforces access.
- **No unread-notification count** is shared, so the bell has no badge.

Both need one small shared prop in `HandleInertiaRequests`, which is a separate backend task for Codex.

## 4. Components and page patterns

### 4.1 New shadcn-vue primitives

These go in `resources/js/components/ui`, built on the existing reka-ui, so no new npm packages are needed: table, tabs, popover, command, textarea, switch, progress, scroll-area and hover-card. All existing primitives are restyled to Bordeaux through tokens plus small variant changes, for example button radius and heights 30/36/44px.

### 4.2 Z1 building blocks

These live in `resources/js/components`, with logic in `composables` or `lib`.

| Unit                       | Responsibility                                                                                                                                                     | Depends on                             |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------- |
| `PageHeader`               | Eyebrow, display title, description, actions slot                                                                                                                  | none                                   |
| `StatTile`                 | Label, display figure with prefix and suffix, trend, optional `Sparkline`                                                                                          | `Money`                                |
| `DataTable`                | Hairline table, sticky header, sort indicators, selectable rows, bulk-action slot, numeric alignment, built-in empty, loading and error states, wraps `Pagination` | ui/table, `EmptyState`, `LoadingState` |
| `FilterBar`                | Search input, filter chips, clear, result count                                                                                                                    | ui/popover                             |
| `StatusDot`                | Dot plus translated label for a canonical status                                                                                                                   | `lib/status-tones.ts`                  |
| `lib/status-tones.ts`      | One map from every backend status value to tone and label key                                                                                                      | none (unit-tested)                     |
| `Money`, `DateText`        | Locale-aware display                                                                                                                                               | `composables/useFormat.ts`             |
| `useFormat`                | `money()`, `number()`, `date()`, `relative()` through `Intl`, with Western digits and the current locale                                                           | `useLocale` (unit-tested)              |
| `FormSection`, `FormField` | Two-column section layout, label, optional marker, help text, error from `InputError`                                                                              | ui/label                               |
| `DetailLayout`             | Record header with status, key-facts strip, tabs slot                                                                                                              | ui/tabs                                |
| `Sparkline`, `AreaChart`   | Lightweight SVG charts in the tokens' chart colors                                                                                                                 | none                                   |
| `CommandPalette`           | ⌘K palette                                                                                                                                                         | ui/command, `lib/navigation.ts`        |

`EmptyState`, `LoadingState` and `ConfirmationModal` already exist and are restyled to the gallery.

### 4.3 Page conversion rules

1. Keep every prop, form field name, route, request payload, permission check and translation key exactly as it is.
2. Replace ad-hoc headers, tables, status badges and money formatting with the building blocks above.
3. Pages over about 400 lines are split into module-owned subcomponents, for example `resources/js/components/finance/fixed-assets/*`, without changing behavior.
4. Every visible string goes through `t()`. New strings go into `resources/js/locales/ar.json`, followed by `php scripts/sync-arabic-catalog.php`.
5. Every list has designed empty, loading and error states.

### 4.4 Dependencies deferred

- **Dates:** a calendar date picker needs `@internationalized/date`. Styled native date inputs are used until that is approved.
- **Charts:** a charting library is proposed only if the reporting pages outgrow the SVG components.

## 5. Marketing website

### 5.1 Pages

The pages live in `resources/js/pages/marketing` and use a new `MarketingLayout`: header with a language switch and "Sign in", plus a footer.

| Page           | Route                | Content                                                                                                                                                                                        |
| -------------- | -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Home           | `/` for guests       | Dark-first wine hero; module showcase built from real components; "Built for the Gulf" section (VAT, Arabic, AED/SAR, bilingual portal); security and reliability section; demo call-to-action |
| Platform       | `/platform`          | One section per module: CRM, Portfolio, Leasing & sales, Finance, Operations, Customer portal                                                                                                  |
| Pricing        | `/pricing`           | Plan names and features, with "Contact sales" and no figures unless the owner provides them                                                                                                    |
| Book a demo    | `/demo`              | Demo request form                                                                                                                                                                              |
| Privacy, Terms | `/privacy`, `/terms` | Content is clearly marked as a draft for legal review                                                                                                                                          |

Signed-in users who visit `/` are still redirected to `/dashboard`. The static pages are registered with `Route::inertia`. Copy is written in English and Arabic, and all copy is reviewed by the owner.

### 5.2 Demo request creates a CRM lead (the only backend change)

- **Config:** a new `config/marketing.php` with `lead_organization` (a slug), read from `MARKETING_LEAD_ORGANIZATION`. A safe placeholder goes in `.env.example`. If the setting is unset or the organization is missing, the form returns a friendly error and logs a warning. It never fails silently.
- **Controller:** `app/Http/Controllers/MarketingDemoRequestController.php` with a `DemoRequest` form request.
    - Fields: first name, last name, work email (required), phone, company (required), role, portfolio size (a choice), preferred language, message.
    - A `website` honeypot must be empty.
    - The route is throttled with `throttle:5,1`.
- **Action:** `app/Domain/Crm/Actions/RecordDemoRequest.php` calls `ManageLeadPipeline::createPublicInquiry()`. The lead gets `source = 'Website demo request'` and `campaign_name = 'Marketing website'`. Role, portfolio size and language are appended to `notes`.
    - `createPublicInquiry()` gains an optional history-note parameter. It defaults to the current text, so existing listing enquiries behave exactly as before.
    - Leads are auto-assigned by the existing routing rules.
- **No migration.** Every field used already exists on `crm_leads`.
- **Tests:** feature tests for the happy path (lead created in the configured organization with the right source), validation, the honeypot, the throttle, and a missing configuration.

### 5.3 SEO

Inertia renders in the browser, so marketing pages are indexed poorly. SSR is already stubbed in `config/inertia.php`. Enabling it is a recommended follow-up and is outside this spec.

## 6. Delivery phases

Each phase needs the owner's explicit approval before it starts, and ends with a report: what changed, the screens to check in light, dark and Arabic, known gaps, and the next phase.

| #   | Phase                                  | Contents                                                                                                                                                                                       |
| --- | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Foundation                             | Tokens, fonts, utilities, restyled primitives, new primitives, building blocks, `useFormat`, `status-tones`, `navigation.ts`, and a `/styleguide` page registered only when `app()->isLocal()` |
| 2   | Shell & dashboard                      | Sidebar, top bar, command palette, user menu, `Dashboard.vue`, auth layouts                                                                                                                    |
| 3   | Finance                                | 18 pages; FixedAssets, OperatingBudgets and Accounting first                                                                                                                                   |
| 4   | Leasing & sales + Portfolio            | transactions, real-estate and inventory pages                                                                                                                                                  |
| 5   | CRM                                    | Includes the pipeline board and its editors                                                                                                                                                    |
| 6   | Operations                             | Includes JobCard, maintenance, fleet and reports                                                                                                                                               |
| 7   | Remaining staff pages                  | Procurement, construction, documents, notifications, search, organization, settings, auth                                                                                                      |
| 8   | Customer portal + public listing       | Quieter Z1 branding, as these are shown to customers' clients                                                                                                                                  |
| 9   | Marketing website + demo lead endpoint | Section 5                                                                                                                                                                                      |

## 7. Verification

- **Every phase**, run through Sail with the output reported: `npm run lint`, `npm run typecheck`, `npm run build`, `./vendor/bin/sail artisan test`. The tests use only the dedicated test database.
- **New unit tests** in `tests/Frontend/*.test.mjs` (`node:test`) for `status-tones`, `useFormat` formatting in both locales, and navigation-config integrity: every item has a route, an icon and a label key, and there are no duplicate routes.
- **Existing PHP feature tests** confirm that every page still renders with its props. Any test that asserts markup or copy is updated only when the change is intended.
- **Visual review:** the owner reviews at `http://localhost:8000` in light, dark and Arabic. Optionally, Claude captures screenshots with a throwaway `docker run --rm` Playwright container to check its own work first.

## 8. Risks

- **Scale:** 74 pages is a lot. Phasing by module keeps each review small, and the building blocks keep pages consistent.
- **Arabic typography:** serif display in Arabic uses Noto Naskh. An Arabic-speaking reviewer should confirm terminology and rendering, as `ARABIC_FIRST_RELEASE_SCOPE.md` already requires.
- **Page tests tied to markup:** some feature tests may assert exact text. Those are fixed as they surface, and each fix is reported.
- **No git repository:** changes can't be committed or diffed by phase. Initialising git before phase 1 is strongly recommended.
