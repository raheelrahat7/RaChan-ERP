# Phase 6.4 internal service workflows — 2026-09-27

Selected scope: internal helpdesk, business-hours SLA targets, explicit acknowledgement, reasoned manager-approved holds, manager-only in-app breach alerts, and fresh reopening cycles with preserved history. Resolution stops at final completion, including confirmation when required. AMC and customer portal remain unselected.

The [service workflow design](PHASE_6_SERVICE_WORKFLOWS_DESIGN.md) records approved policy and delivered behavior.

## Delivery

- Maintenance links to `/operations/helpdesk`; job cards include service targets and cycle history.
- Managers explicitly enable targets and configure a job's working days, hours and holidays. Tracking starts at enablement and preserves its calendar/timezone/targets across reopening cycles.
- Assigned technicians and managers acknowledge jobs explicitly. Assignment/status changes do not acknowledge them.
- Both clocks subtract reasoned manager holds. Submission awaits required confirmation without stopping resolution time.
- Completed/cancelled cycles retain their timestamps; reopened jobs receive a new cycle. Repeated status/acknowledgement/notification calls do not duplicate cycles or alerts.
- Scheduler checks active cycles every fifteen minutes and creates one internal notification per breached target/cycle/eligible manager.
- All writes use organization-scoped job locks and existing audit transactions. Technician visibility remains assigned-only.

## Validation

- Focused SLA/calendar tests: **15 passed, 142 assertions**. Includes holiday/weekend/timezone boundaries, daylight-saving elapsed time, acknowledgement retries, hold deductions, submission/confirmation, reopening history, cancellations, alert retries, organization/assignment access, invalid policy rejection and audit rollback.
- PHPStan: no errors. Pint: 444 files passed.
- Frontend formatting/lint: 130 files passed. TypeScript and production build passed.
- Complete backend regression: **269 passed, 3,788 assertions**. The first run had one login-page failure while a concurrent frontend build temporarily removed the asset manifest; rerunning after the build finished passed the complete suite.
- Local migration 77 applied. The breach command returned zero notifications with no policies enabled; schedule listing confirms the fifteen-minute command. No jobs or targets were seeded.

Owner/device acceptance remains unrecorded. The selected internal service implementation is complete locally; it does not claim all Phase 6 or optional AMC/portal delivery.
