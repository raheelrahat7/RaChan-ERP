# Phase 6.2 technician job cards — approved design

The user approved assigned-only technician access, with operations managers managing all organization jobs. The completion rule was then approved as a per-job creation choice: selecting manager confirmation requires submission and manager confirmation; leaving it unchecked allows assigned technicians to complete their job. Managers may reopen either type if dissatisfied or more changes are needed.

## Creation and compatibility

The maintenance creation form includes an unchecked “Require manager confirmation before completion” option. Manual preventive work-order generation offers the same choice for the generated occurrence. A retry returns the existing occurrence without changing its recorded policy. The policy cannot be changed through status or work-order updates.

Existing requests receive the unchecked policy through an additive migration; historical completion dates are preserved and new actor fields remain null for historical completions. No automatic completion, financial backfill, notification delivery or request generation is performed.

## Job-card records

Use the existing maintenance request and its property/unit, vendor, assignment, priority, dates and manual cost fields. Organization-owned service notes retain author/time. Managers define required/optional checklist items; assigned technicians record progress. Labor/material cost entries use integer decimal arithmetic, retain void corrections with reasons and show active subtotals independently of manual actual cost. Private image/PDF evidence reuses Document storage with job-specific download authorization and rollback cleanup.

Operational entries do not move stock or post accounting journals, bills, payments or deposit deductions. Spare parts remain increment 6.3.

## Completion and reopening

- Finish requires current organization membership, current assignment or manager access, and all required checklist items completed. Optional items do not block completion.
- Unchecked jobs complete directly, recording completion actor/time. Managers retain direct completion authority through existing status controls.
- Checked jobs submit for confirmation, recording submission actor/time and remaining `in_progress` until confirmed. Submitted cards and work orders are read-only. Pending work remains in active reporting and retains its existing due-date aging.
- Only operations managers confirm checked jobs, after submission. The existing status endpoint uses the same checklist and confirmation rules and cannot bypass them.
- Managers reopen submitted/completed/cancelled jobs with a reason. Reopening restores `in_progress` (or the explicit active status requested through existing controls), clears current-cycle submission/confirmation/completion fields and preserves policy, checklist progress, notes, evidence, costs and audited prior history. Technicians can then make changes and finish again according to the saved policy.
- Manager cancellation requires a reason and clears current completion markers; cancelled work must be reopened before finishing. Assignment cannot change during pending confirmation without reopening.
- Repeated submission, completion and confirmation preserve the original dates. Domain mutations lock the parent request and commit their audit atomically; audit failure rolls back the workflow.

## Access and user interface

Technician scope applies across maintenance lists/details, private evidence, mutations, overview/CSV, search, dashboard counters and operational alerts. Reassignment/member removal revokes access immediately on the next request. Managers retain organization-wide operations management. Tenant-scoped child queries reject forged checklist/cost/document IDs.

The job card shows its saved policy, submission/confirmation/completion actors and dates, applicable action buttons, validation errors, and history including reopening reasons. Required checklist flags are enforced on submission, direct completion and manager completion. Submitted and closed records remain readable to authorized members.

Actual delivery and quality checks are recorded in [the job-card checkpoint](PHASE_6_JOB_CARD_CHECKPOINT_2026_09_27.md). Owner/device acceptance and production rollout are separate from automated local validation.
