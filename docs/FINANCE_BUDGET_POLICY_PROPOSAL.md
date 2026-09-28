# Finance budgets — policy proposal

Status: approved for the first implementation increment after the user said “go ahead” on 2026-09-27. Scope is limited to the operating budgets described below.

## Problem and first-release boundary

Budgets remain a gap in the finance workstream. Finance already has organization-owned accounts, accounting periods, detailed journal lines, manager finance permissions, owner/administrator controls, audit logs, and spreadsheet-safe CSV conventions. This proposal uses those records for a read-only comparison between an approved operating expense budget and posted actuals.

The first increment provides organization-wide operating expense budgets by active expense ledger account for one calendar year, split into twelve monthly amounts. Owners and finance managers can review the year-to-date budget, actual expense, remaining budget, monthly variance, and an export. It creates no journal entries and does not block finance, procurement, or operational work.

This is a financial reporting workflow, not a purchase authorization or cash plan. It excludes revenue targets, cash-flow forecasts, project/property allocation, purchase-order commitments, construction budgets, payroll, automatic reforecasting, and budget-to-actual accruals.

## Proposed rules

1. A budget belongs to one organization, calendar year, and currency (AED). Each line references an active expense account from that organization's chart of accounts and records twelve monthly amounts. The annual budget is their sum. Asset, liability, equity, income, inactive, and foreign-organization accounts are not eligible.
2. A finance manager may create and edit a draft. Draft values can be saved incrementally; no actuals or journals are changed by editing a draft.
3. Submission snapshots the draft and sends it for approval. An organization owner or administrator approves or rejects it. The submitter cannot approve their own version. Rejection includes a reason and returns a new editable draft; the rejected snapshot stays in history.
4. An organization can have one active approved version for a year. Approval supersedes the prior version from the new version's effective date, which must be the first day of a month in that calendar year. The report retains the old approval and shows which version applied in each month. No prior approved amounts are rewritten.
5. A version becomes effective no earlier than its approval date. An initial version may begin in any current or future month, subject to the approval-date rule. A later revision can take effect from the current or a future month; it cannot retroactively change closed months or the approved year-to-date comparison. Creating a replacement requires a reason and explicit approval.
6. Actuals are detailed AED journal lines dated inside the selected month for the budgeted account, using debit minus credit (natural expense sign). Include original postings and linked reversals as separately posted ledger activity. Exclude historical summary-only journal entries because they have no account allocation. Actuals are reported as posted; draft vendor bills, purchase orders, and maintenance estimates are not accrued.
7. Monthly variance is actual minus budget: a positive amount is over budget. Year-to-date values sum months through the selected as-of month. A future month has a planned amount and no actual amount. A missing budget line is zero budget, with actual activity still visible.
8. The report is read-only and scoped to the current organization. Finance viewers can view/export; finance managers can draft/submit; owners and administrators can approve/reject. Every create, submit, approval, rejection, and superseding action is audited with actor, date, year, version, effective month, and reason.
9. CSV uses the same year, month, account, approval version, and totals as the page and escapes spreadsheet formulas in user-controlled values.
10. Budgets do not prevent posting, raise alerts, create approvals for bills, or change accounting periods. Threshold alerts and procurement commitments require a separate approved increment.

## Approved decisions

The user approved the policy as written, including:

- **Planning unit:** approve organization-wide account budgets by calendar year and month, with no property or project split in the first increment.
- **Approval:** approve the owner/administrator approval step and maker-checker separation for submitted versions.
- **Revision cutoff:** approve that changes take effect no earlier than approval, cannot rewrite closed months, and supersede the remaining active plan from an explicit month.
- **Actual basis:** approve posted detailed ledger activity only, excluding historical summaries and unposted commitments.
- **Enforcement:** approve advisory reporting only, with no posting blocks or automated alerts.

The acceptance scenarios are: organization isolation; expense-account validation; monthly/annual totals; draft, submit, reject, approve and supersede history; closed-month protection; posted actuals and reversals; unallocated historical entries; future months; formula-safe CSV; and role separation.

## Deferred choices

Property/project allocation requires an approved ledger tagging model because some journal activity has no property dimension. Purchase commitments require rules for approved purchase orders, receipts, vendor bills, cancellations, and overlap prevention. Revenue budgets, payroll, cash forecasts, threshold alerts, recurring templates, carry-forward, and midyear forecast revisions should be separately scoped.
