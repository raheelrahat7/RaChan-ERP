# Deals and CRM settings backend — 2026-10-05

The user requested a working backend while Claude handles frontend layouts. This delivery adds a CRM deal lifecycle independently of lead pipelines and the existing reservation, off-plan, contract and accounting workflows. No frontend files are edited.

## Workflow

Create deal pipelines first through the configuration API. Each new pipeline starts with New, Deal won and Deal lost. Owners/Administrators can rename, order, color, add and deactivate stages; set another active normal initial stage; choose an active default pipeline; and configure transition sources, entry roles and required built-in details. Used stages cannot change outcome type or be deleted. Used pipelines and automation references cannot be deleted. Configuration history is audited.

Direct deals require `title`, `category`, `pipeline_id`. Categories: `offplan`, `secondary`, `resale`, `listing`, `leasing`, `other`. Optional details: first/last name, email, phone, company, source, notes, amount (nonnegative decimal, at most 16 integer/2 decimal digits), three-letter currency, expected close date, organization-owned listing and assignee. The default assignee is the actor. Amounts are opportunity estimates and create no invoice, journal, FX conversion, commission payout or property reservation.

`POST /crm/leads/{lead}/deal` requires the same creation payload and a visible lead at an active **Won** stage in an active lead pipeline. This is the final qualification gate. Contact details are copied when omitted from the payload. The original lead, stage history and customer-conversion state remain unchanged; both records receive linking audit events. A unique organization/lead constraint and organization lock allow one linked deal per lead; retries return that existing visible deal without implicitly transferring it. Direct deals have no lead. Existing customer conversion still works separately.

Lead index rows and LeadShow props now include `linkedDeal` (id/title/pipeline/stage, or null when inaccessible) and `qualifiedForDeal`. Deal detail includes `sourceLead` only when current lead visibility permits it. The copied contact details belong to the authorized deal. Custom field definitions/values are separate per entity; custom lead fields are not silently copied into different deal definitions.

All deal updates, stage moves, transfers and activity mutations require `expected_version` from the latest deal response. Every successful mutation increments the integer version. Stale requests return 422 `expected_version`. Lost stages require `lost_reason`; reopening terminal deals requires notes and a normal/on-hold destination. Cross-pipeline transfers require `confirmed: true`, valid destination-stage rules and destination read/add access. They preserve history and the source lead link.

## Pipeline access

Owners/Administrators retain organization access and exclusively configure pipelines/access/settings. Other actors require CRM section viewing access. New pipelines with no access configuration initially allow own-assigned viewing/amount viewing; existing CRM editing authority permits own add/edit/move/transfer/assign. Export is denied by default.

Once any access rule is saved, the pipeline uses explicit rules and unmatched actors receive no access. Removing the last rule keeps the pipeline restricted. Only an explicit owner/admin pipeline update with `access_configured: false` restores the initial fallback.

Principals: `role`, `user`, `team`, `subdepartment`, `department`; `principal_id` is a **string**, including numeric IDs. Foreign principals are rejected. Applicable rules combine their allowed scopes. Omitted actions and `none` grant nothing; `none` is not an overriding deny against another applicable grant. This is an additive access matrix.

Actions: `read`, `add`, `edit`, `move`, `transfer`, `assign`, `export`, `amount`. Scopes: `none`, `own`, `team`, `subdepartment`, `department`, `organization`. Group scopes use the actor's current active hierarchy placement and current organization memberships; inactive hierarchy and revoked placement immediately stop supplying access. Mutations also require read access. Reassignment requires assign access and readable/editable destination-assignee scope. Hidden amounts/currency are removed from JSON and exports. Stage counts, list pagination, detail, activities, source links and export use current access checks.

Section restrictions apply existing `OrganizationPermission` keys to a role, member or hierarchy group. Any matching disabled restriction removes that permission; enabled removes that principal's restriction. These restrictions intersect the existing role privileges, preserving financial/identity approval authority. They do not promote Members to finance managers or change existing owner-only posting approvals. Existing explicit CRM edit grants respect CRM section restrictions.

## JSON endpoints for frontend integration

Authenticated, verified web/session routes use normal CSRF protection. Send `Accept: application/json`, JSON content type and the session XSRF header. These endpoints return JSON and **do not render an Inertia page**. Claude can fetch them from the layouts. Validation is 422, denied actions 403, inaccessible records 404.

| Method       | Path                                               | Contract                                                                                                                                                |
| ------------ | -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET          | `/crm/deals`                                       | `{deals: paginator(50), pipelines, stageCounts, filters, categories, timezone, canConfigure}`; filters pipeline_id/stage_id/assigned_to/category/q/page |
| POST         | `/crm/deals`                                       | direct creation; `{deal}`, 201                                                                                                                          |
| POST         | `/crm/leads/{lead}/deal`                           | qualified-lead linking; `{deal}`                                                                                                                        |
| GET          | `/crm/deals/{deal}`                                | deal/permissions, sourceLead, stages, accessible pipelines, customFields, paginated history/timeline/activities/timezone                                |
| PUT          | `/crm/deals/{deal}`                                | partial details, optional `custom_fields`, expected_version; `{deal}`                                                                                   |
| PUT          | `/crm/deals/{deal}/stage`                          | stage_id/expected_version/lost_reason/notes; `{deal}`                                                                                                   |
| POST         | `/crm/deals/{deal}/transfer`                       | pipeline_id/stage_id/expected_version/confirmed/lost_reason/notes; `{deal}`                                                                             |
| GET          | `/crm/deals/configuration`                         | owner/admin pipelines/stages/accessRules/actions/scopes/categories/requiredFields                                                                       |
| POST/PUT     | `/crm/deals/pipelines[/{pipeline}]`                | name/description/position/active/is_default/access_configured; `{pipeline}`                                                                             |
| POST/PUT     | `/crm/deals/pipelines/{pipeline}/stages[/{stage}]` | name/type/color/position/active/is_initial/allowed_from_stage_ids/entry_roles/required_fields; `{stage}`                                                |
| PUT          | `/crm/deals/pipelines/{pipeline}/access`           | principal_type/principal_id/permissions action-to-scope object; `{rule}`                                                                                |
| DELETE       | `/crm/deals/configuration/{kind}/{id}`             | kind pipeline/stage/access; unused config only; `{deleted: true}`                                                                                       |
| POST/PUT     | `/crm/deals/{deal}/activities[/{activity}]`        | type/notes/due_at/completed/expected_version; `{activity, version}`                                                                                     |
| GET          | `/crm/deals/export`                                | same list filters, all matching readable **and export-authorized** records; spreadsheet-safe CSV, audited                                               |
| GET/POST/PUT | `/crm/deals/automation[/{rule}]`                   | GET rules/actions/paginated executions; writes name/pipeline_id/stage_id/action/active/delay_minutes/due_days/activity_type                             |
| GET          | `/crm/settings/data`                               | lists/options/canConfigure/permission catalogue/roles; owner/admin also receive section rules, members and hierarchy                                    |
| POST/PUT     | `/crm/settings/options[/{option}]`                 | list_key/name/position/active; `{option}`; archive by active=false                                                                                      |
| PUT          | `/crm/settings/section-access`                     | principal_type/principal_id/permission/enabled; `{saved: true}`                                                                                         |
| GET          | `/crm/settings/fields?entity=deal`                 | owner/admin field definitions/types/entity; entities lead/deal/contact/company                                                                          |
| POST/PUT     | `/crm/settings/fields[/{field}]`                   | existing typed-field contract plus entity/tooltip/show_in_filter/show_in_list; `{field}`                                                                |
| GET/PUT      | `/crm/records/{entity}/{record}/fields`            | entity contact/company; PUT custom_fields; `{fields}`                                                                                                   |

Each accessible pipeline includes stage definitions, readable assignee `members`, and action-to-scope `permissions`. Each deal has boolean `permissions`. Detail pagination query keys: `history_page`, `timeline_page` (100 each), `activity_page` (50). Activities accept naive organization-local due dates or explicitly offset dates; storage is UTC. Completed activities and closed-deal activities are read-only.

Typed fields reuse the established supported types (text, long_text, number, currency, date, datetime, checkbox, single_select, multi_select, phone, email, url, user), required validation and view/edit role lists. The same key can exist independently for each entity. Keys/entity are immutable; used field types are immutable; archival preserves values. Deal `custom_fields` writes occur inside creation/update transactions. Required editable fields are also checked on movement. `customFields` detail entries contain key/name/type/options/required/tooltip/show_in_filter/show_in_list/value/editable. Contact/company field endpoints use the existing CRM module visibility and mutation authority. Existing lead endpoints remain lead-only.

Configurable selection lists: sources, contact_types, company_types, company_sizes, industries, deal_types, salutations, call_statuses. They supply organization-owned options for selectors; existing source strings remain compatible. The screenshot company names, employee identities, source catalogues and pipeline labels are not imported into business organizations.

## Internal automation

A rule belongs to one deal pipeline/stage. Entry (including direct/linked creation at initial stage) creates one execution per rule/history. Selected actions are in-app assignee notice, scoped manager notice, or follow-up activity. `delay_minutes` 0–525600 and `due_days` 0–365 are supported; follow-up type call/email/meeting/task/note is an **internal activity**, not a provider operation. Save active=false to disable a rule. Rules do not move stages, close deals, create contracts or send external email/SMS/calls.

`crm:run-due-deal-automation` runs each minute under the existing Redis scheduler locks, at most 100 due executions per run. Rule edits/disablement/revoked creator authority and stale stage-entry events are skipped. Assignee notices preserve the entry assignee and require current visibility; manager notices require current pipeline visibility. Activity/notification writes, execution completion and audits are atomic and replay-safe. Failures are reported and marked failed in execution history. Run scheduler normally in the target environment; no external provider is activated.

## Boundaries and migration

Four additive migrations: 000118 Deals/pipelines/access/history, 000119 CRM option/section access, 000120 entity-specific typed fields, 000121 deal automation. No existing lead rows are rewritten. There is no automatic business-data/pipeline seeding. Rollback of entity fields refuses to discard non-lead definitions; export/preserve those first.

Currency exchange rates, taxes/payment systems, numbering, document signatures, recruitment funnels, external CRM migration/marketplace/SMS providers in the screenshots remain under their existing modules and approved financial/provider boundaries. This delivery does not replace those workflows or adopt screenshot FX values.

## Validation and activation status

- Full backend regression on an isolated MySQL instance with database `testing`: **432 tests / 6,377 assertions passed**, including concurrency and existing migration rollback tests.
- Final CRM compatibility checks: **40 tests / 583 assertions passed**; final Deals endpoint regression after the read/write controller split: **12 tests / 131 assertions passed**.
- Repository-wide PHPStan: zero errors. PHP formatting: 709 files passed. Frontend formatting/lint: 361/283 files passed. TypeScript and production build passed after regenerating Wayfinder routes. No handwritten frontend files changed.
- Disposable test MySQL instance and `testing_deals_backend` database are removed after validation; application business fixtures are untouched.
- **Local application schema activated on 2026-10-05 after explicit user approval.** Migrations 000118–000121 were successfully applied to local `z1_erp` and verified as Ran. The earlier automatic approval block was resolved by the user’s approval. No production deployment or frontend integration was performed.
- No commit/push is performed by this task. Concurrent Chat work is outside this delivery.

## Organization configuration continuation

The settings options endpoint accepts `list_key: deal_categories`. Creating a category requires an immutable `code` (lowercase letters, numbers and underscores, maximum 24 characters), a display `name`, `position` and `active`. The first category write initializes the six starter categories in the organization, so each can subsequently be renamed, reordered or archived through the same endpoint. Deal list/configuration responses include `categories` (active codes) and `categoryOptions` (code, display name, active and option ID). Existing deals retain archived category codes; new assignments require an active category in the current organization. Read filters accept archived codes.

Stage `required_fields` accepts `custom:<field_key>` for active deal custom fields belonging to the organization, alongside built-in fields. Entry checks stored values; updates cannot clear a field required by the current stage. Failed checks roll back the entire write and version increment. Archived definitions stop enforcing stage requirements. Configuration `requiredFields` exposes these keys. This is included in migration 000119, applied to the local application database after user approval.

Editable configuration covers pipelines, stages, transition restrictions, category labels, selection options, custom-field definitions, role/group permissions and internal notification/follow-up automation. Outcome types, permission scopes and executable automation actions retain defined backend semantics. Lead conversion continues to require an active qualified Won stage. Financial approval and external-provider integrations are outside this continuation.

## Subsequent screenshot completion

[The completion handoff](SCREENSHOT_BACKEND_COMPLETION_2026_10_05.md) supersedes the earlier automation limits above: conditional `change_stage` into normal stages, working calendars, typed custom filters, lead mappings and financial links are now implemented. Providers remain unselected.
