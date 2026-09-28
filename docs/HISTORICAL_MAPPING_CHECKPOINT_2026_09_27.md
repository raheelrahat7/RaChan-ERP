# Historical journal mapping checkpoint — 2026-09-27

The user approved owner-controlled mapping approval, with optional permission for an accountant. The workflow is available from Accounting → Historical journal mappings (`/accounting/legacy-mappings`).

Owners can grant or revoke mapping decision/reversal permission for specific organization members. Owners retain approval authority; administrators and managers do not gain it automatically. Delegation does not grant general finance editing permissions. Removing a member revokes the grant, and rejoining does not restore it.

Finance managers submit explicit balanced account lines, a reason and a source-evidence reference. Submission preserves the original summary snapshot and posts no account lines. The requester cannot approve/reject their own proposal. Approval rechecks the source snapshot, active organization-owned accounts and totals, requires an open period on the original date, and atomically adds lines to the existing AED summary. Original journal metadata/totals stay unchanged and no duplicate journal is posted. Rejected proposals remain in history and can be replaced. Generic journal reversal cannot bypass mapping permissions; a workflow reversal creates a dated linked journal while keeping the original lines. Matched bank rows must be unmatched first.

Non-AED, future, already detailed and reversed sources are excluded. Activation is blocked for dates covered by filed VAT returns or affecting filed corporate-tax returns, pending a separate correction policy. Written external financial-statement/tax review is still outstanding; mapping permission is not that sign-off.

## Validation

- Full backend suite: **200 tests, 2,986 assertions passed**.
- Configured PHPStan level 7: **no errors**.
- Full PHP formatting: **395 files passed**.
- Frontend formatting/lint and TypeScript validation passed.
- Production build passed after generating Laravel route types in the PHP container.
- Focused coverage includes owner approval, delegated approval/revocation, maker-checker controls, organization isolation, source changes, inactive/foreign accounts, original-date report activation, period-readiness changes, duplicate prevention, rejection/resubmission, filed-return protection, bank match reversal protection and membership removal/rejoining.

Migration `2026_09_27_000071_create_legacy_journal_mappings` was reviewed with a dry run and applied to the configured local database. It creates proposal and delegation tables only. No historical journal was automatically allocated, and no mapping grant or approval was created on behalf of an owner.
