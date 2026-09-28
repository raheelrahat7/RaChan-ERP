# Phase 5 accountant evidence checklist

Status: **Preparation ready; evidence and accountant decisions pending.** No business exports, independent calculations or actual filings are attached.

## Collect the review bundle

1. Select the intended organization and record legal entity, organization ID, tax profile, review dates and export timestamp. Export through an authorized finance account.
2. Replace the placeholders below with agreed dates and record IDs. Dates use `YYYY-MM-DD`; VAT `period` uses `YYYY-MM`. Paths are relative to the application URL and require an authenticated session.
3. Save unchanged exports with unique evidence IDs and record filters, generation time and file locations. Preserve reviewed copies if data changes later.
4. Add underlying documents, independent calculations, historical mapping decisions and material corrections/reversals.
5. Add actual filed returns and acknowledgments. If unavailable, record the reason and leave the filing comparison pending.
6. Share the bundle and [written sign-off form](PHASE_5_WRITTEN_ACCOUNTANT_SIGNOFF.md) with the accountant for separate statement, VAT and corporate-tax decisions.

## Existing application exports

| Evidence                  | Path and review filters                                          | Context                                                                                                            |
| ------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Balance sheet             | `/accounting/statements/balance-sheet.csv?as_of=AS_OF`           | An accounting period must contain the cutoff.                                                                      |
| Profit and loss           | `/accounting/statements/profit-and-loss.csv?from=FROM&to=TO`     | Use the agreed interval.                                                                                           |
| Trial balance             | `/accounting/trial-balance.csv?as_of=AS_OF`                      | Specify cutoff explicitly.                                                                                         |
| Journal register          | `/accounting/journal-register.csv?from=FROM&to=TO`               | Omit event filtering; add earlier supporting journals where needed.                                                |
| Account activity          | `/accounting/activity.csv?account_id=ACCOUNT_ID&from=FROM&to=TO` | Export material accounts with opening/closing balances.                                                            |
| VAT preparation           | `/accounting/vat-return.csv?period=YYYY-MM`                      | Organization frequency selects the month or calendar quarter; verify exported dates against intended filing dates. |
| Corporate-tax preparation | `/accounting/corporate-tax.csv?return_id=RETURN_ID`              | Requires an existing prepared return in the selected organization.                                                 |

VAT CSV calculates from current records; include the stored return snapshot and adjustments from the VAT page separately and reconcile differences. Corporate-tax CSV describes the stored return; include supporting profit reports and independently reviewed adjustments. Application filing references alone do not establish submission.

## Supporting evidence

- Statements: chart of accounts, invoices/bills, receipts/payments, bank reconciliation, deposit/rent offsets, refunds, customer/supplier credits, assets/depreciation/disposals and reversals. Document excluded summary journals and their impact; include historical mapping evidence and decisions.
- VAT: tax profile/TRN/frequency, source tax documents, recoverability rationale, credit calculations, snapshots/corrections, settlements/refunds, independent totals, filed return and acknowledgment.
- Corporate tax: profile/year, profit-to-taxable-income bridge, adjustments, independently checked eligibility/relief evidence, provision/payment journals, independent calculation, filed return and acknowledgment.

## Evidence manifest

| Evidence ID | Entity / organization | Report or document | Period / cutoff | Filters or record ID | Generated / obtained at | File location and revision | Finding IDs |
| ----------- | --------------------- | ------------------ | --------------- | -------------------- | ----------------------- | -------------------------- | ----------- |
| Pending     |                       |                    |                 |                      |                         |                            |             |

## Cover note for the owner to share

> Please review the Phase 5 financial statements, VAT policy and corporate-tax policy for [legal entity], using bundle [reference/revision] for [dates]. Record separate written decisions in the sign-off form, including differences against independent calculations and actual filings. Identify required changes, evidence references and limitations. Where filing evidence is unavailable, mark that comparison not reviewed. Sign and date each decision and recheck required corrections before acceptance.

No message has been sent. Preparation does not complete external acceptance or submit returns to the FTA.
