# Customer credit-note checkpoint — 2026-09-27

The user approved the proposed Phase 5 customer-credit-note accounting policy and requested implementation. The approved boundary is recorded in [ACCOUNTING_DECISIONS.md](ACCOUNTING_DECISIONS.md).

## Delivered

The Invoices page now supports reasoned credit-note drafts, posting confirmation with an accounting date, and reasoned reversals. Finance-manager permissions and current-organization isolation apply to every mutation. Posted notes retain original amounts, VAT, actors, reasons, dates and linked journals.

Posting locks the invoice and note, checks the original detailed AED revenue posting, rejects reversed sources, and caps the amount at the current outstanding balance. An open period and active original accounts are required. Repeated posting/reversal is rejected. Payment and rent-offset allocation limits include active credits. General-ledger reversal cannot bypass the credit-note workflow or reverse an invoice with active credits.

VAT allocation follows the original posting, with cumulative cent rounding. Credits are separate from cash payments. Historical receivables, CSV exports, VAT preparation, dashboard balances and owner statements incorporate dated credits and reversals. Accounting and Leasing consume Finance services/queries rather than querying credit-note storage directly. Filed VAT return snapshots remain unchanged.

## Verification

- Full backend regression: **185 tests, 2,754 assertions pass**.
- Added 12 focused credit-note tests covering posting accounts, reversal, duplicate operations, balance races simulated through competing drafts/payments, VAT rounding, historical reports, CSV, dashboard, owner statements, rent offsets, period/account restrictions, tenant boundaries, roles and invalid inputs.
- PHPStan level 7: zero errors, no baseline or suppressions added.
- PHP Pint: passes.
- Frontend formatting/lint, Vue/TypeScript validation and production build: pass.
- Wayfinder routes generated; local migration applied; login HTTP smoke check returns 200.
- Browser interaction and physical-device acceptance were not performed during this increment.

PHP validation uses `z1erp-web` and the disposable `testing` database. Node validation uses the existing Node 22 image. The optional font-fallback optimizer notice remains informational; no dependency was added.

## Local runtime and remaining scope

The app is available at `http://localhost:8000`. The ERP database uses its existing volume via the internal-only `z1erp-mysql-local` container because another project occupies host port 3306. See [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) before restarting database containers.

Cash refunds, customer credit balances, supplier credit notes, over-balance credits and budgets remain outside this implementation. Phase 5 remains active; later phases and existing human/production release gates are unchanged.
