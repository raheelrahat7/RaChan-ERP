# Leasing acceptance checklist

Run manual acceptance in a disposable organization with owner, manager, viewer, and a second-organization user. Record the tester, date, and outcome for each item. Automated regression does not substitute for owner acceptance.

- [ ] Create a deposit invoice, post it, collect AED 5,000, and confirm Accounts Receivable, Customer Deposits, and Undeposited Funds entries.
- [ ] Confirm partial collections update the lease-compliance collection total.
- [ ] Create draft deductions: AED 750 standard-rated recovery, AED 500 tenant rent offset, and AED 250 out-of-scope forfeiture. Confirm the refundable balance is AED 3,500.
- [ ] Reject a rent invoice belonging to another tenant, a draft invoice, and an amount exceeding its outstanding balance.
- [ ] Require a forfeiture reason/evidence and explicit VAT treatment for taxable-organization recoveries.
- [ ] Submit as manager; confirm deductions become immutable. Approve as owner; confirm manager cannot perform owner approval.
- [ ] Post recovery, rent offset, forfeiture, and refund as finance manager. Confirm duplicate posting is blocked.
- [ ] Confirm recovery splits AED 750 into AED 714.29 income and AED 35.71 Output VAT; forfeiture credits AED 250 to its dedicated income account.
- [ ] Confirm rent offset reduces the invoice balance without Undeposited Funds or bank movement.
- [ ] Import the outbound refund bank CSV, manually match Payment Clearing, and approve bank settlement. Confirm debit Payment Clearing / credit Bank for AED 3,500.
- [ ] Block refund-journal reversal while matched. Reverse bank settlement, unmatch, then reverse the refund. Confirm its reversed clearing entry cannot be matched again.
- [ ] Reverse a rent offset from lease compliance. Confirm a negative non-cash allocation restores the invoice balance and status; duplicate reversal is blocked.
- [ ] Confirm VAT preparation and CSV include posted recoveries/forfeitures and signed reversal rows in the journal posting period. Filed snapshots remain immutable; use the existing post-filing adjustment workflow for corrections to filed periods.
- [ ] Close the accounting period and confirm postings/reversals fail without changing balances or allocations.
- [ ] Confirm viewers cannot mutate records and users in a second organization cannot access settlement endpoints.
- [ ] Exercise Ejari application/registration/renewal and expiry alerts, PDC bounce/replacement, and service-charge invoicing on mobile and desktop.
- [ ] Record move-in and move-out condition observations by area, attach JPG/PNG/WebP photo evidence, and verify authorized download before completion. Confirm observations and evidence remain visible but cannot be added after completion.
- [ ] Complete a move-out and confirm an active vacancy appears with zero-day aging. Move it through maintenance, ready-to-list and listed states, then activate a new lease for the unit and confirm the vacancy leaves the active register.
- [ ] Assign two owners with different property shares. Review an owner statement period and CSV against posted service-charge income and operating-expense bills, including recoverable VAT exclusion. Confirm it creates no journal or payable and does not include deposits, capital assets, unlinked invoices, or another organization's activity.

## Review fixes

VAT preparation now includes recovery and forfeiture journals and their reversals. Rent offsets use a dedicated reversal that restores invoice allocations. Refund reversal requires bank unmatching, and reversed clearing entries are excluded from matching/settlement. Rent offsets require the lease contact or an invoice explicitly linked to the lease through a service charge.

Manual sign-off: pending.

## Local rehearsal evidence — 2026-09-21

The disposable-database workflow in [LeaseSecurityDepositTest.php](../tests/Feature/Transactions/LeaseSecurityDepositTest.php) passed with 109 assertions. It now verifies that AED 2,000 then AED 3,000 collections appear as AED 2,000 and AED 5,000 on the lease-compliance screen, and imports the AED 3,500 outbound refund through the bank CSV endpoint before manual matching, settlement and reversal. It also exercises the deduction totals, approval separation, journal splits, VAT preparation, closed-period protection, Ejari, PDC replacement and cross-organization denial above. The separate [pilot journey](../tests/Feature/PilotReleaseJourneyTest.php) covers lead-to-lease-to-bank-to-maintenance integration.

These are automated HTTP/database checks, not owner acceptance. The remaining hands-on review is the full checklist with an Owner, Manager, Viewer and second-organization user on desktop and a physical phone, including the exact accounting and user-facing wording. Record tester, date and result before checking items above or signing off.
