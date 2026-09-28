# Phase 6.5 operations reporting

The user approved Phase 6.5 reporting and dashboards after the selected internal Phase 6.4 workflows were completed. This increment adds read-only reporting at `/operations/reports`, linked from the operations overview, with a matching CSV at `/operations/reports.csv`.

## Filters and access

- Property, vendor, assignee, current status, priority, and inclusive job creation dates.
- Creation dates are interpreted in the organization's timezone and converted to UTC bounds. The through-date includes the whole local day.
- Creation dates select a **job cohort**. They do not select completion events, stock movements or cost-entry dates, and they are not historical snapshot dates.
- Managers see organization jobs. Technicians see assigned jobs only; filtering by another assignee cannot widen that scope. Page, aggregates, details and exports share this scope.
- Property, vendor and assignee selectors reject foreign organization IDs. Membership permission is required for page and export access.
- Details paginate at 25 jobs. CSV exports all matching jobs, including every recorded SLA cycle, independently of the selected page. User-controlled cells are escaped against spreadsheet formulas.

## Metric definitions

**Job status and aging:** current status counts. Active aging includes open, in-progress and on-hold jobs, using whole elapsed 24-hour periods since creation, bounded below by zero. Buckets are 0–2, 3–7, 8–30 and 31+ days. Completed and cancelled jobs have no active age. Hold time remains part of job aging; the SLA clock separately deducts approved holds.

**Workload:** current active, completed and on-hold counts grouped by current assignee, including unassigned jobs. Completed counts do not attribute historical work to a previous assignee. Assignees missing organization membership are labelled unavailable rather than exposing foreign user names.

**Operational costs:** exact integer-cent totals grouped by each job's currency. Estimated cost and manually entered actual cost remain separate from recorded job-card labor and material costs. Voided lines and foreign-organization lines are excluded. Missing manual values are counted and not presented as recorded zero values on job details. No currency conversion, stock valuation, bill or journal posting occurs.

**Preventive results:** generated preventive jobs with a recorded occurrence date due by today in the organization's calendar. Classification is due today unfinished, outstanding overdue, completed on time, completed late, completion date unknown, or cancelled. Completion before the following local midnight is on time. The displayed on-time percentage uses completed jobs with known completion dates only. Cancelled, unfinished and unknown-date jobs are shown separately and are not hidden in that denominator. Reopened jobs use their current state/latest completion. Future occurrences and occurrences without generated jobs are excluded; upcoming/overdue plans remain available in the existing overview. This is not reconstructed historical plan compliance.

**SLA results:** counts of active, completed and cancelled cycles for matching jobs, preserving previous cycles after reopening. Breaches are separated into active and completed response/resolution counts. Cancelled cycles have no breach result. Calculations share one report-generation instant for active cycles and use stored acknowledgement/closure timestamps for historical cycles. Jobs without configured targets do not contribute cycles.

## Boundaries

No schema changes are required. Saved filters, scheduled delivery, external messages, AMC, customer portal and Phase 6.6 release acceptance are outside this increment. Historical job-status/workload trends cannot be inferred from current state; no such trends are claimed.

The page and CSV are live read-only reports. Filters and timing rules match; concurrent business changes can affect repeated queries or separate requests. They are not a persisted accounting snapshot.

Implementation and automated validation are complete locally. See [the reporting checkpoint](PHASE_6_REPORTING_CHECKPOINT_2026_09_27.md) for validation evidence. Owner/device acceptance remains unrecorded.
