# Dynamic CRM pipeline development plan

## Agreed direction

Use configurable Pipeline + Stage data instead of fixed stage names. Each lead belongs to one organization-owned pipeline and one stage within that pipeline. Behavior depends on stable stage types (`normal`, `on_hold`, `won`, `lost`), never editable names. Stage order controls display order; it does not require sequential progression.

The default Sales Pipeline contains New Leads, Assigned Leads, Not Qualified, Qualified, Referral Leads, Lead on Hold, Won, and Lost. Only Won and Lost are terminal outcomes; Not Qualified remains a normal configurable stage. Initial lost reasons are No Finance, Incorrect Number, Already Purchased, No Longer Interested, Agent Inquiry, and Went Quiet.

## Increment 1 — Foundation and basic interface

- Introduce organization-owned pipelines, stages, lost reasons, and dedicated stage history. Leads reference their pipeline, current stage, current lost reason, and stage-change timestamp. Reuse the existing `assigned_to` field.
- Provide configuration screens for pipeline creation/editing/activation, stage naming, description, color, ordering, type and activation, and lost-reason naming, description, ordering and activation.
- Protect configuration from the first release with a separate pipeline-management permission. Map it to organization owners and organization administrators using the existing access model; managers do not receive configuration access automatically. Do not introduce a platform-wide Super Admin role or cross-organization visibility through this increment.
- Retain existing CRM view/manage permissions for lead access and manual movement. Assignee-only agent visibility and configurable stage-specific permissions are later work.
- Allow authorized manual movement between active stages in the same pipeline. Require an active reason belonging to that pipeline when entering Lost. Record actor, time, previous/new stage, pipeline, reason, and any supplied notes.
- Permit authorized reopening of unconverted lost leads to an active nonterminal stage. Clear the current lost reason while retaining the prior loss in history. Preserve existing converted-lead guards.
- Preserve stage and reason names/types in history so later renaming cannot rewrite the meaning of past transitions. Archive used configuration; allow permanent deletion only when no current or historical references exist. An inactive stage remains visible on existing leads and cannot receive new leads.
- Require an active default pipeline and active initial normal stage for new leads. Block deactivation that would remove the usable default. Historical or current stage types cannot be changed in ways that reinterpret recorded outcomes.
- Show pipeline counts, a lead stage selector, stage history, and pipeline/stage/assignee filters. Counts use the selected pipeline and assignee scope; stage selection filters the lead list while keeping other stage counts visible.
- Keep explicit customer conversion separate from stage movement. Entering Won does not create contacts/accounts automatically. Explicit conversion moves the lead to the first active Won stage (by configured display order), if necessary, with audited history; conversion is rejected when the pipeline is inactive or has no active Won stage. Lost leads must be reopened first. Existing converted leads remain converted and linked to their existing records.
- Do not automatically assign agents, cancel/pause follow-ups, or deliver notifications when stages change in this increment. Existing assignment and follow-up workflows continue to apply.

## Migration and architecture

Seed defaults for existing organizations and initialize them for newly created organizations. Backfill every existing lead without changing assignment, activity, contact, or account links. Map existing unconverted `new` leads to New Leads and converted leads to Won. Preserve unfamiliar statuses as named `Legacy: <status>` normal stages and retain the original status in history. The local database had no lead records at inspection. Migration history explicitly identifies the initial backfill; it does not claim to know the original stage entry time.

CRM-owned actions coordinate configuration and movement in `app/Domain/Crm`; queries supply configuration, counts, and history. Controllers handle HTTP concerns only. Mutations are transactional, organization-scoped, audited, and validate that stages/reasons belong to the selected pipeline. Lock lead state during movement to prevent competing changes from corrupting history. Keep a clear stage-change event boundary for future automation; do not add an automation engine or speculative rules tables now.

Moving existing leads between different pipelines is deferred until explicit stage/reason mapping and history semantics are approved. Creating a lead in a selected active pipeline is supported.

## Acceptance criteria

- Configuration permission checks and tenant isolation apply to every read and mutation, including submitted pipeline, stage, reason, and assignee IDs.
- Renaming or reordering stages updates the interface without code changes; outcome behavior still follows the stage type.
- Lost movement without a valid active pipeline reason fails without changing the lead or adding history.
- Reopening retains the complete loss history. Repeated or concurrent movement cannot create misleading duplicate transitions.
- Used configuration cannot be deleted, and inactive configuration cannot receive new leads. Default configuration remains usable.
- Existing leads retain assignments, activities, conversion links, and access behavior after migration; new organizations receive defaults.
- Counts and filters agree with scoped lead data, and lead history identifies who changed each stage and why a lead was lost.
- Converted leads cannot be reopened or converted again through pipeline movement.
- Focused backend coverage, PHP formatting, frontend formatting/lint, TypeScript validation, full regression tests, and a production build pass before delivery.
- Basic controls are accessible and responsive; drag-and-drop is not required to use the feature.

## Increment 2 — Pipeline board

The user approved this increment on 2026-09-16. It adds a stage-column board to the existing CRM leads page, with a board/list toggle. Columns use configured names, order, colors and active state. Cards reuse the server-filtered lead data, including assignees and conversion state; archived stages retain their cards. Each column labels the visible lead count separately from the existing pipeline/assignee total.

Authorized users can drag an unconverted lead onto a different active stage in the same active pipeline. A dialog preselects that destination and requires explicit confirmation through the existing stage-change endpoint. Lost requires an active reason. Converted leads, inactive pipelines and invalid destinations cannot be dragged into a move; lost leads reopen to nonterminal stages. Server permissions, tenant validation, stale-state checking and audit history remain authoritative. Cards and counts update after server acceptance, with validation errors retained in the dialog.

Every movable card also provides a labeled Move button and a native stage/reason form for keyboard and touch use. The existing dialog component provides focus handling; focus returns to the card after closing. Pending requests disable cancellation. The horizontally scrollable board is keyboard focusable, and move announcements use a live region. The Lead list continues to provide assignment, conversion and stage history.

This increment adds no dependencies, business tables, backend workflow changes, automatic customer creation, or automation engine.

Frontend eligibility/grouping tests run with Node 22:

```sh
node --experimental-strip-types --test tests/Frontend/crm-pipeline-board.test.mjs
```

## Later increments — Separate approval required

1. Further permission configuration. Assignee visibility is delivered in Increment 5, the department/subdepartment/team hierarchy with user-based view grants is documented in [CRM_DEPARTMENT_HIERARCHY_PLAN.md](CRM_DEPARTMENT_HIERARCHY_PLAN.md), and cross-pipeline movement is delivered below.
2. Further funnel analytics beyond the delivered observed stage-to-stage movements, outcomes, conversion, lost-reason, assignee and stage-time reports.
3. Further automation triggers and conditions, reminders, and scheduled actions beyond the delivered stage-entry in-app notification, follow-up task and assignment rule. Customer creation and follow-up closure policies require explicit decisions before automation. External messaging and webhooks require explicit authorization.

## Status

The user approved planning and then explicitly started Increment 1 on 2026-09-16. Increment 1 is implemented. Migration `2026_09_16_000052_create_crm_pipelines.php` is applied locally. Full backend regression passed: 120 tests, 1,388 assertions. Frontend formatting/lint, TypeScript validation, and the production build passed. The approved board increment is also implemented and validated: three frontend board tests and the full 120-test backend regression (1,388 assertions) passed, along with PHP formatting, frontend formatting/lint, TypeScript and the production build. Later increments remain separately gated by the repository workflow.

## Using the first increment

Open CRM leads at `/crm/leads`. Owners and administrators can follow Manage CRM pipelines to `/crm/pipelines`. Edit names, descriptions, active state, stage type/color, and numeric display positions. Positions sort ascending, with IDs breaking ties. Newly created pipelines receive an initial normal New Leads stage; add an active Won stage before customer conversion and Lost stages/reasons before using lost outcomes.

Apply pipeline, stage, and assignee filters to the lead list. Summary counts remain scoped to pipeline and assignee while showing every stage. Follow-ups and recent activities retain their existing organization-wide scope. Use the per-lead Move to stage form, supply a reason for Lost, and expand Stage history to see the preserved movement record. Explicit conversion remains a separate action.

Manual browser acceptance remains for the owner: check responsive configuration forms, keyboard and touch movement, drag-to-Lost confirmation and cancellation, filtered counts, and loss/reopening history with sample leads. The board uses native desktop drag-and-drop; touch users use Move. Automation remains a later increment.

## Browser acceptance review

Technical local Chrome checks and resulting fixes are recorded in [CRM_PIPELINE_ACCEPTANCE_CHECKLIST.md](CRM_PIPELINE_ACCEPTANCE_CHECKLIST.md). Native drag confirmation/cancellation, lost-reason validation, keyboard activation/submission and focus restoration, filtered counts, and mobile width passed using temporary isolated records. Owner business/device sign-off remains pending.

## Increment 3 — Stage entry rules

The user approved configurable source transitions, required lead fields and role-based stage entry on 2026-09-16. Rules are stored on each stage. A null source/role list means unrestricted; an explicitly restricted empty list blocks every source/role. Sources must belong to the same pipeline and exclude the destination itself. Referenced source stages cannot be deleted until rules referencing them are changed.

Owners/administrators configure entry rules using the existing pipeline-management permission. Existing CRM management permission remains mandatory; these rules never grant it. Owners/administrators follow configured restrictions without an implicit bypass. Required fields are the existing first name, last name, email, phone, company, source and notes; whitespace-only values are missing. No custom fields, team permissions, assignee-only visibility, cross-pipeline transfers, reports or automation are introduced.

Rules apply transactionally to manual movement, lost-lead reopening and explicit customer conversion, including conversion of leads already on Won (role and field rules still apply). New lead creation checks its initial stage's role and field requirements; it has no source-stage transition. Updating configuration does not move existing leads retroactively. Existing pipelines start unrestricted.

The server supplies per-lead blocked-move explanations to both board and list; server validation remains authoritative if data/rules change after loading. Lead list includes an audited, permission-controlled details editor so missing fields can be completed before retrying. Converted lead details remain immutable. Rule changes include before/after audit metadata.

Increment 3 is implemented and migrated locally through `2026_09_16_000053_add_crm_stage_entry_rules.php`. Full backend regression passed: 125 tests (1,463 assertions); four frontend board tests passed. PHP formatting, frontend formatting/lint, TypeScript and production build passed. Local Chrome confirmed rule configuration, blocked missing-field explanations, lead-details editing and successful audited movement using temporary isolated records, followed by cleanup.

To configure rules, open Manage CRM pipelines, expand a stage, and use Entry rules. For missing required fields, switch to Lead list, expand Edit lead details, save, then retry movement. Stage rules constrain entry; they do not retroactively change leads already in a stage. Owner business/device sign-off remains pending.

## Increment 4 — Pipeline reporting

The user approved pipeline reporting on 2026-09-16. CRM-view permission controls the organization-scoped report. Filters select dates, pipeline (including inactive pipelines), and current organization assignee. Historical assignment attribution is not inferred from stage history.

Outcome totals show the last recorded stage at the selected end date, capped at the current time. Won/Lost counts are distinct leads, not repeated entry events. Reopened leads use their latest recorded state at that cutoff. Closed-lead win rate is Won divided by Won + Lost. Lost reasons retain recorded names and include only leads still Lost at the cutoff. Leads without an observed state are explicitly untracked.

Period activity separately counts leads created and explicit customer conversions during the selected dates. New-lead conversion rate is the number of leads both created and customer-converted in the period divided by leads created in that period. Manual Won movement does not count as customer conversion. Empty denominators display no rate.

Observed open/on-hold stage intervals are clipped to the date range and cutoff. Terminal Won/Lost waiting time is excluded; reopening begins a new interval. Average hours uses observed intervals, not unique leads. Initial migration records begin observation at backfill time and cannot reconstruct earlier stage time. Missing initial history is excluded. Recorded stage/pipeline names are preserved in interval rows, including renamed or archived configuration.

This increment introduces no business tables or automatic actions. The report is available at `/crm/pipeline-report`, linked from CRM leads.

Increment 4 is implemented and validated. Three focused reporting tests cover historical cutoff/reopening, retained lost names, interval clipping, conversion cohorts, untracked data, authorization and filter validation. Full backend regression passed: 128 tests (1,525 assertions). PHP formatting, frontend formatting/lint, TypeScript and production build passed. Local Chrome verified report rendering, known outcome/conversion totals, assignee-filtered totals and mobile width; temporary records and sessions were cleaned up. No migration was required.

## Increment 5 — Assignee visibility

The user approved the next CRM increment and confirmed that organization Members and Viewers may see only leads currently assigned to them. Owners, Administrators and Managers retain organization-wide visibility. Unassigned leads remain visible only to the wider-access roles. This rule applies to the pipeline board/list, stage counts, lead follow-ups and recent activities, pipeline reporting, dashboard CRM counts and lead CSV exports. Restricted users cannot request another assignee through URL filters; the UI explains their personal scope. CRM write permissions remain unchanged, and no team-membership data model is introduced.

Increment 5 was implemented without a migration or dependency. Focused tests covered lead, activity, follow-up, report, dashboard and export visibility and rejected assignee-filter bypasses. At that point, full backend regression passed: 129 tests (1,597 assertions), along with PHP formatting, frontend formatting/lint, TypeScript and production build. The later hierarchy increment below supersedes its Manager and team-visibility status.

The later approved hierarchy increment supersedes Increment 5's initial organization-wide Manager visibility. Managers now require explicit user-based CRM view grants for department, subdepartment or organization access; Owners and Administrators retain all-lead visibility. Ordinary team members still see only their own assigned leads. See [CRM_DEPARTMENT_HIERARCHY_PLAN.md](CRM_DEPARTMENT_HIERARCHY_PLAN.md).

## Increment 7 — Cross-pipeline lead transfers

The user approved this increment on 2026-09-19. Authorized CRM managers of a lead choose a different active organization pipeline and an active destination stage in Lead list, then explicitly confirm. The server checks organization and lead visibility, expected source pipeline/stage to reject stale requests, destination entry rules, and an active destination Lost reason when entering Lost. A Lost lead may transfer only to a nonterminal stage to reopen. Converted leads cannot transfer. An inactive source pipeline may be exited into an active destination. Transfer changes the lead's current pipeline and stage in one transaction; it clears the prior lost reason unless the destination is Lost. It does not assign an agent, create a customer or close follow-ups. Entering Won manually remains distinct from explicit customer conversion. The later stage-entry notification increment applies to transfers into a configured stage.

One history entry records the previous stage, destination stage, actor, time, reason and notes, with source and destination pipeline names preserved in its snapshot. The existing stage-change event and audit trail remain. Pipeline reports now resolve a filtered lead's pipeline from the last history state at the selected cutoff, so a later transfer does not rewrite past outcome totals. Stage-time rows for a selected pipeline include only observed intervals in that pipeline. Leads without history still use their current pipeline because no earlier placement can be reconstructed.

No migration or dependency was required. Two focused tests cover confirmation, stale/foreign destinations, destination rules, Lost reopening, manual Won versus conversion, preserved history and past pipeline reports. Full backend regression passed: 133 tests (1,794 assertions). PHP formatting, frontend formatting/lint, TypeScript and production build passed. Owner business/device acceptance remains pending.

## Increment 8 — Stage-entry assignee notifications

The user approved this first automation increment on 2026-09-20. Owners and Administrators can enable an in-app notification on each stage from Manage CRM pipelines. The default is off. New lead creation, manual movement, reopening, cross-pipeline transfer and conversion movement share the existing after-commit stage-entry event. A history snapshot freezes whether the rule was enabled and who was assigned at entry time. Only a current organization member who still owns the lead receives the notification; unassigned leads produce none. The existing notification history shows the item, and recipients can mark it read.

Delivery uses the notification table's unique per-user event key based on stage-history ID. Replaying the event cannot create another notification or delivery audit record. Rule changes and successful delivery are audited. The daily digest preference does not control these immediate CRM event notifications. No email, webhook, automatic assignment, customer creation, follow-up change or other automation is introduced.

Migration `2026_09_20_000055_add_crm_stage_assignee_notifications.php` adds the stage flag and is applied locally. Four focused tests cover delivery, replay, rollback, missing/changing recipients, creation and transfer entry, configuration permission and tenant isolation. Full backend regression passed: 137 tests (1,829 assertions). PHP formatting, frontend formatting/lint, TypeScript and production build passed. Owner business/device acceptance remains pending.

## Increment 9 — Stage-entry follow-up tasks

The user approved an optional due-day offset per stage on 2026-09-20. Owners and Administrators can set 0–365 days or turn the rule off. Each committed stage-entry event freezes the rule and assignee in its history snapshot. An entry creates one existing CRM task due at the entry time plus the offset only if the lead is still assigned to that organization member and is unconverted when the listener runs. Unassigned or converted leads produce no task. Tasks remain attached to the lead and follow its current assignee in the existing follow-up views; no separate task assignee or external message is introduced.

Migration `2026_09_20_000056_add_crm_stage_follow_up_rules.php` adds the nullable offset and a unique history reference on CRM activities; it is applied locally. The unique reference prevents duplicate tasks on event replay. Rule changes and successful task creation are audited. Three focused tests verify the authenticated configuration response and assigned member's follow-up list as well as task behavior. The full backend suite passed: 140 tests, 1,877 assertions. PHP formatting, frontend formatting/lint, TypeScript and production build passed. This increment does not add automatic assignment, customer conversion, task completion, or a general automation engine. Owner business/device acceptance remains pending.

## Increment 10 — Automatic lead assignment

The user approved round-robin assignment among selected members on creation and stage entry, with existing assignees preserved. Owners and Administrators configure a member pool per stage; an empty pool disables the rule. The initial stage's pool applies to new leads. An unassigned lead entering another stage uses that stage's pool. The next eligible organization member is selected in ascending member ID order, with the stage's last selection persisted under a row lock to serialize concurrent assignments. Removed members are skipped; if no selected member remains eligible, the lead stays unassigned. Rule changes reset the rotation. Restricted creators retain their existing self-assignment before automation runs.

Assignment occurs in the same transaction before stage history, notifications and follow-up tasks are recorded. Existing assigned leads are never reassigned. Explicit customer conversion does not run assignment. Rule changes and actual automatic assignments are audited. Migration `2026_09_20_000057_add_crm_stage_assignment_rules.php` adds the stage member pool and rotation pointer and is applied locally. Three focused tests cover creation rotation, existing-assignee protection, departed members, notification/task integration, permissions and tenant isolation. The full backend suite passed: 143 tests, 1,925 assertions. PHP formatting, frontend formatting/lint, TypeScript and production build passed. No generalized automation engine, external messaging or follow-up completion is added. Owner business/device acceptance remains pending.

## Increment 11 — Specific routing, quotas, availability and Meta intake

The user approved flexible assignment by Meta form, campaign, project, and department/team scope, with only available agents under their active-lead quota receiving new leads. The detailed policy, setup and external prerequisites are in [CRM_ASSIGNMENT_ROUTING_AND_META.md](CRM_ASSIGNMENT_ROUTING_AND_META.md). Migration `2026_09_20_000058_create_crm_assignment_routing_and_meta.php` stores routing rules, daily check-in and quotas, assignment holds, lead source metadata, and encrypted Meta Page connections/import status. It also adds an organization timezone and is applied locally. Focused tests exercise route precedence, department scope, quota counting and retry order, authorization, signed Meta webhook verification, Page subscription, partial lead creation and deduplication. The last full backend suite passed: 147 tests, 1,992 assertions. Frontend formatting/lint, TypeScript and production build passed. The local Redis worker and scheduler are running. Live Meta delivery remains unverified until real app credentials, Page permissions and a public HTTPS callback are configured.

## Increment 12 — Observed stage-to-stage funnel

The user approved stage-to-stage funnel analytics. The pipeline report now shows actual history movements during the selected dates, including moves into Won or Lost, reopening, and transfers into or out of a selected pipeline. New-lead creation is excluded because it has no source stage. Each row shows transition count, distinct leads and its share of all observed exits from the recorded source stage. Repeated moves count again; the share is a distribution of exits, not a lead conversion rate. Recorded pipeline and stage names remain visible after renaming. The report uses existing organization and user lead visibility and the current-assignee filter; a pipeline filter includes movements touching that pipeline even when a lead later leaves it. No migration or new external dependency was needed.

The focused report tests cover date bounds, repeated movements, cross-pipeline filtering and the Inertia response. Full backend regression passed: 150 tests, 2,054 assertions. PHP formatting, frontend formatting/lint, TypeScript and production build passed. Owner business acceptance remains pending.

## Increment 13 — Individual CRM editing grants

Owners and Administrators can grant or revoke CRM editing for a named Member or Viewer from Departments and CRM access. A grant allows the existing CRM lead, contact and follow-up actions but does not enlarge lead visibility, grant pipeline or hierarchy configuration, or change another module's permissions. Restricted creators' new leads remain assigned to themselves. The grant is audited and removed when the member leaves the organization. Migration `2026_09_20_000061_create_crm_edit_grants.php` is applied locally. Focused tests cover assigned versus hidden leads, denied configuration, foreign users, revocation and member removal. Full backend regression passed: 151 tests, 2,071 assertions. PHP formatting, frontend formatting/lint, TypeScript and production build passed.

## Increment 14 — Optional assignee follow-up reminders

Owners and Administrators can set an organization-level reminder lead time of 1–30 local days or leave it off. A scheduled command checks each configured organization during its local 08:00 hour and sends one in-app notice to the current assignee of each open follow-up due on the target local date. Completed tasks, converted or unassigned leads and former members are skipped. Existing daily digest behavior remains separate. The notice and configuration changes are audited, and a unique event key deduplicates repeated scheduler runs. Migration `2026_09_20_000062_add_crm_follow_up_reminder_setting.php` is applied locally. Focused tests cover configuration permission, timezone, recipient selection, idempotency and exclusions. Full backend regression passed: 153 tests, 2,093 assertions. PHP formatting, frontend formatting/lint, TypeScript and production build passed. The existing local scheduler is running.

## Increment 15 — Optional overdue follow-up escalation

Owners and Administrators can enable in-app escalation for an organization; it is off by default. At 08:00 in the organization timezone, an open CRM lead follow-up qualifies when at least 1, 7 or 14 days overdue. The latest eligible checkpoint sends once per recipient, so enabling the rule after two weeks does not deliver older checkpoints together. Owners, Administrators and Managers with visibility to the lead receive the notice, excluding the current assignee. Converted or unassigned leads, departed assignees and completed follow-ups are skipped. Escalation never closes the task or sends externally. Configuration and each successful delivery are audited. Migration `2026_09_21_000063_add_crm_follow_up_escalation_setting.php` adds the setting; a scheduled command checks enabled organizations every 15 minutes and delivers during their local 08:00 hour.

The migration is applied locally. Two focused tests cover the three checkpoints, late enablement, scope, permission, idempotency and exclusions. The full backend suite passed: 155 tests, 2,124 assertions. PHP formatting, frontend formatting/lint, TypeScript and production build passed, and the command appears in `schedule:list`.
