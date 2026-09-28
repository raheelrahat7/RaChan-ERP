# Phase 6.2 job-card checkpoint — 2026-09-27

Status: **Phase 6.2 implementation complete and validated locally. Owner/device acceptance remains unrecorded.**

The user authorized technician job cards and selected assigned jobs only for technicians, with operations managers managing all organization jobs. The user then approved a per-job creation choice: selecting manager confirmation requires technician submission and manager confirmation; otherwise the assigned technician can complete the job. Managers can reopen either type when dissatisfied or further changes are needed. The [approved design](PHASE_6_JOB_CARD_DESIGN.md) records compatibility and lifecycle rules.

## Delivered behavior

Maintenance links to a detail page showing job context, notes, checklist progress, labor/material entries, private evidence and audit history. Managers define checklist items and can void cost entries with a reason; assigned members record work against editable jobs. Notes preserve authorship; checklist changes preserve completion actor/time. Required checklist items are enforced before submission or completion through both job-card and existing status endpoints.

Current organization membership and assignment are checked on reads and under a request lock on writes. Reassignment revokes the former technician's access. Lists, overview, CSV exports, search, dashboard maintenance counters and operational alerts follow the same assigned-only scope. Managers retain organization-wide operations access. Nonmanagers do not receive preventive-plan lists/counts.

Labor/material entries use integer decimal arithmetic and half-up rounding. Active subtotals exclude voided lines and remain separate from the existing manual actual-cost field. These records create no stock movements, bills, payments or journals. Parts inventory remains increment 6.3.

Evidence is private JPEG/PNG/WebP/PDF, up to 10 MB. Dedicated and generic document downloads enforce current job access; private paths are omitted from page data. Failed database/audit writes remove uploaded files. Submitted/completed/cancelled cards reject record and work-order writes and retain authorized read access.

## Local validation

- Full backend regression: **232 tests passed, 3,416 assertions**.
- Operations regression: **42 tests passed, 603 assertions**, including nine new completion-policy tests for creation/generation choices, submission/confirmation, direct completion, reopening/review cycles, checklist enforcement, legacy-route bypass prevention, permissions and audit rollback.
- Initial focused job-card records suite: **9 tests, 173 assertions** covering assignment scope, revoked access, forged child IDs, costs, audit rollback/file cleanup, closed jobs, rounding and scoped alerts.
- Configured PHPStan: zero errors. PHP formatting: 419 files passed.
- Frontend formatting/lint, TypeScript and production build passed.
- Post-test guarded inspection confirmed zero records in the eight checked identity, operations, document and audit tables in `testing`.
- Migration `2026_09_27_000073_create_maintenance_job_card_records.php` was reviewed with a dry run and applied to local development MySQL. It adds notes, tasks and cost lines only.
- Migration `2026_09_27_000074_add_job_card_completion_policy.php` was dry-run reviewed and applied locally after the full regression passed. It adds the per-job confirmation choice and nullable submission/confirmation/completion metadata.

Automated checks are not owner/device acceptance or production deployment. The completion policy is approved as described above. No financial posting or external messaging was performed. Phase 5 written accountant acceptance remains pending.

## Approved lifecycle delivered

Creation and manual preventive generation offer an unchecked manager-confirmation checkbox. The saved choice cannot be changed through status/work-order controls or occurrence retries. Existing jobs receive the unchecked policy; historical completed dates remain intact and historical actor fields are not backfilled.

Unchecked jobs complete directly. Checked jobs submit as `in_progress`, remain in active/due-date reporting, and are read-only until a manager confirms or reopens them. Completion, submission and confirmation retain actors/timestamps; duplicate requests preserve dates. Managers reopen with a reason, clearing current-cycle markers while preserving policy, work records and audited history. Reopened checked jobs require confirmation again. Cancellation also requires a manager reason. Pending assignment changes require reopening.

Status and job-card endpoints share a locked domain workflow; required checklist and confirmation cannot be bypassed through the legacy route. Work-order updates require editable jobs. The interface exposes errors, actors/dates, awaiting-confirmation state and reopening reasons in history.

Later Phase 6 increments remain separately scoped. Owner/device acceptance is unrecorded; later policy-dependent scheduling, parts, service workflows and provider work remain planned.
