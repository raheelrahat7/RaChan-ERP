# First-organization pilot sign-off packet — 2026-09-21

Latest continuation: [Phase 6 acceptance/readiness packet](PHASE_6_RELEASE_ACCEPTANCE_2026_09_27.md). The evidence below preserves the earlier review; it does not record later owner or production acceptance.

Use a disposable organization and invented people, properties, invoices and bank rows. Keep real customer data out of this review. Record a tester, date, result and issue link for every hands-on item. A passing automated test is evidence for the implementation, not a business or production sign-off.

## Historical local evidence — 2026-09-21

| Check                                           | Result                                                                                  | Scope                                                                           |
| ----------------------------------------------- | --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Laravel `/up`                                   | HTTP 200                                                                                | Local app container only                                                        |
| MySQL and Redis containers                      | Healthy                                                                                 | Local Docker services only                                                      |
| Scheduler                                       | Daily notifications, held-lead retry, follow-up reminders and overdue escalation listed | A persistent production scheduler is still required                             |
| Pilot, leasing and private-document regressions | 5 tests, 150 assertions passed                                                          | Disposable test database; includes tenant isolation and finance postings        |
| Frontend formatting/lint and TypeScript         | Route types regenerated; TypeScript, formatting and lint passed                         | No physical-device review                                                       |
| Production build                                | Passed with freshly generated Wayfinder routes                                          | Local build only; staging and production builds remain unverified               |
| `php artisan release:check`                     | Fails as expected locally                                                               | Local settings use debug, localhost, synchronous queue, file cache and log mail |

The latest full backend regression passed 165 tests and 2,293 assertions after the owner-statement increment. The earlier focused pilot rehearsal passed 5 tests and 150 assertions; owner review of the listing, inspection, vacancy and owner-statement paths remains pending.

## Owner acceptance

Tester: ______ Date: ______ Device/browser: ______ Result: pending

1. In a disposable organization, create Owner, Administrator, Manager, Member and Viewer users plus a second-organization user. Confirm CRM list and Kanban views on desktop and a physical phone, including keyboard/touch movement, stage history, Lost reason and reopening.
2. Assign members to separate departments and subdepartments. Confirm each Member sees only their assigned leads, Managers see their own leads and explicitly granted scopes, and Owners/Administrators see the organization. Grant and remove individual editing access and confirm visibility never expands because of the edit grant.
3. Configure a small round-robin pool, daily check-in, quota and a campaign/form route. Confirm hold notices go to Owners/Administrators and held leads retry in arrival order when capacity returns. Check follow-up reminders and optional 1-day, 1-week and 2-week escalation wording.
4. Complete every item in the [leasing acceptance checklist](LEASING_ACCEPTANCE_CHECKLIST.md), including AED 5,000 deposit collection, AED 3,500 refund, role separation, CSV settlement and reversals, Ejari and PDC flows. Record the observed journal balances and any wording or usability issues.
5. Record an internal inquiry from a listing and confirm its CRM lead shows the listing reference, follows normal assignment and visibility, and cannot be recorded against another organization's listing. Activate the listing, submit its public form while signed out, confirm the lead enters CRM, then pause the listing and confirm the public URL returns 404.
6. Run the human lead → contact → reservation → lease → invoice → AED payment → bank settlement → maintenance journey. Confirm private-document downloads and second-organization denial.

Owner decision: accept / accept with issues / reject Issue links: ______ Signed/date: ______

## Accountant review

Reviewer: ______ Qualification: ______ Date: ______ Result: pending

Provide a disposable period with the chart of accounts, journal register, trial balance, account activity, balance sheet, profit-and-loss, receivables/payables, bank reconciliation, deposit settlement and VAT/corporate-tax preparation exports. Review the [accounting decisions](ACCOUNTING_DECISIONS.md), account mappings, VAT treatments and reversal examples against source documents. Record corrections before relying on statements or tax workflows. FTA submission and regulatory compliance claims remain outside the pilot.

Accountant decision: accept for internal use / corrections required / reject Issue links: ______ Signed/date: ______

## Production readiness and release rehearsal

Operator: ______ Date: ______ Environment: ______ Result: pending

1. Supply hosting target, domain, public HTTPS URL, MySQL, Redis, mail transport and persistent private-storage design. Put secrets in the platform secret store, never in this packet or source files.
2. Run `php artisan release:check`, frontend build, production Composer install, migration backup, `php artisan migrate --force` and `php artisan optimize` in staging. Capture command outcomes and migration plan.
3. Restore a MySQL backup into an isolated staging database and restore private documents into isolated staging storage. Verify representative counts, download authorization and one document checksum before considering the restore proven.
4. Verify queue worker and scheduler persistence, failed-job monitoring, application errors, disk capacity, TLS, web-server access to private storage and a rollback procedure. Repeat the owner journey in staging with invented data.
5. Complete an independent security review and resolve launch-blocking findings. Follow [deployment readiness](DEPLOYMENT_READINESS.md) for the release procedure.

Operator decision: ready / blocked Evidence links: ______ Signed/date: ______

No production deployment, owner acceptance, accountant approval or live Meta delivery is recorded by this packet.
