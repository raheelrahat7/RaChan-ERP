# Z1 ERP Project Memory

## Latest checkpoint — full-roadmap local continuation — 2026-09-28

- User authorized remaining roadmap work and selected policies recorded in docs/REMAINING_DELIVERY_BACKLOG_2026_09_27.md. Implemented local stock transfers/valuation/import, AMC, invited customer portal, construction claims, vendor cash received, document lifecycle/signature preparation, fleet, saved filters/activity, read-only personal API and private scheduled PDF/XLSX reports. Provider-neutral contracts remain inactive until providers are chosen. Arabic portal/technician scope is implemented; finance terminology awaits accountant review.
- Final quality gates: backend 324 tests/4,772 assertions, Pint 557 files, PHPStan zero errors, frontend lint/TypeScript/build pass. Local business migrations through 000090 applied; scheduled-report command generated0/failed0 with no due reports.
- Authenticated HTTP smoke: owner new pages all200; technician assigned job card/API token page200 and construction/fleet/signatures403. Retained review organization26 remains six jobs, two balances (4000/2000 thousandths), four stock movements, five SLA cycles; credential file remains ignored and mode0600. No secrets printed in final record.
- docs/FULL_ROADMAP_LOCAL_CHECKPOINT_2026_09_28.md is the final local implementation checkpoint. The older 277/4065 baseline below is historical. Owner/device acceptance, written accountant/filing review, chosen provider/hosting, staging security/restore and production release are still open. No external messages or deployment.


## Latest checkpoint — retained owner review environment — 2026-09-27

- User selected creation of a disposable local organization with sample jobs for owner/device review. Created ONLY new organization26: DISPOSABLE Phase6 Review etqgqnnfpo, synthetic Owner33/Technician34, property, six jobs, one preventiveplan automationOFF, two stores/one part/four referenced movements and five SLAcycles. No existing organizations edited, no finance postings or external messages sent.
- Accounts owner-etqgqnnfpo@review.example.test and technician-etqgqnnfpo@review.example.test select organization26. Generated passwords live only in ignored storage/app/private/owner-review-access-etqgqnnfpo.json, mode0600, verified readable by local user. Never print/commit passwords. User can open privatefile through final link.
- Jobs1direct/checklist/parts,2submittedawaitsmanagerconfirmation,3reopenedwithpreservedclosed+activeSLAcycles,4reasonedSLAmangerhold,5generatedpreventivewithrequiredtask/confirmation,6owner-onlyassignment. SamplecalendarAsiaKarachi09–17all7days/noholidays response30m resolution120m applies to jobs1–4 only; invented data, not companypolicy. Mainstore1balance4000thousandths/secondstore2balance2000; originalissue3; directjobcost18.13AED labor13.13 material5; manualactualunrecorded.
- Actual local HTTP password logins verified using independent cookie jars and current Inertia asset version. Owner reports6jobs/globalstock200/ownerjob200; technician reports5/globalstock403/ownerjob404; CSV visibilitymatches, bothreopenedjob2cycles/cost18.13. No product-code fixes needed: temporary verification adapted to PendingRequest API and Inertia version. Temporarysetup/verification scripts removed afterward.
- docs/PHASE_6_OWNER_REVIEW_GUIDE_2026_09_27.md provides logins/privatefile reference/scenarios/phoneURL/review steps. Acceptancepacket/masterplan updated.21local linksverified. These records intentionally remain in BUSINESS localDB for ownerreview, separate from previously cleaned testingDB. Do not delete them or claim allbusinessDBempty.
- Chrome exists but headless login-page attempt timedout45s; no browser/physical-device pass claimed. Temporaryprofile/scripts cleaned; no remaining scopedChrome processes. Existing Docker port8000bind0.0.0.0/[::] unchanged; phone uses computerLANIP, notphone-localhost.
- Application code/schema unchanged. Last fullbackend277/4065, Pint450 and earlierappstatic/frontgates stillvalid. Owner/device acceptance, accountant/filing and target staging/production gates remainOPEN. No deployment.
- Next: user logs in with private access file and follows guide, records findings/device/results. Fix suppliedfindings. Afterreview, cleanup only recordedorg26, syntheticusers33/34 and credentialfile with relationship checks; do not broadly truncate/delete businessdata. Hosting/domain stillunselected.

## Historical checkpoint — Phase 6.6 readiness preparation — 2026-09-27

- User approved final acceptance/release-readiness work. Explicit answers: owner/device acceptance not yet performed, prepare checklist; hosting/domain not selected, prepare platform-neutral plan. Do not infer deployment/provider authorization or human sign-off.
- Local readiness review and preparation complete. docs/PHASE_6_RELEASE_ACCEPTANCE_2026_09_27.md contains scope, local evidence, owner/device scenarios and separate accountant/operator gates. docs/PHASE_6_RELEASE_RUNBOOK.md provides staging, supervision, restore proof, monitoring, release/rollback decision and evidence fields. DEPLOYMENT_READINESS worker timeout corrected60 < defaultRedis retry_after90; no local runtime settings changed.
- New OperationsReleaseJourneyTest integrates opt-in preventive generation/retry, inherited confirmation, assignment/checklist, exact costs, receipt/issue/return retries and technician denial, SLA acknowledgement/reasoned hold, confirmation timing, alerts/retry, reopening/history, reports/export and tenant denial, without finance writes.
- Full backend277 tests/4065 assertions passed (316.65s); focused new operations +existing pilot +production-config checks4/147. Pint450 passed. Last application PHPStan zero/frontend lint132/format185/types/build from6.5 remain valid:6.6 changes only test/docs.19 packet links verified.
- Guarded uncached testing-database check after suite:19 named organization/user/job/plan/job-card/stock/SLA/document/audit/notification/finance tables allzero. No rehearsal data seeded into business database.
- Local /up200; web running (no Docker healthcheck), MySQL/Redis runninghealthy and applicationpings passed; migrations through77 applied; six schedules listed; one PHPschedule:work worker. release:check actualexit1, six expectedlocalfailures environment/debug/URL/queue/cache/mail. Do not change local defaults just to make production check green.
- Selected6.1–6.5 implementation complete locally;6.6 local readiness preparation complete, but owner/device, writtenaccountant/actualfilingcomparisons, selectedhosting/domain, stagingmigration/restore/security/monitoring and production decision remainOPEN. AMC/customerportal/providers/transfers/valuation/scheduledreports remainunselected. No deployment or external message sent.
- Next user step: perform owner/device review from acceptance packet and provide results/issues; select hosting/domain when ready. Fix supplied findings within authorized scope. Production action requires a concrete reviewed target/artifact/backup/migration/rollback plan and explicit authorization. Never claim wholeERP or allreleasegates complete from tests.

## Historical checkpoint — Phase 6.5 reporting — 2026-09-27

- User approved Phase6.5 reporting/dashboards after selected6.4 delivery. Implementation and automated validation are complete locally. Reports at /operations/reports linked from /operations; matching CSV /operations/reports.csv. No schema changes or business data seeded.
- Shared property/vendor/assignee/current-status/priority/job-creation-date cohort filters; local inclusive creation-date bounds. Technician assigned-only access covers metrics/details/export; foreign selectors rejected. Details paginate25, CSV exports all matching jobs and SLA cycles with formula-safe cells.
- Current status/whole-elapsed-day active aging (including holds), workload by current assignee, exact currency-separated estimated/manualactual/job-card labor/material totals; void/foreign lines excluded, missing manual values counted.
- Generated preventive jobs due by local today classified unfinisheddue/overdue/on-time/late/unknowncompletion/cancelled. Due-date handling explicitly formats date-only before timezone parsing. Percentage denominator is known dated completions, clearly labelled. Future/ungenerated occurrences excluded; do not claim reconstructed historical plan compliance. Current plan backlog remains in existing overview.
- SLA metrics preserve old cycles and separate active/completed breaches, exclude cancellations. JobSlaClock summary now optionally accepts a fixed report-generation instant, preserving default existing behavior.
- Full backend276 tests/3953 assertions passed; focused reporting7/165; PHPStan zero, Pint449, frontend formatting185/lint132, TypeScript/build passed. Build finished before full backend run to avoid asset manifest race.
- Design/checkpoint: docs/PHASE_6_REPORTING_DESIGN.md, docs/PHASE_6_REPORTING_CHECKPOINT_2026_09_27.md. Owner/device acceptance and written Phase5 accountant/filing acceptance remain unrecorded. Saved filters/scheduled delivery/AMC/customer portal remain separate. Do not claim whole ERP/Phase6 complete.
- Next is separately authorized6.6 final acceptance/release readiness. No6.6 work or deployment was performed in this increment.

## Historical checkpoint — Phase 6.4 internal service workflows — 2026-09-27

- User approved internal helpdesk/SLA first, configurable business hours and final completion including manager confirmation as resolution. Then explicitly selected acknowledgement as first response, only reasoned manager-approved holds pause time, operations managers receive internal breach alerts, and reopening starts a new cycle preserving prior results.
- Selected 6.4 implementation complete locally: /operations/helpdesk linked from Maintenance; job-card calendar/target configuration and acknowledgements; snapshot policy per job; manager holds deduct both clocks; final completion/cancellation close cycles; reasoned reopening copies policy to a new cycle. Tracking starts when enabled, not backdated. Shared policy templates, AMC and customer portal are not implemented or selected.
- Scheduled operations:notify-sla-breaches every fifteen minutes with Redis locks; one in-app notification per eligible manager/cycle/target, active cycles only. Local command returned zero with no SLA jobs seeded. Migration77 applied.
- Full backend269 tests/3788 assertions passed. Focused SLA/calendar15/142. PHPStan zero, Pint444, frontend lint130, TypeScript/build passed. Initial login test failed during concurrent asset build; stable-assets full rerun passed. Avoid running frontend build concurrently with tests that render Vite assets.
- See docs/PHASE_6_SERVICE_WORKFLOWS_DESIGN.md and docs/PHASE_6_SERVICE_WORKFLOWS_CHECKPOINT_2026_09_27.md. Owner/device acceptance and written Phase5 accountant/filing acceptance remain unrecorded. Do not claim whole ERP or Phase6 complete.
- Next planned increment is6.5 reporting/dashboards, then6.6 release acceptance; require user goahead for that work. No later phase was implemented in this increment.

## Historical spare-parts checkpoint — 2026-09-27

- Phase 6 is active. Implementations6.1 maintenance reliability,6.2 assigned-only job cards with per-job optional manager confirmation, and6.3 preventive automation plus multi-store spare parts are complete locally. Owner/device acceptance and written Phase5 accountant/filing acceptance remain unrecorded. Do not claim whole Phase6 or ERP complete.
- User APPROVED stock choices: multiple stores/warehouses; operations managers record all movements, technicians view only assigned-job parts; block negative stock. Delivered part/store catalogues, referenced receipts/opening stock, editable-job issues, original-issue unused returns with cumulative caps, whole-entry reasoned corrections, per-store balances, paginated history/filters and job-card stock visibility. No automated costs, bills, journals, stock valuation or opening-stock backfill.
- Full backend254 tests/3646 assertions; focused stock+quantities12/139; committed-fixture concurrency and migration rollback2/17; PHPStan zero, Pint434, frontend lint/types/build passed. Testing cleanup verified zero rows in12 checked tables. Migration76 applied locally. Stock page /operations/spare-parts linked from Maintenance. See docs/PHASE_6_SPARE_PARTS_CHECKPOINT_2026_09_27.md.
- Next: select scope for6.4 service workflows (AMC/SLA/helpdesk/portal) or a separately approved reporting increment. These later scopes are not implemented or implicitly approved by the stock task. Valuation and inter-store transfers likewise remain separate.

## Historical credit-note checkpoint — 2026-09-27

- Phase 5 remains active. The user approved and the application now implements customer credit-note drafts, balance-capped posting, original revenue/VAT mappings and audited dated reversals on the Invoices page. Finance policy is in `docs/ACCOUNTING_DECISIONS.md`.
- Credit balances feed payment/rent-offset limits, dashboard metrics, historical receivable reports/CSV, VAT preparation and owner statements. Domain work lives in `app/Domain/Finance`; Accounting and Leasing consume its services/queries.
- Migration `2026_09_27_000068_create_customer_credit_notes_table.php` is applied locally. The production UI build is refreshed.
- Full backend regression passed 185 tests / 2,754 assertions. PHPStan level 7, PHP formatting, frontend formatting/lint, TypeScript and production build passed. See `docs/CREDIT_NOTE_CHECKPOINT_2026_09_27.md` for final verification and boundaries.
- Local app: `http://localhost:8000`. Another project occupies port 3306. ERP MySQL now runs in `z1erp-mysql-local` on the existing volume/network with no host port, alongside `z1erp-web` and `z1-erp-redis-1`. Keep original `z1-erp-mysql-1` stopped while the workaround container runs. Details: `docs/LOCAL_DEVELOPMENT.md`.
- Broader credit/refund workflows remain unimplemented. The next Phase 5 opportunity is an organization-wide operating-budget workflow; its policy proposal is in `docs/FINANCE_BUDGET_POLICY_PROPOSAL.md` and awaits product-owner/accountant approval. No budget implementation or later-phase work has been authorized by this continuation. A separate Phase 5 budget implementation is underway under the user-approved policy in `docs/FINANCE_BUDGET_POLICY_PROPOSAL.md`. The older sections below are a September 16 snapshot; the current master plan and dated checkpoints take precedence.

## Local runtime

- Application: `http://localhost:8000`
- Laravel 13 / PHP 8.5 / Inertia / Vue.
- Local Docker container: `z1erp-web`.
- MySQL and Redis containers are available. phpredis6.3.0 is now installed/enabled in the split PHP container; rerun sh scripts/install-local-redis.sh after container recreation. Schedule locks explicitly use Redis. Existing local queue/cache settings are unchanged.
- Safe local configuration: `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=database`.
- Local Laravel scheduler is running detached in `z1erp-web` with an exclusive file lock. Restart after web-container restart using `sh scripts/start-local-scheduler.sh`; logs are in `storage/logs/local-scheduler.log`. Daily notifications run at 08:00 in the application timezone.

## Completed foundation

- Multi-tenant organizations, organization roles, invitations, audit logs, tenant isolation.
- CRM leads, contacts, activities. Dynamic organization-owned pipelines, configurable stages/colors/order and lost reasons, owner/admin configuration permission, safe legacy backfill, audited manual movement/reopening with reason snapshots, and pipeline/stage/assignee filters and scoped counts. Explicit conversion records Won movement without duplicate contacts; manual Won movement does not create contacts. A configurable pipeline board now supports native desktop drag-and-drop into a confirmation dialog and keyboard/touch Move controls. Server validation/audit is reused; counts label visible and scoped totals. Stage entry rules are implemented: allowed source-stage lists, role restrictions and required existing fields, enforced on movement/reopening/conversion and initial-stage creation requirements. Server explanations feed board/list, and an audited list details editor resolves missing fields. Source-stage deletion is protected while referenced by rules. Pipeline reporting is implemented at `/crm/pipeline-report`: end-date historical outcomes, explicit customer conversion and new-lead conversion rate, preserved lost reasons, current-assignee performance, and clipped observed open-stage hours. Missing history is untracked; terminal waiting/pre-tracking time is excluded. Team/assignee visibility, cross-pipeline movement and automation remain pending.
- CRM lead assignment/unassignment to organization members and scheduled follow-up tracking with due-date ordering, overdue indicators, audited completion, and converted-lead guards. CRM overdue/due-today dashboard alerts feed the existing preference-controlled, deduplicated daily in-app digest; scheduler operation is required for timed delivery.
- Property/building/unit inventory and secured document uploads.
- Reservations, leases, sales contracts, broker attribution, commissions, tenant-linked lease renewal.
- Owners, tenants, brokers, listings, ownership percentages, brokerage commission plans.
- Move-in/move-out handovers; completing move-out completes the lease and makes the unit available.
- Invoices, partial payments, AED journal summaries.
- Vendor bills, property allocation, partial/full payments, journal summaries.
- Property operating report: owners, active contracted rent, vendor-bill commitments, maintenance actuals.
- Maintenance requests, vendors, estimated/actual costs, preventive maintenance plans and due-work-order generation.
- Compliance-document uploads for properties, leases, tenants, and vendors with expiry highlighting.
- Dashboard alert feed for expiring leases/documents, overdue invoices/vendor bills/maintenance, and due preventive plans.
- Procurement requests and lines, separate-manager approval, RFQs and quotations, purchase orders, receipt tracking, and draft vendor-bill conversion.
- Organization notification preferences, scheduled daily in-app alerts, per-user history and read state.
- AED accounting ledger: current finance overview, UAE VAT registration settings, transaction classifications, output/input VAT mappings, monthly/quarterly VAT201 preparation with detailed CSV, immutable manager-prepared snapshots, owner-only filing confirmation, audited post-filing adjustments, and finance-manager-approved VAT payments/refunds with linked reversals; IAS 16 fixed-asset register with audited non-financial transfers, annual estimate reviews, prospective estimate changes, impairment and disposal postings, depreciation, chart of accounts, opening balances, periods, audit trail, trial balance, account activity, journal register, draft financial statements, outstanding balances, and invoice/vendor-bill/payment mappings. Historical summaries remain unallocated.
- Manual bank CSV reconciliation: organization bank labels, idempotent signed-AED statement import, manual clearing-entry matches and filters. Finance managers link an asset ledger account and explicitly approve receipt/payment settlement postings; corrections use reversing journals. Matching alone does not post journals.
- Tenant-scoped global search across CRM, property, finance, and maintenance records, gated by each module's view permission.
- Lease security-deposit schedules create linked AED refundable-deposit invoices and show live collection status; refund/deduction/forfeiture policies remain pending.
- Lease PDC tracking covers scheduled, deposited, cleared, bounced, and linked replacement states without automatic journal entries.
- Lease service charges record category and service period, require an explicit VAT treatment for VAT-enabled organizations, create linked draft revenue invoices, and derive collection totals from invoice payments.
- Ejari tracking covers pending applications, registered numbers and expiry dates, 60-day operational alerts, and renewal records linked to immutable prior registrations; all transitions are tenant scoped and audited.
- Security-deposit settlements support itemized deductions and evidence references, freeze calculated refund balances on manager submission, and require owner approval. Refund posting is supported; deduction and forfeiture mappings remain pending.
- Finance managers can post the refundable balance of an owner-approved deposit settlement to Customer Deposits and Payment Clearing; outbound bank CSV matching and settlement use the existing reconciliation and reversal controls. Deduction and forfeiture postings remain pending.
- Rent-arrears deductions require an outstanding revenue invoice and can be posted by finance managers from Customer Deposits to Accounts Receivable as a non-cash invoice allocation.
- Damage, cleaning, utility, and other recovery deductions require explicit VAT treatment and can be posted by finance managers from Customer Deposits to Recovery Income, with Output VAT split from the gross amount when standard-rated.
- Deposit forfeiture requires a reason, evidence reference, explicit VAT treatment, owner-approved settlement, and finance-manager posting from Customer Deposits to Deposit Forfeiture Income and applicable Output VAT.

## Local migrations applied

- Migrations through `2026_09_16_000053_add_crm_stage_entry_rules.php` have been applied to local MySQL.

## Validation status

- Full backend regression most recently passed: 128 tests (1,525 assertions), after CRM pipeline reporting.
- Vue typecheck, formatting/lint, and production build passed after CRM pipeline reporting. Four Node frontend board grouping/eligibility tests passed. Local Chrome confirmed rules/detail repair and reporting totals, assignee filtering and mobile width with temporary isolated records and cleanup.
- The dashboard alert change passed focused dashboard tests; its UI formatter/typecheck was run.

## Important implementation notes

- Do not claim entire ERP completion: major enterprise modules remain.
- Maintain strict organization scoping for every query, mutation, upload, and download.
- Use AED as current application currency default.
- `property_owner` is the explicit owner/property pivot table (not Laravel's inferred `owner_property`).
- A prior MySQL migration failed due to a too-long index name; use explicit short index names for long tables.
- `public/build` must be refreshed after new Inertia pages or feature tests rendering the first HTML response can fail due to stale Vite manifest.
- Leasing acceptance fixes cover VAT deduction/reversal rows, dedicated rent-offset reversal with invoice restoration, lease invoice eligibility, and refund bank reversal ordering. Manual owner sign-off remains pending in `docs/LEASING_ACCEPTANCE_CHECKLIST.md`.

## Remaining roadmap

- Active continuation: Phase 6.1 maintenance reliability is implemented after user approval. See `docs/PHASE_6_MAINTENANCE_RELIABILITY_2026_09_27.md`. Domain actions, atomic occurrence generation with plan locks/unique plan-date keys and replay handling, organization-local eligibility/UTC deadlines, preserved assignment/completion dates and module-authorized CSV escaping are delivered. Full backend: 214 tests / 3,092 assertions; PHPStan, formatting/lint, TypeScript and production build passed. Real two-process generation and new-migration rollback/reapply pass; test-fixture cleanup verified zero checked identity/operations/audit rows in `testing`. Migration72 applied locally; no automatic requests/backfill. Phase 6.2 is implemented and locally validated: assigned-only technicians, managers all jobs, notes/checklist/labor/material/private evidence/history; consistent list/report/search/dashboard/alert scope. USER APPROVED per-job creation choice: manager-confirmation checked means technician submits and manager confirms; unchecked means assigned technician completes directly. Managers reopen with a reason for further changes; preserve policy/records/history, clear current-cycle markers, checked jobs require reconfirmation. Cancellation requires manager reason; required checklist enforced through job-card and legacy status route. Submitted/closed records and work orders read-only; pending reassignment requires reopening. Manual preventive generation supports the same per-occurrence choice; retries cannot change it. See docs/PHASE_6_JOB_CARD_CHECKPOINT_2026_09_27.md and approved design. Migrations73/74 applied locally. Full backend232/3416; operations42/603; PHPStan zero/Pint419/frontend lint/types/build passed. Guarded testing fixture check eight tables zero. Owner/device acceptance unrecorded. No pending completion question. User then approved6.3 preventive automation with each missed occurrence. This increment is complete: opt-in manager plan settings, inherited confirmation choice, active/pause controls, 15-minute Redis-locked schedule, max100 candidate plans/25 occurrences per plan/1000 jobs per run, organization-local due dates, latest20 occurrence links, safe visible failures/one-hour retry cooldown, per-occurrence transaction/audit rollback and shared manual/automatic locks/uniqueness. Full backend240/3490; operations50/677 including real manual-auto race; PHPStan zero/Pint422/frontend lint/types/build passed. Migration75 applied locally; nine checked testing tables zero. Installed phpredis6.3.0 in alternate local PHP Alpine container; schedule:list successful; started the previously stopped local scheduler with the existing exclusive-lock script and verified one PHP worker; local generation returned0created/0failed with all old plans opted out. See docs/PHASE_6_PREVENTIVE_AUTOMATION_2026_09_27.md. Spare parts were subsequently approved and completed as recorded in the latest checkpoint above;6.4–6.6 remain planned. Do not claim whole Phase6 complete. Phase 5 accountant/filing acceptance stays pending; earlier “Phase 6 not started” notes are historical.

- Dynamic CRM pipeline foundation, board, stage-entry rules and reporting are implemented; the foundation migration is applied locally; see `docs/CRM_DYNAMIC_PIPELINE_PLAN.md`. Technical Chrome acceptance checks passed with isolated temporary data and cleanup; see `docs/CRM_PIPELINE_ACCEPTANCE_CHECKLIST.md`. Owner business/device sign-off remains. Review fixes separate board heading/selector IDs and reset request state after successful movement. Later team/assignee visibility, cross-pipeline transfers, additional funnel analytics and automation increments require separate approval.

1. Accounting: accountant-approved corporate-tax rules.
2. Leasing compliance: deposits, PDC/cheques, service charges, Ejari/UAE workflows.
3. CRM automation, HR/payroll, projects/construction, facilities/assets.
4. Platform: approvals engine, imports/exports, API/webhooks, localization, backups, monitoring, production security/deployment.

## Latest Phase 5 checkpoint — 2026-09-27

- User-authorized local finance acceptance passed 76 finance tests / 1,417 assertions (28.46s). Post-run read-only check confirmed the `testing` database and zero records in all 15 checked identity/finance tables. See `docs/PHASE_5_FINANCE_ACCEPTANCE_2026_09_27.md`. Automated HTTP/domain checks only; browser/device, owner business acceptance, accountant decisions and actual filing comparisons remain pending.

- Customer cash refunds and supplier credit notes are implemented with owner approval; operating budgets are implemented.
- Historical mapping is now owner-controlled by default. Owners may grant/revoke approval authority to specific organization members acting as accountants. Mapping decisions and reversals are delegated separately from general finance editing. Removal revokes a grant; rejoining does not restore it.
- Explicit mapping proposals require source evidence, exact balanced original totals, active tenant-owned accounts, maker-checker decisions and open original periods. Approved lines attach to the original AED summary; no automatic backfill or duplicate journal is created. Filed-return dates block activation pending separate correction policy.
- Mapping UI: `/accounting/legacy-mappings`, linked from Accounting. Details and verified behavior: `docs/HISTORICAL_MAPPING_CHECKPOINT_2026_09_27.md`.
- Local migrations applied through `2026_09_27_000071_create_legacy_journal_mappings`.
- Latest full backend check passed 200 tests and 2,986 assertions; configured PHPStan, full Pint, frontend formatting/lint, TypeScript and production build passed.
- Written accountant financial-statement/tax sign-off and actual filing comparisons are still pending. Phase 6 had not started at this historical finance checkpoint; see the current Phase 6 continuation above.
- Prepared separate written statement/VAT/CT decision forms and an evidence checklist with verified existing export paths in `docs/PHASE_5_WRITTEN_ACCOUNTANT_SIGNOFF.md` and `docs/PHASE_5_ACCOUNTANT_EVIDENCE_CHECKLIST.md`. No accountant decisions, business evidence or actual filings supplied; no message sent. External acceptance remains pending.
