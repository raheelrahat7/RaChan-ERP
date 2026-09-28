# Phase 6.3 spare parts — design and pending stock decisions

Status: **Stock decisions approved; implementation complete and validated locally.** Preventive automation is complete; this is the remaining spare-parts portion of increment 6.3.

## Existing workflow review

The current Inventory module manages real-estate properties and units, not physical spare-part quantities. Procurement records purchase requests, quotations, orders, an order-level received date/note and optional supplier bills. Its receive action has no part catalogue, item receipt quantities or stock ledger. Job-card material entries record operational costs; they do not represent stock issues or available quantities.

Keep spare-part stock application logic in `app/Domain/Operations`, with thin HTTP controllers and Vue/TypeScript pages. The existing procurement and accounting workflows remain authoritative for orders, supplier bills and journals. Stock receipts must not silently convert order totals into quantities or generate financial postings.

## Approved stock decisions

The user selected separate balances for multiple stores/warehouses, operations-manager recording of all movements with technicians viewing their assigned jobs, and blocking negative stock. These answers are recorded approvals. Existing owner/administrator/manager operations permissions implement the selected manager authority.

No financial valuation, automatic cost allocation, supplier-bill generation or general warehouse access for technicians was approved. This increment records physical quantities only.

## Delivered increment

- Part catalogue: organization-owned unique code, name and unit label. Record an item's chosen unit; unit conversions and financial valuations are separate scope.
- Append-only quantity movement history with receipt, job issue, unused-part return and explicit correction records. Retain recorder/time, reference and reason; do not replace balances with unaudited manual edits.
- Current per-part/per-store balances updated atomically alongside the ledger. Use integer thousandths for quantities, validate precision and bounds, and lock the stock balance before mutation.
- Tie issues to an authorized organization-owned editable job. Submitted/completed/cancelled jobs follow the existing read-only rule; managers reopen before changing job stock records.
- Tie unused-part returns to their original issue and limit cumulative returns to the remaining issued quantity. Preserve location identity if multiple stores are selected.
- Corrections retain the original entry and create a reasoned opposing movement. Guard corrections against consumed receipts, returned issues and resulting stock/return inconsistencies according to the selected shortage policy.
- Protect duplicate submissions with an organization-scoped operation key and enforce matching payloads on retries. Audit and stock changes commit together.
- Show managers catalogue/balances/history and authorized readers job-specific issued/returned quantities. Preserve assigned-only technician visibility across stock links and every mutation.

Job-card cost subtotals and manual actual costs remain independent from quantities. No opening stock is inferred from past costs, purchase-order totals, bills or journals. Opening quantities, when supplied, must be recorded as explicit referenced receipts.

## Verification plan

Test authorization and foreign parts/jobs/locations, exact quantities, insufficient-stock handling, return caps, correction consistency, duplicate submissions, closed-job restrictions, transaction/audit rollback and concurrent issues competing for the same stock. Verify stock operations create no financial entries. Run PHP formatting/static analysis, focused and full regression suites, frontend formatting/lint, TypeScript and production build before reviewing/applying the local migration.

Migration76 creates part/store catalogues, stock balances and movement history. It is applied to local development after all quality gates passed. Tests use isolated fixtures; no business quantities or catalogue records have been created locally. Actual gate results are recorded in [the delivery checkpoint](PHASE_6_SPARE_PARTS_CHECKPOINT_2026_09_27.md).
