# Quality gate review — 2026-09-22

The user requested correction of all errors found during the Phase 5 continuation review. The initial scan reported 627 PHPStan errors; four were corrected with the earlier ledger fix, leaving 623 at the start of this cleanup.

## Corrections

- Enabled Larastan parsing of model `casts()` methods so dates, booleans, and JSON attributes have their actual runtime types. Kept analysis at level 7 without adding a baseline, exclusions, or ignored errors.
- Added related-model, query, collection, report, and input type contracts. Used scalar IDs for validated single-record lookups and explicit model checks for polymorphic CRM subjects and assignment pools.
- Made nullable-value checks explicit, guarded CSV output stream creation, and made JSON serialization failures explicit in the finance audit export.
- Added validation responses for unsupported CRM configuration and routing types.
- Fixed the corporate-tax page's year fallback: apply the current-year default before converting the selected year to an integer. Regression coverage checks omitted, empty, and explicit years with an April tax-year start.

## Final local verification

| Check                      | Result                                    |
| -------------------------- | ----------------------------------------- |
| Full PHPStan, level 7      | Zero errors                               |
| PHP Pint                   | 361 files pass                            |
| Full backend suite         | 169 tests, 2,363 assertions pass          |
| Frontend formatting        | 145 files pass                            |
| Frontend lint              | 118 files pass without warnings or errors |
| TypeScript                 | Pass                                      |
| Wayfinder route generation | Pass                                      |
| Production frontend build  | Pass                                      |

PHP checks ran in the existing `z1erp-web` container, with tests using the configured disposable testing database. Frontend checks used the existing Node 22 image. The build still emits an informational notice about the optional font fallback optimizer; no dependency was added.

The error-cleanup task is complete. Phase 5 remains the active development phase. Credit-note policy approval and the separately documented human and production release gates remain unchanged.
