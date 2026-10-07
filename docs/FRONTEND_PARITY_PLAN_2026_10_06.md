# Frontend parity plan and tracker — 2026-10-06

Single source of truth for what is done, what is open, and the order we work in. Update the checkboxes in the same commit as the work. Do not start a new item while an earlier item in the same phase is half done.

Reference system (for comparison only): DONUT ERP, brokerage edition, `https://donuterp.com/projects/brokerage3/`. Credentials are held by the owner and are never stored in this repo.

Owners: **FE** = frontend (Claude), **BE** = backend (Codex). Status: `[x]` done and pushed, `[~]` built but not verified in a browser, `[ ]` open, `[!]` blocked.

## Golden rule: parity is additive only

The reference system (DONUT) is a checklist of things we may be missing, not a template to copy. Everything we built in the CRM over the last week stays: Bitrix-style leads (board, filters, import, activities and calendar, automation, products, estimates), the separate deals module with its own pipelines, access permissions, filters, export, automation and finance links, the settings hub, workflows, and company chat. Never remove, rename or replace an existing screen, tab, field, route or flow to match the reference. Add missing tabs and fields next to what exists. If a reference item conflicts with our flow, keep ours and note the difference here for the owner to decide.

## Codex checklist (backend, read before every change)

Written for the backend owner. The frontend builds screens from the contract docs, not from the PHP.

- [ ] Contract documented in `docs/CRM_FRONTEND_BACKEND_*.md`: method and URI, request body, sample response, validation errors, who may call it (`permissions`), and whether it uses `version` / `expected_version`.
- [ ] One route per URI and handler. Use `Route::match([...])` with a single name; two routes to the same action break the generated TypeScript routes and CI.
- [ ] Create responses include `version` and `permissions`.
- [ ] Validation failures return `422 {message, errors:{field:[message]}}`.
- [ ] No route names renamed or removed without telling the frontend.
- [ ] Tests pass on CI conditions: PHP 8.4, fresh MySQL, database `testing`. Do not set `DB_DATABASE` anywhere else.
- [ ] Full test suite, PHPStan and Pint run before pushing.
- [ ] Commit only your own files; stage only your own lines in `routes/web.php`; check `git status` before pushing.
- [ ] Tick the backend item in this plan in the same commit.

## Working rules (so nothing is left half done)

1. One section at a time, in the phase order below. Finish it, verify it, commit it, tick it here, then move on.
2. Definition of done for a screen: lint, vue-tsc and frontend tests pass; the backend route has a feature test; the screen was opened in a browser and the main actions clicked; labels exist in `ar.json`; this file is ticked.
3. If a screen needs backend that does not exist, FE writes the request to Codex first (what, shape, permissions), marks the item `[!]`, and works on a different section until BE confirms.
4. Commit only own files. Shared files (`routes/web.php`, `AGENTS.md`, docs) are edited in small separate commits.
5. Push only when the owner asks. CI must be green before the next phase starts.
6. Every pause ends with the first unticked item written at the bottom ("Next up").

## Phase 0 — verify and stabilise (before more features)

- [x] FE: browser check (2026-10-06, headless Chromium against a seeded `testing` database, desktop 1440 and phone 390): 44 screens load with no console errors, failed requests or horizontal scroll; 22 click-through flows pass (create contact, tax, product, currency, selection option, calendar save, field tabs and add, deal filters/picker/move/transfer dialogs, deal and lead tabs, estimate from workflow board, invoice/procurement/maintenance panels, AMC tabs, deal automation rule). Bugs found and fixed: startup `locale` TypeError on every page, catalog save crash on number inputs, `pattern` attribute rejected by Chromium, `crypto.randomUUID` on plain-http hosts, Deal pipelines and Access permissions tiles not linked. Still unchecked: logged-out portal screens, Arabic/RTL layout, drag-and-drop on the deal board, file uploads.
- [x] CI green on `main` (MySQL service, PHP 8.4, memory limit, route duplicates, testing database).
- [!] FE+BE: `composer.json` says `^8.3` but the lock needs 8.4.1. Changing it needs `composer update --lock` to refresh the lock hash, so Codex should do it with a composer run.
- [!] Owner: removing omniroute from Claude, Codex and VS Code needs consent per config file (outside the project); skipped until given.
- [x] FE: Arabic strings for every `t('…')` string in the app (698 added, `2e60a82`). Arabic/RTL layout itself still needs a visual pass.
- [~] BE→FE: calendar/provider version checks implemented and documented in `docs/CRM_FRONTEND_BACKEND_SETTINGS_LOCKING_2026_10_06.md`; calendar page sends `expected_version`. Weekday keys remain ISO **1–7**, not day names. Backend verified locally; browser acceptance and push pending.

## Phase 1 — CRM completeness (biggest visible gaps)

### 1A. Lead detail tabs (reference: 9 tabs; ours: 4)

Ours today: General, Activities, Products, History. Reference: Overview, Requirement, Matched Properties, Activities, Follow-Up Timeline, Tasks, Meetings/Viewings, Offers/Contracts/Deal, Accounting Link.

- [~] BE: backend ready — versioned lead requirement fields (type, purpose, unit category, emirate, property type, location, bedrooms min/max, bathrooms min, furnishing, size min/max, budget min/max, rent frequency, timeline, ready/off-plan, handover, payment method, down payment %, ROI %, financing status, language, amenities, preferences) + lead score and temperature. Organization-editable choices and contract: `docs/CRM_FRONTEND_BACKEND_LEAD_REQUIREMENTS_2026_10_06.md`. Local migration applied; PHP 8.4 isolated MySQL suite 484 tests / 7,140 assertions, PHPStan, Pint, route generation and TypeScript passed. Push pending.
- [x] FE: Requirement tab (5 sections, partial saves with version, inline errors) and Settings > Requirement options editor. Browser-checked 2026-10-06.
- [~] BE: matched properties API ready — tenant-scoped saved matches, ranked suggestions, dynamic match %, manual overrides, internal shared flag, viewing progress, versioned edits/removal and organization-editable viewing statuses. Community is `null` until Phase 2 listing data exists. Contract: `docs/CRM_FRONTEND_BACKEND_LEAD_MATCHES_2026_10_07.md`. Local migration applied; PHP 8.4 isolated MySQL suite 489 tests / 7,294 assertions, PHPStan, Pint, route generation and TypeScript passed. Browser acceptance and push pending.
- [x] FE: Matched Properties tab (saved matches with score and viewing status, ranked suggestions with search, add/edit/remove with versions) and Settings > Viewing statuses. Browser-checked 2026-10-07.
- [ ] FE: Follow-Up Timeline tab (re-use follow-ups already built).
- [x] BE: lead-linked tasks JSON API on existing `/tasks` records, with scoped list/create/edit/complete, record permissions, version locking and audit. Contract: `docs/CRM_FRONTEND_BACKEND_LEAD_SCHEDULE_2026_10_07.md`. FE tab integration remains.
- [x] BE: lead-linked meetings/viewings JSON API on existing appointments, with scoped list/create/edit/complete/cancel, record permissions, version locking and audit. Same contract. Local migration applied; full PHP 8.5 MySQL suite passed (492 tests / 7,351 assertions), PHPStan and Pint passed. FE tab integration remains.
- [ ] BE+FE: Offers, Contracts and Deal tab (linked deal, contracts, offer submitted) — deal link exists, rest needs BE.
- [ ] BE+FE: Accounting Link tab (documents, amount, VAT, total).
- [x] FE: lead header "Mark lost" (opens the stage move dialog on the lost stage) and "WhatsApp" (wa.me link, also on contacts). In-app WhatsApp sending still needs credentials from 3C. Browser-checked 2026-10-06.
- [x] Products tab and linked estimates; "New estimate" from lead.

### 1B. Contacts and companies

- [~] BE done (`47e4d9d`): list, detail, edit with `expected_version`, custom fields.
- [x] FE: contacts list upgrade (company link, People tabs), contact detail page (General/Leads/Deals/Activities), edit with version check.
- [x] FE: companies list (server search and paging), company detail (General/Contacts/Leads/Deals/Activities), create, edit.
- [x] FE: show and edit contact and company custom fields (all 13 field types). Browser-checked on 2026-10-06 (7 flows).

### 1C. Deals

- [x] Board/list, create, edit, move, detail, history, filters, export, automation rules, qualified-lead picker, pipeline transfer, finance links (all `[~]` browser-unchecked).
- [ ] BE+FE: reference deal status strip (Submitted, Approved, Contract In Progress/Signed, Invoice Generated, Payment Pending/Received, Commission Calculated/Approved/Paid, Disputed, Clawback Required, Refund Required) — decide whether these become default deal-pipeline stages or a separate commission status.
- [ ] BE+FE: deal fields scenario, co-broker share, agent share, gross commission.

### 1D. Settings hub

- [x] Pipelines, deal pipelines, permissions, selection lists, currency, locations, numbering, taxes, units, templates, company details, mailboxes, products, field list (4 entities), working calendar.
- [x] FE: "Payment systems" tile and page on the providers reference-settings endpoint with version checks (browser-checked 2026-10-06).
- [x] FE: "Other settings" tile and page on the other-settings catalog endpoint with version checks (browser-checked 2026-10-06).
- [!] CRM applications market — out of backend scope (Codex).

## Phase 2 — property and transaction sections (reference gaps)

Each item = FE screen change plus BE fields. Do one section completely, then the next.

- [ ] 2A Property & Listings: 13-status tab strip, filters (cost centre, status, category, agent, community, sort), table columns, create form fields (listing category, unit category, building, emirate, community, sub-community, unit/floor, Trakheesi/DLD permit, bedroom type, bathrooms, balconies, parking, size, plot size, furnishing, completion status, handover date, grade, loading bay, fit-out, price type/price/range/label, price per sq ft auto, owner/developer, portals), emirate summary table.
- [ ] 2B Secondary Market: valuation price, mortgage and NOC status, transfer status, seller/buyer, status tabs.
- [ ] 2C Leasing & Rental: tenancy number, renewal date, Ejari, security deposit, advance, renewed/move-out/renewal-due actions, cheque linking.
- [ ] 2D Off-Plan: units total/available/sold, starting price, commission %, launch/handover dates, assigned agent, status tabs.
- [ ] 2E Owners & Developers: payment terms, commission notes, linked listings, edit.
- [ ] 2F Agents & Commission: clawback, net contribution, team view.
- [ ] 2G AI Matchmaker: AI settings (active, auto-qualify threshold), start agent, closed-by.

## Phase 3 — marketing, portals, procurement, corporate

- [ ] 3A Marketing campaigns: status strip (Budget Submitted … Underperforming), vendor/portal, assigned agent.
- [ ] 3B Portals: listing sync (status tabs, last sync, run sync), subscriptions (package, credits, auto-renew, expiring soon), invoicing (generate invoice), costing figures.
- [ ] 3C Follow-Up Automation: Templates / Rules / Credentials tabs, SMTP and WhatsApp credentials, send test. (BE needed.)
- [ ] 3D Broker allocation (home branch, primary/co-broker, bulk reallocate) and broker performance (period, recalculate, stale listings).
- [ ] 3E Procurement vendors: ~18 vendor fields (trade name, type, subcategory, supply type, addresses, trade licence, bank, IBAN, SWIFT, payment terms, credit limit, default expense account).
- [ ] 3F Meetings (type, outcome notes, next task), Tasks (completion remarks, linked record), Contracts (type, party, value), HR (expiry, days left, monthly cost).
- [ ] 3G GAIM compliance dashboard and bulletins (AI summary, affected forms, mark actioned).

## Phase 4 — settings parity

- [ ] Master data: 17 tables (locations/communities, emirates, bedroom types, property types, party roles, meeting types, activity types, priority levels, module registry, lead lost reasons, financing statuses, purchase timelines, languages, financing methods, amenities, furnishing types, broker performance weights).
- [ ] Cost centres full path, chart of accounts parent account, roles matrix "Confidential" column, document numbering module keys.
- [ ] Companies, branches, departments, users screens (compare field by field).

## Phase 5 — remaining restyle of old-layout pages

Already restyled: invoices, vendor bills, vendor cash refunds, outstanding balances header, maintenance, helpdesk, preventive maintenance, AMC, scheduled reports, fleet list, inventory imports, procurement, people, compliance docs, brokerage, reservations, agreements, contacts.

- [x] Done: Construction index, Notifications inbox, HR profile header (browser-checked).
- [x] Done (browser-checked 2026-10-06): Handovers (tabs, plan panel, dialogs), Lease compliance (5 section tabs), Spare parts (4 tabs), Construction project (4 tabs), Fleet vehicle (3 tabs).
- [x] Done (2026-10-07, browser-loaded): Signatures, Document versions, API tokens, Organization activity, Organization settings (Members/tax tabs), Portal access (2 tabs), Operations overview and reports headers; the remaining 22 pages that used the old h2 Heading (accounting, inventory, portal, listings, search, job card) now use the standard h1 PageHeader.
- [ ] Remaining: Real-estate listings body restyle (with 2A), Accounting pages body restyle (reports; header unified), Portal pages, Search results layout.

## Built, not browser-checked (all `[~]`)

CRM leads/board/import/activities/automation; pipeline editor; deals (board, list, detail, filters, export, picker, transfer, finance, automation); permissions; settings hub and all settings tables; field list tabs; workflows (boards, pipelines, stage rules, estimate from lead); company chat; every restyled list page above.

## Backend requests outstanding for Codex

1. Lead requirement fields and lead score (1A): backend ready; frontend integration/browser acceptance pending (contract linked above).
2. Matched-properties API with match % and auto-suggest (1A): backend ready; frontend integration/browser acceptance pending (contract linked above).
3. Lead-linked tasks and meetings; offers/contracts on a lead (1A).
4. Deal commission statuses and co-broker/agent share fields (1C).
5. Listing, secondary, lease, off-plan field additions (Phase 2).
6. Portal sync fields, credentials storage, templates/rules (Phase 3).
7. Master-data tables (Phase 4).

## Next up

BE: Phase 1A offers/contracts on a lead. FE: Matched Properties, Tasks, and Meetings/Viewings tab integration and browser acceptance (backends ready). Push only when the owner asks.
