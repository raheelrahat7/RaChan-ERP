# CRM leads backend handoff — 5 October 2026

The user requested backend completion against the eleven supplied CRM reference screenshots while Claude implements the frontend. This increment stays in CRM and preserves organization isolation, assignee/hierarchy visibility, explicit export grants, and the existing customer-conversion workflow. It does not copy the reference account's personal records or invent its N7 field definitions.

## Reference coverage

| Reference                        | Backend contract                                                                                                                                                                                                                                                                                                                                                                                   |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| New lead and lead view           | Existing audited creation/editing/custom values; optional `assigned_to` on creation is organization- and visibility-checked. Details now include assignment, project/campaign/Meta fields, stage time, lost reason, editable custom keys, required flags and select options. Existing explicit conversion and stage movement routes are retained.                                                  |
| Kanban                           | Existing pipeline overview supplies scoped counts, permitted currency-field totals, stage history and blocked transition reasons; movement still requires `expected_stage_id` and an active pipeline-owned lost reason where applicable.                                                                                                                                                           |
| Activities and calendar          | Filtered activity lists and an organization-local six-lane board are available. Full activity records can be paged through the JSON endpoint rather than relying on the ten-item recent-activity summary.                                                                                                                                                                                          |
| Filter search and field settings | Catalog includes supported built-ins and visible custom fields. Full-name search, typed values, whole local-date ranges, history exclusion, exact multi-select membership and same-activity combined predicates are enforced. Private saved filters and field selections have organization/user-scoped persistence.                                                                                |
| History                          | Audited custom-field edits/clears, stage changes, assignment, activity creation/rescheduling/completion, automation and throttled views; pagination retains access to older events. Audit details do not copy custom values or activity notes.                                                                                                                                                     |
| Import leads and field mapping   | CSV preview supports encoding, separator, optional headers, empty-column removal, name order, editable custom-field mapping, import-specific required fields and default assignee. Duplicate matching checks normalized email OR phone, including successful earlier rows; failed rows do not reserve identities. Creator-only batches remain encrypted, expire after one day and are replay-safe. |
| Automation and rule picker       | Owner/admin rules support lead-created or stage-entered triggers, supported field conditions, immediate/delayed execution, internal assignee or scoped manager alerts, selectable follow-up types and guarded nonterminal stage changes. Recent execution outcomes are returned for diagnostics.                                                                                                   |

## Frontend integration contracts

All endpoints require authenticated, verified organization membership. JSON calls should send `Accept: application/json`; normal Inertia mutation routes continue redirecting back and use Laravel validation errors.

### Lead list and detail

`GET /crm/leads` continues accepting `pipeline_id`, `stage_id`, `assignee_id`, `q`, `page` and `filters[]` clauses (`field`, `operator`, `value`, optional `to`). It additionally returns:

- `activityBoard`: `{timezone, as_of, lanes}`. Each lane has `{key, total, leads, page, per_page: 20}`. Keys are `overdue`, `due_today`, `due_this_week`, `due_next_week`, `idle`, `due_later`. Each unconverted lead appears once, classified by its earliest open scheduled activity. Idle means no open scheduled activity. Weeks end Sunday. Counts cover all filtered records, independently of the visible page.
- `leadPreferences`: `{selected_field_keys: string[] | null, presets: [{id, name, state}]}`. `null` means use the frontend's default field set; an empty array is an intentional empty selection.
- `activity_page` chooses the page for each activity-board lane independently of lead-list pagination.

`GET /crm/activities` accepts the same selection/filter parameters plus `activity_page`, and returns `{board, activities}`. `activities` is Laravel's 50-record paginator with totals and page links; each record includes its subject lead, creator, completion date and `updated_at`. The list page's existing `activities` prop remains a recent ten-item summary for compatibility. Its existing `followUps` and `activities` now obey the selected pipeline, stage, assignee and lead-field filters.

`GET /crm/leads/{lead}` adds `history_q`, `history_event`, `history_page` query parameters and returns `historyFilters` and `historyPagination: {total, page, per_page: 100}` alongside `timeline`. `history_q` searches audit event names and actors; `history_event` is an exact event key such as `crm.activity.updated`. Keep the existing lead details, stage and activities props.

### Saved filters and field selection

- `GET /crm/leads/preferences` returns the current user's preferences for the current organization.
- `POST /crm/leads/preferences` accepts `selected_field_keys?: string[]` and/or `{name, state}`. `state` may contain `pipeline_id`, `stage_id`, `assignee_id`, `q`, `filters`; saving a named filter adds a server-generated UUID. At most 20 named filters are retained.
- `DELETE /crm/leads/preferences/{presetUUID}` removes only the caller's preset.

These return the updated preference object. Field access is checked at save and read; presets referencing revoked/archived fields are excluded. Filter criteria are encrypted at rest. Replace browser-global localStorage storage with these endpoints to retain settings across devices without sharing them between organizations.

### Activity edits

`PUT /crm/activities/{activity}` accepts required `expected_updated_at` (the returned ISO timestamp) and any of `type`, `notes`, `due_at`. A naive datetime is interpreted in the organization timezone; an explicit offset is respected, and storage uses UTC. Stale edits return a validation error under `activity`; completed activities and converted leads reject edits. Due-date changes are audited with old/new timestamps; notes are not copied into the audit log. Existing create and complete endpoints remain unchanged.

### CSV import

`POST /crm/leads/import/preview` accepts a `file` plus optional:

| Key                  | Accepted values / default             |
| -------------------- | ------------------------------------- |
| `encoding`           | `UTF-8` (default), `Windows-1252`     |
| `delimiter`          | `comma` (default), `semicolon`, `tab` |
| `has_header`         | boolean, default true                 |
| `skip_empty_columns` | boolean, default false                |
| `name_format`        | `first_last` (default), `last_first`  |

The import page returns `sourceOptions`, `members`, `sampleUrl`, and batch `source_settings`/`expires_at` in addition to existing preview/mapping/result props. `GET /crm/leads/import/sample` downloads a synthetic sample CSV.

`POST /crm/leads/import/{batch}/commit` retains `pipeline_id`, `mapping`, `duplicate_mode: skip|allow`, `required_targets` and adds optional `assigned_to`. The mapping direction is source header → target key. Headerless columns are named `Column 1`, `Column 2`, etc. `full_name` uses the preview's chosen name order. Files remain capped at 2 MB, 100 columns, 1,000 nonempty data records. Result row numbers include skipped blank CSV records. Existing pre-increment previews retain their old defaults.

### Automation

Existing `/crm/automation` create/update/disable routes remain. Save payloads add:

- `delay_minutes`: integer 0–525600, default 0; elapsed time from stage entry, independent of business hours.
- `activity_type`: `call`, `email`, `meeting`, `task`, `note`, default `task`, for `create_follow_up`.
- `action`: `notify_assignee`, `notify_managers`, `create_follow_up`, `change_stage`.
- `target_stage_id`: required for `change_stage`; must belong to the pipeline and be active/nonterminal. This action never performs customer conversion.

`due_days` remains required for a follow-up (0–365); the follow-up becomes due that many days after execution. An `email` activity is a CRM work item, not outbound email delivery.

The automation page additionally returns `actionCatalog`, `activityTypes`, and up to 100 `executionHistory` rows with outcome, schedule and safe failure reason. Pending executions freeze configuration and the notification assignee. Editing or disabling the rule cancels old pending work. Creator owner/admin authority, pipeline activity, current stage-entry identity and conditions are checked again at execution. Assignment changes cancel frozen assignee notices; manager recipients must currently see the lead. Archived condition fields do not match. Automatic moves enforce current entry rules, preserve stage history, run existing stage workflows, and do not chain generic automation rules.

`php artisan crm:run-due-automation` processes at most 100 due executions each run. Laravel schedules it every minute under the existing Redis scheduler locks. Outcomes include `pending`, `completed`, `skipped_disabled`, `skipped_changed`, `skipped_stale`, `skipped_condition`, `skipped_recipient`, `blocked`, `failed`.

## Explicit boundaries

Reference-specific N7 field types/options and existing records need authoritative definitions/data; owners can configure supported custom fields without duplicating built-ins. The screenshots do not define variable/constant values, a CRM working-hours calendar, or product/estimate/dependency behavior. Those are not fabricated as working backend features. Live email/calls/messaging, legal signature delivery and other provider-dependent actions remain subject to the existing provider-selection boundary. No external messages or live provider calls are introduced.

Frontend screenshot/device acceptance belongs to the concurrently running frontend work. This document describes backend contracts, not a claim that the final frontend already uses every endpoint.

## Verification

The full CRM regression passed **79 tests / 1,308 assertions**, including 21 focused completion tests. Three additive migrations (`000115`–`000117`) have been applied to the local development database. Full project backend regression passed **413 tests / 6,134 assertions** using `php -d memory_limit=512M vendor/bin/phpunit` (reported peak 123 MB). The initial default-memory full run terminated in signature preparation; its two isolated tests passed, followed by the successful complete run. Full PHPStan reports zero errors, and Pint passed all 680 PHP files. `wayfinder:generate --with-form` refreshed frontend route types for the added endpoints.

Frontend compatibility checks also passed: formatting/lint (326 formatted files, 250 linted files), TypeScript, the frontend test suite and the production build. The existing optional-font fallback notice remains informational. The backend checkpoint was committed as `ff6dfea` and pushed to `origin/main`. Subsequent frontend integration changes are recorded below.

## Frontend integration continuation

The user approved connecting and checking the CRM frontend after the backend push. The filter panel now uses the authenticated preference endpoints for named filters and field selection, retains pipeline/stage criteria, and displays rejected saves. Active filter preferences no longer read browser-global storage. Browser storage remains only for the presentation view preference.

The activity board now renders the server-provided organization-local lanes, full counts and paginated leads instead of regrouping a limited lead/follow-up snapshot in the browser. Cards link to the lead activities tab. The calendar reads all pages of the filtered activity endpoint, removes completed/undated work, groups by organization-local date and initializes to the organization month. Loading failures are shown instead of an apparently empty calendar; requests are cancelled when the selection or view changes.

Lead history uses server search/event filters and pagination. Open activities on unconverted leads offer edits and completion; the editor passes the returned `expected_updated_at`, uses an organization-local datetime input and renders validation errors. The detail endpoint adds `timezone` for that input. Concurrent frontend work supplies the new import wizard and stage-based automation rule panel; their source settings, default-assignee, delay, action, activity-type and target-stage payloads match the backend contracts.

Timezone regressions cover midnight boundaries and a daylight-saving transition. Automated compilation and endpoint regressions do not constitute screenshot or physical-device acceptance. At that integration checkpoint browser/device review was pending; the subsequent Chrome review is recorded below.

Final integration validation passed 79 CRM tests / 1,316 assertions in a separate disposable `testing_crm_integration` database, 103 frontend tests, full PHPStan with zero errors, PHP controller formatting, frontend formatting/lint, TypeScript and production build. The first backend attempt failed while migrating the shared testing database; the isolated rerun passed and its disposable database was removed. The user subsequently authorized browser review, commit and push of these integration changes.

## Chrome browser review — 5 October 2026

An isolated local headless Chrome profile reviewed the authenticated application with the existing synthetic owner in disposable organization 26. Desktop captures used 1440×1000; mobile emulation used 390×844. Reviewed screens included the lead board, creation sheet, filter panel/field settings, lead details, activity editor, history, calendar, CSV source/mapping steps and automation picker. Measured document width matched viewport width on the captured desktop/mobile pages; wide boards scroll within their own region.

Actual browser interactions verified creation of synthetic lead 48 (`CRM Browser Review 2026-10-05`, `crm-browser-review@example.test`), saved-filter creation/read/deletion, activity creation, rescheduling from 6 October 09:00 to 7 October 10:00 organization time, matching calendar placement and history filtering to `crm.activity.updated`. The temporary saved filter was deleted. The synthetic lead and its follow-up remain in the retained disposable organization for further review; no real organization's CRM data was changed. One synthetic CSV preview was uploaded without committing its import; the creator-only batch follows the existing one-day expiry policy. Automation was inspected without activating a rule.

Review findings corrected: obsolete browser-only saved-filter wording, inaccurate offset instructions on the local datetime editor, same-page hash navigation between lead tabs, and low-contrast calendar entry text in the dark theme. Screenshots remain private local review artifacts rather than repository assets. The screenshots guide functional composition; the application retains its existing design system, configurable organization stages and authorized field definitions. This is desktop/headless mobile-emulation evidence, not physical-device or product-owner acceptance. Provider-dependent actions and unsupported reference-specific tabs retain the handoff boundaries above.
