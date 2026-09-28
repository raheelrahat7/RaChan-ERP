# Phase 6.4 service workflows — draft

Status: selected internal helpdesk/SLA scope implemented and validated locally; migration 77 applied. AMC and customer portal are not selected.

Phase 6.1–6.3 implementation is complete. The [implementation plan](PHASE_6_IMPLEMENTATION_PLAN_2026_09_27.md) identifies service workflows as the next increment. AMC, SLA, helpdesk and customer portal were excluded from the [pilot](PILOT_RELEASE_SCOPE_2026_09_21.md) unless separately selected.

## Approved decisions

- Internal helpdesk, SLA targets and internal escalation alerts.
- Business-hours calendars with configurable working days and holidays.
- Resolution at completed status, including manager confirmation where required.
- Explicit technician or manager acknowledgement counts as first response.
- Only manager-approved holds with a recorded reason pause both clocks.
- Breach alerts go to operations managers inside the app.
- Reopening a completed or cancelled job starts a new cycle and preserves the previous result. Returning a submitted job for more work continues its existing active cycle.

Managers configure targets and the calendar explicitly on each active job. No company working hours or holidays are assumed. This first increment provides job-level configuration; shared policy templates are a separate addition.

## Delivered behavior

- `/operations/helpdesk` lists organization-scoped jobs with search, status filters, pagination and latest SLA results. Technicians see only assigned jobs.
- Job cards show current and previous cycles, targets, elapsed working minutes, breach flags, acknowledgements and hold reasons.
- Enabling tracking starts the first cycle at that moment; it does not backdate a service commitment to job creation. Existing jobs remain without targets until configured by a manager.
- Calendar, timezone and response/resolution targets are snapshotted and cannot be replaced for the job. Reopening copies that policy into a fresh cycle. Organization timezone changes do not rewrite existing cycles.
- Calendars use ISO weekdays (Monday 1 to Sunday 7), one same-day working window per selected day, and local holiday dates. Targets use whole working minutes; calculations preserve elapsed seconds. Breach means strictly more than the target.
- Elapsed time counts actual seconds within local windows, including daylight-saving changes. Nonexistent local working boundaries are rejected rather than normalized silently. Calculation intervals and deadlines are limited to ten years; targets are limited to 525,600 working minutes.
- Assignment and moving to In progress do not acknowledge a job. Repeated acknowledgement preserves its first actor and timestamp.
- SLA holds require manager status changes and a reason. A manager must resume a held SLA job before technician submission. Hold time is deducted from both response and resolution clocks.
- Technician submission does not stop resolution time when manager confirmation is required. Final confirmation or direct completion closes the cycle. Cancellation closes it without a breach result; prior alerts remain historical records.
- `operations:notify-sla-breaches` runs every fifteen minutes with Redis overlap/single-server locks. Each active cycle/target generates at most one in-app alert per eligible manager. Already closed cycles remain visible in history but are not newly alerted by this command. No external messages are sent.
- Job locks serialize policy creation, acknowledgement, status transitions and notification checks; updates share existing job transactions and audit rollback behavior.

Migration: `2026_09_27_000077_create_job_sla_cycles.php`. No financial tables, AMC billing, portal access, or automatic targets for preventive jobs are introduced.

## Existing integration points

- Maintenance requests already provide organization, property, unit, priority, assignment, status and completion timestamps.
- `ManageJobCompletion` owns submission, confirmation, completion, holds and reasoned reopening. SLA lifecycle updates should use the same transactions and preserve historical cycles when current completion markers are cleared.
- `JobCardAccess` enforces manager access to all organization jobs and technician access to assigned jobs. Internal helpdesk views should preserve these rules.
- Organization timezone provides the basis for local service calendars; stored event timestamps should remain UTC.
- Existing organization notifications and scheduled commands can support internal breach alerts after recipient rules are selected. External delivery needs separate selection.

## Proposed implementation sequence

1. Record selected scope and service policies in this document.
2. Add domain-owned policy/calendar definitions and immutable job lifecycle records necessary for accurate elapsed-time calculations. Snapshot applied targets so policy edits do not silently rewrite existing commitments.
3. Integrate first-response, hold/resume and resolution events with job actions, with audited manager configuration and organization-scoped access.
4. Add internal helpdesk filters and job-card SLA visibility, then repeat-safe scheduled breach notifications for approved recipients.
5. Implement AMC or portal only if selected and their access/coverage rules are resolved.
6. Verify calendar boundaries, timezone handling, holds, confirmation, reopening, policy changes, notification retries and cross-organization access; run repository quality gates.

Validation and local migration status are recorded in the [service-workflows checkpoint](PHASE_6_SERVICE_WORKFLOWS_CHECKPOINT_2026_09_27.md). Owner/device acceptance remains separate from automated validation.
