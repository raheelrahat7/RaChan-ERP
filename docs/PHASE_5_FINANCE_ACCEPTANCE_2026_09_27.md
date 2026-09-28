# Phase 5 local finance acceptance — 2026-09-27

Result: **76 finance feature tests passed, with 1,417 assertions**, in 28.46 seconds. The user authorized local acceptance checks using temporary test data. No application behavior or accounting policy was changed.

## Method and isolation

Ran the existing finance suite in the local PHP container:

```sh
docker exec z1erp-web sh -lc 'cd /workspace && php artisan test tests/Feature/Finance'
```

The suite creates representative organizations, roles, accounts, source documents and transactions, then exercises authenticated HTTP routes and domain queries. `phpunit.xml` selects the `testing` database, with array mail/cache/session drivers. Finance tests use Laravel `RefreshDatabase` to roll back transaction fixtures. CSV downloads are exercised and their content asserted in memory; no business export bundle was generated.

A separate post-run, read-only check confirmed the connected database was `testing` and configuration was not cached. All checked tables had zero remaining rows: organizations, users, invoices, payments, vendor bills, customer credit notes/refunds, vendor credit notes, journals/lines, mapping proposals/delegations, VAT returns and corporate-tax returns. No temporary acceptance records remain in those tables.

## Representative outcomes

All amounts below are AED and describe synthetic fixtures only.

| Workflow                      | Verified result                                                                                                                                                                                                                                                                                                         | Test evidence                                                                |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| Invoicing and receipts        | Posting, partial payments and settlement work; historical summary payments stay unallocated.                                                                                                                                                                                                                            | `InvoiceWorkflowTest`                                                        |
| Customer credits and refunds  | A 100 invoice with 80 collected permits an 80 refund after credit; 100 refund is rejected. Manager approval is blocked, owner approval posts Dr receivables / Cr payment clearing for 80. Reversal restores current cash capacity while the earlier cutoff stays unchanged. Deposit offsets provide no refundable cash. | `CustomerRefundWorkflowTest`, `CustomerCreditNoteTest`                       |
| Supplier credit               | A 1,050 bill with a 105 credit reduces payable to 945 and recoverable input VAT from 50 to 45. Owner approval is required. Reversal restores 1,050 payable and 50 recoverable VAT.                                                                                                                                      | `VendorCreditNoteWorkflowTest`                                               |
| Historical mapping            | A 100 summary remains excluded until approval; approved lines enter reports on the original date without a duplicate journal. Dedicated reversal retains original lines. Owner delegation/revocation and membership removal are enforced.                                                                               | `LegacyJournalMappingTest`                                                   |
| Mapping protections           | Invalid totals, changed sources, closed periods, foreign accounts, non-AED sources, self-approval and filed-return conflicts are rejected. Bank matching blocks reversal until unmatched.                                                                                                                               | `LegacyJournalMappingTest`                                                   |
| Financial statements          | Prior results 100 plus current revenue 50 less expenses 20 gives current profit 30. Assets and equity including results both equal 140. Balance-sheet and profit-and-loss CSV values match the reports.                                                                                                                 | `FinancialStatementsTest`                                                    |
| VAT lifecycle                 | Output/input VAT postings, preparation CSV, internal snapshots, adjustments, settlement and refund receipt are exercised.                                                                                                                                                                                               | `VatWorkflowTest`                                                            |
| Corporate tax                 | Year selection, configured profile calculations, internal approval/provision/filing/payment and reversals pass. A representative prepared return exports 9,000 tax payable with the asserted due date.                                                                                                                  | `CorporateTaxReturnTest`                                                     |
| Banking and period controls   | Reconciliation does not duplicate journals; dated bank settlement/reversal and closed-period protections work. Period readiness flags ledger blockers.                                                                                                                                                                  | `BankReconciliationTest`, `AccountingLedgerTest`, `PeriodCloseReadinessTest` |
| Reporting, assets and budgets | Account activity, trial balance, journal register, audit trail, outstanding balances, owner/property reports, asset workflows and budget revisions/actuals pass their fixtures and applicable export assertions.                                                                                                        | Remaining finance feature tests                                              |

## Acceptance limits and remaining work

No failures were found in this run. These are automated HTTP/domain acceptance checks; no browser interaction, physical-device usability review, owner business sign-off or independent accountant decision was performed. Synthetic internal filing references do not represent an actual FTA submission. Tax tests verify the implemented policy, not external approval of that policy.

Written financial-statement acceptance and VAT/corporate-tax policy approval with actual filing comparisons remain pending. Prepare entity-specific evidence using [the evidence checklist](PHASE_5_ACCOUNTANT_EVIDENCE_CHECKLIST.md), then obtain separate decisions using [the written sign-off form](PHASE_5_WRITTEN_ACCOUNTANT_SIGNOFF.md). Phase 6 has not been started.
