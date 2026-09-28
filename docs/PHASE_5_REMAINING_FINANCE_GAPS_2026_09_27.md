# Phase 5 remaining finance gaps — 2026-09-27

This review separates coding work from decisions and external acceptance that code cannot supply. The operating-budget workflow and its focused feature coverage are implemented locally.

## 1. Customer refunds, unapplied customer credits, and supplier credit notes

The user approved a scoped customer cash-refund and supplier-credit workflow on 2026-09-27, with owner approval and maker-checker separation. Managers may submit requests; an organization owner must approve, reject, or reverse. Customer cash refunds require a posted credit note and are capped by the unrefunded note plus eligible direct customer cash. Rent/security-deposit offsets do not count as cash. Supplier credits are capped at original bill gross, reverse detailed source accounts and proportional recoverable VAT, and remain unapplied against cash already paid. Both workflows post on open-period dates and preserve dated reversals and audit events. The later owner-authorized vendor cash-return increment records money received back from a supplier against actual overpayment; it does not add an outbound vendor payout. See ACCOUNTING_DECISIONS.md and the full-roadmap continuation backlog.

Customer credits above the open receivable are now allowed only up to unused original invoice gross and direct cash collected. Any cash refund is separately owner-approved and posted through the refund workflow. The implementation and tests do not constitute accountant approval of its VAT policy; supplier-credit VAT treatment and filing effect remain in the external review packet.

## 2. Historical summary-only journal entries

Old `journal_entries` may contain balanced totals without detailed account lines. They are intentionally excluded from detailed-ledger reports and period-close blockers identify unallocated summaries. Existing records can also carry currencies or mappings that do not match the new AED ledger.

The user approved a customizable mapping workflow: owners approve by default and may grant/revoke approval authority to specific organization members acting as accountants. A finance manager submits explicit account allocations with a reason and source-evidence reference. The requester cannot approve or reject their own proposal. Approval activates the original summary's detailed lines, preserving its ID, reference, date, currency, source and debit/credit totals. Allocations must balance exactly and use active organization-owned accounts. Unapproved proposals never enter reports; accounts are not inferred automatically.

Activation requires an open accounting period containing the original date. Non-AED, already detailed, reversed and future journals are excluded. Dates covered by filed VAT returns or affecting a filed corporate-tax return are blocked pending a separately reviewed correction workflow. Decisions and delegation changes are audited. A dated linked reversal preserves the original lines and history; matched bank rows must be unmatched first. Removing a member revokes delegation, and rejoining does not restore it.

## 3. Accountant review of financial statements

The balance sheet and profit-and-loss reports are explicitly internal drafts based on detailed AED journal lines. They have no accountant approval recorded. A product change cannot substitute for accountant review; the open item is an external sign-off, with any resulting mapping or report changes handled afterward.

The output-by-output questions and review record are in [ACCOUNTANT_REVIEW_PACKET_FINANCE_2026_09_27.md](ACCOUNTANT_REVIEW_PACKET_FINANCE_2026_09_27.md).

## 4. Accountant and filing validation for VAT and corporate tax

The application prepares internal VAT and corporate-tax reports and supports internal approval/filing records. It does not submit returns to the FTA. Accountant-approved tax policy and validation against actual filings remain outstanding. Do not describe the reports as compliant filings or add external filing behavior until the accountant confirms rules and provides a validated filing workflow.

The packet lists the current VAT and corporate-tax assumptions for an accountant to validate; it does not claim those assumptions have been approved.

Review preparation is complete: the [written sign-off form](PHASE_5_WRITTEN_ACCOUNTANT_SIGNOFF.md) separates statements, tax-policy decisions and actual filing comparisons; the [evidence checklist](PHASE_5_ACCOUNTANT_EVIDENCE_CHECKLIST.md) provides verified export paths and a cover note. Entity-specific evidence, accountant decisions and filing comparisons remain pending. No review message has been sent.

## Proposed sequence

The user-authorized local finance acceptance run passed **76 tests / 1,417 assertions** with temporary fixtures rolled back. Representative results, cleanup verification and review limits are recorded in [PHASE_5_FINANCE_ACCEPTANCE_2026_09_27.md](PHASE_5_FINANCE_ACCEPTANCE_2026_09_27.md). This completes the automated local acceptance check; it does not complete external sign-off.

1. The owner-approved customer refund and supplier-credit increment is implemented locally and covered by focused tests. Written accountant review of financial statements and VAT treatment remains outstanding.
2. The owner-controlled historical mapping workflow is implemented. An owner or explicitly delegated accountant must review each proposal before activation; no automatic backfill is run.
3. Send the existing financial-statement and tax outputs for accountant review and record the findings outside the application until their required workflow is clear.
4. Implement only the changes that follow from those approved decisions. Re-run tenant, authorization, accounting, reversal, and report tests before treating any increment as complete.
