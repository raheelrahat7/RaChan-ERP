# Phase 6.5 reporting checkpoint — 2026-09-27

Scope approved: operations reports and dashboards covering job status/aging, technician workload, operational costs, generated preventive-job results, preserved SLA cycle results and matching filtered CSV exports.

Implementation and automated validation are complete locally. Metric definitions and boundaries are recorded in [the reporting design](PHASE_6_REPORTING_DESIGN.md).

## Delivered

- `/operations/reports`, linked from the existing operations overview, with responsive summaries, status bars, workload/cost tables and paginated job details.
- Shared property/vendor/assignee/status/priority/job-creation-date filters for all metrics, job details and `/operations/reports.csv`.
- Creation-date boundaries respect organization timezone. Aging is current elapsed whole days and includes holds.
- Costs use exact integer cents, separate currencies, exclude voided/foreign lines, and distinguish manual actuals from job-card costs and missing values.
- Preventive results distinguish due today, overdue outstanding, known on-time/late completion, unknown completion dates and cancellations; future/ungenerated occurrences are excluded explicitly.
- SLA counts retain closed-cycle history after reopening and separate active from completed breaches. Cancelled cycles have no breach result.
- Technicians remain limited to assigned jobs throughout summaries, details and exports. Foreign selector IDs are rejected.
- CSV includes all matching pages and cycle details, records filters and generation time, and escapes spreadsheet formulas.
- No schema changes, financial writes, external delivery, saved filters or Phase 6.6 implementation.

## Validation

Focused reporting tests: **7 passed, 165 assertions**. PHPStan: no errors. Pint: 449 files passed. Frontend formatting/lint, TypeScript and production build passed. Full backend regression: **276 passed, 3,953 assertions**. Focused tests cover status/aging/workload filters, fractional cost totals across currencies, void/foreign line exclusion, local preventive due-date boundaries, missing legacy completion timestamps, retained SLA cycles, cohort boundaries, all-page exports, formula safety, assignment/organization permissions and empty/invalid reports.

No migrations were required and no local business data was seeded. Owner/device acceptance remains unrecorded. The dashboard represents live current records and stored SLA results; no reconstructed historical status trends or persisted accounting snapshot are claimed.
