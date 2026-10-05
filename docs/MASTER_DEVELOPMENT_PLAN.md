# Master Development Plan

## Product and architecture

Build an enterprise real-estate ERP as a modular Laravel monolith. Laravel owns HTTP, authentication, persistence, queues, caching, authorization, and business workflows. Inertia and Vue provide the authenticated application UI. Modules communicate through explicit application services, events, or contracts instead of reaching into one another's internals.

The approved technology baseline is Laravel 13, PHP 8.5, MySQL 8.4, Redis, Inertia 3, Vue 3, TypeScript, Tailwind CSS, and shadcn-vue.

## Module boundaries

Each future business module lives at `app/Domain/<Module>` and may contain only the folders it needs:

```text
app/Domain/<Module>/
├── Actions/          # Use-case orchestration
├── Contracts/        # Module-facing interfaces and DTO contracts
├── Data/             # Typed data-transfer objects
├── Events/           # Domain or application events
├── Exceptions/       # Module-specific exceptions
├── Models/           # Eloquent models owned by the module
├── Policies/         # Authorization policies owned by the module
├── Queries/          # Read-model/query services
├── Services/         # Reusable module services
└── Support/          # Module-private supporting code
```

Shared, non-business concerns belong in `app/Support`. HTTP controllers, requests, middleware, and resources remain in `app/Http`. Do not create empty directories merely to mirror this diagram; establish the `app/Domain` root during Phase 0 and create module directories only when that module begins.

## Delivery phases

The current full-roadmap gap count and recommended first-release boundary are recorded in [RELEASE_GAP_AUDIT_2026_09_21.md](RELEASE_GAP_AUDIT_2026_09_21.md).

The user approved the first-organization pilot boundary on 2026-09-21. Its included workflows and open release gates are recorded in [PILOT_RELEASE_SCOPE_2026_09_21.md](PILOT_RELEASE_SCOPE_2026_09_21.md).

### Phase 0 — Project foundation

Create the Laravel application with the official Vue + Inertia TypeScript starter architecture and Laravel's built-in authentication. Establish local MySQL and Redis configuration, the authenticated application shell, reusable frontend conventions, quality tooling, and dashboard test coverage. No business modules or business database tables are permitted.

### Phase 1 — Identity and access

Define organization, user-role, permission, and audit foundations. Product-owner approval is required before choosing multi-tenancy isolation and role/permission semantics.

### Phase 2 — CRM

Implement leads, contacts, accounts, activities, and conversion workflows.

#### CRM extension — Dynamic lead pipelines

The agreed design replaces fixed lead stages with organization-owned pipelines, stages, and lost reasons. The detailed delivery scope and acceptance criteria are recorded in [CRM_DYNAMIC_PIPELINE_PLAN.md](CRM_DYNAMIC_PIPELINE_PLAN.md). The user approved the first implementation increment on 2026-09-16; it is now implemented and validated locally.

The first implementation increment covers configuration permissions, pipeline/stage/lost-reason management, safe migration of existing leads, audited manual movement and reopening, required lost reasons, and a basic interface with counts and pipeline/stage/assignee filters. The user subsequently approved the pipeline board increment, which adds drag-and-drop confirmation and keyboard/touch movement using the existing workflow. The configurable stage-entry increment is also approved and implemented: allowed source stages, required lead details, role restrictions, shared blocked-move explanations, and audited lead-details editing. The approved reporting increment is implemented with historical outcome totals, customer conversion metrics, lost reasons, current-assignee performance and observed stage time. The later approved funnel increment adds observed stage-to-stage movement counts and cross-pipeline transitions. The assignee-visibility increment initially limited Members and Viewers to their own assigned leads. The later approved hierarchy increment adds departments, subdepartments, teams, member placement and per-user department/subdepartment/organization view grants. Owners and Administrators see all leads; Managers are now scoped to their own leads and explicit grants. A later individual editing grant lets them enable CRM mutations for a Member or Viewer without expanding lead visibility or configuration rights. The approved cross-pipeline transfer increment requires an explicit destination and confirmation, enforces destination rules, and preserves stage history and historical report cutoffs. The first approved automation increment adds configurable in-app notifications to the assigned member on stage entry, once per event. The next approved automation increment adds an optional stage-entry follow-up task with a due-day offset, once per event. The approved assignment increments add stage pools, then specific form/campaign/project/department routing with daily check-in, quotas, holds, and Meta Lead Ads intake. An optional organization-level reminder now sends in-app notices to current assignees before open follow-ups are due. Further automation remains separately gated. See [CRM_DEPARTMENT_HIERARCHY_PLAN.md](CRM_DEPARTMENT_HIERARCHY_PLAN.md), [CRM_DYNAMIC_PIPELINE_PLAN.md](CRM_DYNAMIC_PIPELINE_PLAN.md), and [CRM_ASSIGNMENT_ROUTING_AND_META.md](CRM_ASSIGNMENT_ROUTING_AND_META.md).

The user paused further CRM automation for an acceptance review, then said to proceed on 2026-09-21. Local technical checks are recorded in [CRM_ACCEPTANCE_REVIEW_2026_09_20.md](CRM_ACCEPTANCE_REVIEW_2026_09_20.md); owner business and physical-device sign-off are still unrecorded. The next CRM automation increment adds opt-in in-app escalation at 1 day, 1 week and 2 weeks overdue. Scoped Managers and Owners/Administrators receive notices; follow-ups remain open. See [CRM_DYNAMIC_PIPELINE_PLAN.md](CRM_DYNAMIC_PIPELINE_PLAN.md).

CRM reference-workflow continuation — 2026-10-05: the user requested completion of the CRM leads backend against supplied screenshots while a separate frontend implementation runs. The increment adds CSV source settings/default assignment, private saved filters and field selection, corrected typed/name/activity/history filters, organization-local activity-board data, audited activity rescheduling, paginated history, and delayed internal automation with scoped manager alerts and guarded nonterminal stage changes. Generic automatic moves cannot chain automation rules or convert customers. Three additive migrations are applied locally. Frontend contracts, validation and provider/reference-data boundaries are recorded in [the CRM backend handoff](CRM_LEADS_BACKEND_HANDOFF_2026_10_05.md). This supersedes the earlier immediate-only generic-rule boundary for these selected internal actions; it does not authorize live provider activation.

### Phase 3 — Property and inventory

Implement properties, buildings, units, availability, and related documents.

The approved continuation of the remaining phases adds listing inquiry paths into the existing CRM pipeline. Staff can record a prospect against an organization-owned listing, while active listings expose an unguessable, rate-limited public page that accepts a prospect with email or phone. Paused, draft and closed listings are unavailable publicly. Property-portal syndication remains separate external-integration work.

### Phase 4 — Leasing and sales

Implement inquiries, reservations, leases, sales contracts, renewals, and workflow approvals.

The approved continuation adds audited, tenant-scoped condition observations and private image evidence to planned move-in/out handovers. Observations and evidence become read-only after completion. They do not create deposit deductions or change the approved handover completion rule. Completing a move-out now opens a vacancy record with aging, target readiness, inspection/maintenance/listing status and notes; activation of the next lease resolves it automatically. Read-only owner statements allocate posted lease service-charge income and property operating expenses by recorded ownership share for a selected period, with CSV export; they create no payable or journal entry.

### Phase 5 — Finance and accounting

Implement receivables, payments, invoicing, ledger integration, and financial reporting only after accounting rules receive product-owner approval.

The user confirmed continuation from Phase 4 into Phase 5 on 2026-09-21. Resume finance work against the existing mappings in [ACCOUNTING_DECISIONS.md](ACCOUNTING_DECISIONS.md); the repository already contains invoices/payments, a detailed AED ledger, reconciliation and internal reports. The continuation review found and corrected domain journal validation accepting negative debit/credit sides despite balanced totals. The user approved the first customer-credit-note increment on 2026-09-27. Implementation adds reasoned drafts for outstanding posted AED revenue invoices, balance-capped finance-manager posting in an open period, original revenue/VAT account reversals, and audited dated corrections. Payment/rent-offset limits and receivable, VAT, dashboard and owner reports account for credits separately from payments. See [ACCOUNTING_DECISIONS.md](ACCOUNTING_DECISIONS.md) for approved boundaries. The user also approved an organization-wide operating-budget increment on 2026-09-27; the first implementation is complete and locally validated against [FINANCE_BUDGET_POLICY_PROPOSAL.md](FINANCE_BUDGET_POLICY_PROPOSAL.md) and [OPERATING_BUDGET_CHECKPOINT_2026_09_27.md](OPERATING_BUDGET_CHECKPOINT_2026_09_27.md). Remaining Phase 5 gaps and approval gates are listed in [PHASE_5_REMAINING_FINANCE_GAPS_2026_09_27.md](PHASE_5_REMAINING_FINANCE_GAPS_2026_09_27.md). Phase 6 was not active at that historical finance checkpoint; its subsequent approval is recorded below.

The user subsequently approved manager-submitted customer cash refunds and supplier credit notes with owner approval and maker-checker controls. Refunds require posted customer credits and direct cash capacity; offsets are excluded. Supplier credits reverse original detailed bill accounts and source-ratio recoverable VAT, without vendor cash payout. The increment is implemented locally with focused workflow tests. Written accountant review remains outstanding. The user subsequently approved owner-controlled historical journal mapping, with optional approval delegation to named organization members acting as accountants. Explicit, reconciled proposals activate detailed lines on the original date after approval; closed periods and filed-return dates are protected. No automatic backfill is performed. Local validation is recorded in [HISTORICAL_MAPPING_CHECKPOINT_2026_09_27.md](HISTORICAL_MAPPING_CHECKPOINT_2026_09_27.md).

Continuation validation: the initial full PHPStan scan reported 627 pre-existing errors. The user requested correction of all errors, and that cleanup is complete as of 2026-09-22. Full PHPStan at level 7 now reports zero errors, with model-cast parsing enabled and no error baseline or suppression added. The full backend suite passes 169 tests and 2,363 assertions, including journal negative-side validation and corporate-tax year-selection regressions. PHP formatting, frontend formatting/lint, TypeScript and the local production build also pass. See [QUALITY_GATE_REVIEW_2026_09_22.md](QUALITY_GATE_REVIEW_2026_09_22.md) for the verified checkpoint. This completed the error-cleanup task. The subsequent credit-note policy approval is recorded above.

Credit-note continuation validation is recorded in [CREDIT_NOTE_CHECKPOINT_2026_09_27.md](CREDIT_NOTE_CHECKPOINT_2026_09_27.md).

### Phase 6 — Operations and reporting

Implement maintenance, service workflows, reporting, integrations, and operational dashboards.

The user approved starting Phase 6 with an existing-workflow review and implementation plan on 2026-09-27. Phase 6 is now the active continuation; earlier Phase 5 checkpoints describe their historical scope. The review, current baseline, proposed delivery order and pending product decisions are recorded in [PHASE_6_IMPLEMENTATION_PLAN_2026_09_27.md](PHASE_6_IMPLEMENTATION_PLAN_2026_09_27.md). Existing maintenance, preventive plans and operations overview are implemented; the fresh Operations/Dashboard baseline passes 12 tests and 183 assertions. The recommended first implementation increment is maintenance reliability and export safety, followed by technician job cards. New access rules, stock/finance policy and provider integrations remain separate decisions. Phase 5 written accountant acceptance and actual filing comparisons are still pending.

Phase 6 continuation: the user approved the first maintenance-reliability increment. Domain actions, atomic preventive generation with occurrence uniqueness/replay handling, organization-local due dates, preserved assignment/completion timestamps and module-authorized spreadsheet-safe exports are implemented. Delivery details are recorded in [PHASE_6_MAINTENANCE_RELIABILITY_2026_09_27.md](PHASE_6_MAINTENANCE_RELIABILITY_2026_09_27.md). The user subsequently approved technician job cards and assigned-only technician access, with operations managers managing all organization jobs. Notes, checklist progress, operational labor/material entries, private evidence and scoped reporting are implemented; see [PHASE_6_JOB_CARD_CHECKPOINT_2026_09_27.md](PHASE_6_JOB_CARD_CHECKPOINT_2026_09_27.md). The user then approved manager confirmation as a per-job creation option: selected jobs require submission and manager confirmation, while unchecked jobs allow assigned-technician completion. Managers can reopen either type with a reason. The lifecycle, required checklists and existing status-route enforcement are implemented; final validation is recorded in the checkpoint.

The user approved proceeding with Phase 6.3 preventive automation after the recommendation to preserve each missed occurrence. The current increment adds opt-in plan settings, organization-local due-date catch-up, bounded scheduled generation, inherited completion policy, recent occurrence history and failure visibility. See [PHASE_6_PREVENTIVE_AUTOMATION_2026_09_27.md](PHASE_6_PREVENTIVE_AUTOMATION_2026_09_27.md) for delivery and validation status. The user then approved spare-part implementation and selected multiple stores/warehouses, manager-recorded movements with assigned-job technician viewing, and no negative stock. Receipts, job issues, bounded unused-part returns and reasoned corrections are implemented; see [PHASE_6_SPARE_PARTS_CHECKPOINT_2026_09_27.md](PHASE_6_SPARE_PARTS_CHECKPOINT_2026_09_27.md) for final validation and local migration status. The selected Phase 6.3 implementation is complete; owner/device acceptance remains unrecorded. No financial valuation or posting policy is approved by these quantity workflows.

## Cross-cutting rules

- Every phase must include authorization, validation, tests, and appropriate auditability.
- Database changes require migrations and focused test coverage.
- Queue and cache work must use Redis unless a later approved decision documents an exception.
- Keep external integrations behind module contracts.
- Product decisions affecting tenancy, financial rules, compliance, data retention, or external integrations require product-owner approval before implementation.

Phase 6.4 continuation: the user selected internal helpdesk, configurable business-hours SLA targets, explicit acknowledgement, reasoned manager-approved holds, manager-only in-app breach alerts and fresh reopening cycles with preserved history. Final completion, including required manager confirmation, ends resolution time. The selected internal service implementation is complete locally; see [the service workflow checkpoint](PHASE_6_SERVICE_WORKFLOWS_CHECKPOINT_2026_09_27.md). AMC and customer portal remain unselected; this does not authorize Phase 6.5 or 6.6 implementation.

Phase 6.5 continuation: the user approved reporting and dashboards. Current job status/aging, scoped workload, exact currency-separated costs, generated preventive-job results, preserved SLA cycle results and matching filtered all-page CSV exports are implemented and validated locally. See [the reporting checkpoint](PHASE_6_REPORTING_CHECKPOINT_2026_09_27.md). No historical current-state trends are reconstructed; no Phase 6.6 release or deployment work is authorized by this increment.

Phase 6.6 continuation: the user approved final acceptance/release-readiness preparation, confirmed that owner/device review has not yet happened, and selected a platform-neutral release plan because hosting/domain are unselected. The integrated temporary-data operations rehearsal and acceptance/runbook packet are complete locally. Final regression passed 277 tests / 4,065 assertions; PHP formatting passed 450 files, and a guarded cleanup check found zero records in 19 checked testing tables. See [the Phase 6 acceptance packet](PHASE_6_RELEASE_ACCEPTANCE_2026_09_27.md) and [release runbook](PHASE_6_RELEASE_RUNBOOK.md). Human/accountant/staging/restore/security gates remain open. No production deployment or optional provider integration is selected or performed.

Owner-review continuation: the user selected a retained disposable local organization with invented jobs. Organization 26 and synthetic owner/technician accounts are prepared; authenticated HTTP checks verify six/five job visibility, stock denial, reports/CSV and sample balances. See [the owner review guide](PHASE_6_OWNER_REVIEW_GUIDE_2026_09_27.md). No application behavior/schema changed. These local review records are intentionally retained, separate from cleaned test fixtures. Owner/device acceptance and staging/accountant gates remain open.

Full-roadmap continuation: the user explicitly authorized completing the remaining parts on 2026-09-27. This extends the active task to remaining workstreams across the phases, subject to the existing finance, tenancy, compliance, retention and provider decision rules. The user selected weighted-average operational stock valuation without automatic journals; AMC coverage dates, property/equipment coverage and service limits without billing; and invited tenants/landlords with explicit links and separate access. Portal tenants may read their linked leases/invoices and submit service requests; landlords may read owned properties, owner statements and service summaries. See [the continuation backlog](REMAINING_DELIVERY_BACKLOG_2026_09_27.md). External deployment and human acceptance remain unperformed.

The continuation also implements owner-approved gross construction claims followed by finance draft bills, owner-approved vendor cash received against actual supplier overpayment, archive/preserved document versions, expiring read-only personal API tokens, and private creator-only daily/weekly PDF/XLSX reports with current permission checks. Providers/hosting remain neutral and unselected. Arabic's first-release boundary is customer-portal and technician workflows; specialized finance wording stays for accountant review. See [the continuation backlog](REMAINING_DELIVERY_BACKLOG_2026_09_27.md) for evidence and open release gates.

Final local implementation and verification are recorded in [the 2026-09-28 full-roadmap checkpoint](FULL_ROADMAP_LOCAL_CHECKPOINT_2026_09_28.md). This supersedes earlier test counts as a local code checkpoint; it does not supersede owner/device, accountant, provider or target-environment acceptance gates.
