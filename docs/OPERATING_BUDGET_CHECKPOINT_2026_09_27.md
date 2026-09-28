# Operating budgets checkpoint — 2026-09-27

The user approved the budget policy in [FINANCE_BUDGET_POLICY_PROPOSAL.md](FINANCE_BUDGET_POLICY_PROPOSAL.md) and requested implementation. This is a Phase 5 increment; no later phase started.

## Delivered locally

- Finance now has organization-owned AED operating-budget versions for the current or future calendar year. Each version budgets active organization expense accounts across 12 monthly amounts.
- Finance managers can create drafts, seed revisions from the approved version effective in their selected month, incrementally edit account plans, remove lines, and submit an immutable version with its effective month and reason.
- Organization owners or administrators review the full submitted account and monthly plan before approving or rejecting. A submitter cannot approve/reject their own version. Rejection creates a new editable draft, preserves history and lines, and records the reason. If the effective month changes, the manager can explicitly reset lines from the approved version applicable to the new month.
- Future-dated versions take effect at the start of a current or future month. Approval rechecks that the date has not passed and that no affected month overlaps a closed accounting period. Existing versions are retained, and each report month shows the approved version then in force.
- Actuals sum detailed AED expense journal debits minus credits, including posted reversals. Historical unallocated summaries and unposted vendor bills, purchase orders, or maintenance estimates are excluded. Reports show monthly planned/actual/variance, year-to-date totals, remaining balance, and spreadsheet-safe CSV. No journal or posting block is created.
- Data, actions, approvals, exports, and actuals are organization scoped and audited. The Accounting landing page links to the report.

## Local validation

- Migration `2026_09_27_000069_create_operating_budgets_tables.php` is applied to the local ERP database.
- Nine authenticated budget routes are registered, including draft editing, rebase, submit, approve, reject and CSV export.
- PHP Pint and PHPStan level 7 pass. Frontend formatting/lint, TypeScript validation and production build pass. Wayfinder routes were regenerated and `public/build` refreshed.
- Full backend feature tests were not run during this increment.
- A later focused review added `OperatingBudgetTest.php` and exercised draft submission, maker-checker review, rejection/resubmission, approval, revision rebase, closed-period protection, reversal-aware actuals, and CSV. All 3 focused tests passed with 88 assertions. The same review replaced the report's optional `cal_days_in_month()` dependency with Carbon after the feature test exposed the missing PHP extension.
- The local ERP app is at `http://localhost:8000`; see [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) for the database port-conflict setup.

Property/project allocation, purchase commitments, revenue/cash forecasts, payroll, threshold alerts, and budget enforcement remain separately scoped.
