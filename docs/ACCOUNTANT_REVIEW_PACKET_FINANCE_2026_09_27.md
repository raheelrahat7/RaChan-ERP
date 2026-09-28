# Accountant review packet — finance outputs

Status: **prepared for external review; no accountant approval or live filing validation is recorded.** This packet describes current application behavior and asks the reviewer to confirm or correct it. It is not tax, accounting, or legal advice and does not represent that any report is compliant for filing.

## Review record

Use the [written sign-off form](PHASE_5_WRITTEN_ACCOUNTANT_SIGNOFF.md) for separate statement, VAT and corporate-tax decisions. The [evidence checklist](PHASE_5_ACCOUNTANT_EVIDENCE_CHECKLIST.md) lists existing export paths, supporting documents and a bundle manifest. Forms are prepared; evidence and signatures remain pending.

| Field                                               | To be completed by reviewer |
| --------------------------------------------------- | --------------------------- |
| Organization / legal entity                         |                             |
| Reviewer name and qualification                     |                             |
| Review date                                         |                             |
| Periods and sample records reviewed                 |                             |
| Result: approved / approved with changes / rejected |                             |
| Required changes and owner                          |                             |
| Written sign-off reference                          |                             |

## Financial statements and ledger reports

Review the Accounting balance sheet, profit-and-loss statement, trial balance, account activity, and journal register against source journals for a representative period and as-of date.

- Confirm that report scope, account classification, natural debit/credit signs, current-period profit, and prior accumulated results agree with the entity's accounting policy.
- Confirm that the balance sheet date falls in the selected accounting period and that the period's opening and closing presentation is appropriate.
- Confirm whether detailed AED entries alone are sufficient for the intended internal use. Historical summary-only journals remain excluded until explicit account mappings are approved by the owner or an accountant the owner has authorized. No allocation is inferred. Review the source evidence, approval history, original-date report effect and any dated correction journals for activated mappings.
- Confirm that reversals, closed-period corrections, payment clearing, bank settlements, credit notes, and depreciation/disposals are represented on the dates and accounts expected.
- Confirm that these pages are labelled as internal drafts until review is complete. Comparative statements and external filing are outside the current behavior.

Record sample report URL, selected date/period, ledger extract reference, and any difference:

| Report / period | Source checked | Difference | Reviewer decision |
| --------------- | -------------- | ---------- | ----------------- |
|                 |                |            |                   |

## VAT preparation and settlement

Review the organization's tax profile and a sample of invoices, vendor bills, customer credit notes, supplier credit notes, customer cash refunds, reversals, return snapshots, adjustments, settlements, and refund receipts. New supplier-credit notes reduce detailed expense/asset and recoverable input VAT accounts and appear as dated negative purchase rows; cash refunds are owner-approved and post against receivables and payment clearing. Validate these new classifications and correction dates before relying on the affected return preparation.

- Confirm the stored TRN and the organization's selected monthly or calendar-quarter return period.
- Validate classifications and tax calculations against source documents and current approved policy, including standard-rated, zero-rated, exempt, and out-of-scope transactions.
- Check invoice VAT-exclusive pricing, vendor-bill VAT-inclusive totals, recoverable input VAT, original-rate credit-note reversals, cumulative rounding, and post-filing adjustment treatment.
- Reconcile the preparation totals and transaction detail to the organization's accounting records and the reviewer's independently calculated return.
- Confirm that the FTA reference/date fields are internal records only. The application does not submit a VAT return to the FTA.

## Corporate-tax preparation

Review the organization's tax year, selected entity profile, detailed-ledger starting profit, documented adjustments, relief elections/attestations, calculation, provision, payment, and due-date presentation.

- Validate each embedded rate, threshold, eligibility test, and tax-year treatment against the organization's approved policy and rules applicable to the reviewed period.
- Confirm that adjustments are supported by notes and source evidence and that the detailed-ledger profit agrees to the reviewed financial statements.
- Review any Free Zone or small-business-relief elections and supporting eligibility evidence independently; application attestations do not establish eligibility.
- Confirm that approval, provision, internal filing reference, and payment are separate workflow steps and that the application does not submit a corporate-tax return to the FTA.

## Operating budget report

Confirm the selected calendar year, effective version by month, closed-period protection, treatment of detailed AED expense debits minus credits, treatment of reversals, and exclusion of unallocated summaries and unposted commitments. Review the report and CSV against source ledger activity. The budget is advisory and creates no accounting entries.

## Findings and sign-off

Do not mark any statement or tax output approved from a development or feature-test result alone. Record reviewer findings above and link the exact report exports and filing comparisons reviewed. If the reviewer requires accounting or tax behavior changes, translate each into an approved policy decision before implementing it.
