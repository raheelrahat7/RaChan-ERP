# Phase 6.3 spare-parts checkpoint — 2026-09-27

Status: **Spare-parts implementation complete and validated locally. Together with preventive automation, this completes the selected Phase 6.3 implementation.** The user approved multiple stores/warehouses, operations-manager recording of all stock movements, technicians viewing assigned-job parts, and no negative stock.

## Delivered workflow

Managers open Spare parts and stores from Maintenance or Manage job stock from a job card. They create organization-owned part and store codes, choose each part's unit, record referenced receipts/opening stock, issue available stock to an editable organization-owned job, and return unused quantities against their original issue. Stores have separate balances; returns use the original part/store/job and cannot exceed the issue's outstanding quantity.

Quantities use integer thousandths without floating-point rounding: each movement accepts 0.001–999999.999; a balance cannot exceed 999999999.999. No unit conversion is inferred. Balances and recorded movement deltas commit together, including audit records. Tenant-owned job, organization, part/store and original movement locks serialize changes and operation-key retries. The organization lock also prevents competing operation keys on different parts from producing conflicting duplicate writes.

Repeated submissions with the same operation key and normalized payload return the original record. Different payloads are rejected. A successful retry does not add another movement, even if the job subsequently closes. New writes require an editable job; submitted/completed/cancelled jobs must be reopened by a manager with a reason before their parts change.

Corrections append a whole-entry reversal with a required reason and mark the original with its reversal ID. Entries are not deleted or overwritten. A consumed receipt/return cannot be reversed if doing so would make stock negative. Active unused-part returns must be reversed before their original issue is corrected. Already reversed entries and corrections themselves cannot be reversed. Record corrected quantities as a new referenced movement after an eligible reversal.

The stock page displays per-store balances and paginated movement history with part/store/job filters, reference, date, related/reversal IDs and reasons. Job cards show only their own stock movements and retain assignment authorization. Technicians cannot browse the stock manager page or record receipts/issues/returns/corrections.

Physical quantities do not update operational cost entries or manual actual costs, create finance journals/bills, or infer opening stock from historic purchase-order totals. Procurement's existing order-level received status remains distinct from these explicit quantity receipts. Inter-store transfers, valuation and financial postings are separate capabilities.

## Validation and migration

- Focused stock/quantity tests: **12 tests passed, 139 assertions**.
- Real two-process stock race and migration rollback/reapply: **2 tests passed, 17 assertions**. Of two competing issues of four units against a five-unit receipt, one succeeds and the other is rejected; available stock and ledger sum both remain one unit.
- Configured PHPStan: zero errors. Full PHP formatting: 434 files passed.
- Frontend formatting/lint, final generated-route TypeScript validation and production build passed.
- Full backend regression: **254 tests passed, 3,646 assertions**.
- Migration `2026_09_27_000076_create_spare_parts_stock.php` was dry-run reviewed and applied locally after all gates passed. It creates catalogues/balances/history only; no opening stock or business records were seeded.
- Guarded post-test inspection confirmed zero records in all twelve checked identity, job, stock, document and audit tables in `testing`.

New tests cover multi-store balance separation, exact quantities, shortage/return caps, correction consistency, tenant permissions, closed jobs, idempotency and audit rollback. A committed-fixture concurrency test runs two PHP processes competing for the same store balance in the guarded `testing` database. Migration rollback/reapply is also checked there.

No business stock records or opening quantities have been entered locally. Owner/device acceptance and production rollout remain unrecorded. Written Phase 5 accountant acceptance remains pending.
