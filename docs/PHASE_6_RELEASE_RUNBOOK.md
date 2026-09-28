# Phase 6 platform-neutral staging and release runbook

Status: prepared for review. Hosting target and domain are not selected. This document is a procedure, not evidence that staging or production actions have been performed.

## Operator inputs

Record the operator, target environment, source revision or immutable artifact identifier/checksum, public HTTPS domain, MySQL 8.4/Redis services, production mail transport, persistent private-storage location, process supervisor, monitoring destination, backup retention and recovery objectives. Keep credentials in the platform secret store; do not put them in this document or issue comments. Optional providers remain unselected.

## Build and staging rehearsal

1. Build an immutable artifact from the reviewed source. Record Composer/npm lockfile identifiers, build outcome and artifact checksum. Install production Composer dependencies with optimized autoloading and no development dependencies. Generate Wayfinder routes in PHP before a split-container frontend build; set `WAYFINDER_GENERATED=1` for that build. Run backend tests against the disposable testing database after the build completes, so asset regeneration does not remove the manifest during HTTP tests.
2. Provision isolated staging MySQL, Redis and persistent private storage. Configure a new staging application key and HTTPS URL. Use a controlled mail destination or sink; do not send to real customers. Do not use production credentials/data for this invented-data rehearsal.
3. Configure `APP_ENV=production`, `APP_DEBUG=false`, MySQL, Redis queue/cache, final URL and mail transport. Run `php artisan release:check`. Record its exit code; a pass validates configuration only and does not prove connectivity, mail delivery, backup capability or business acceptance.
4. Review `php artisan migrate:status` and `php artisan migrate --pretend` against the selected staging database. The current local schema includes migration 77 (job SLA cycles). Obtain and verify a pre-migration database backup before actual target migrations. Apply `php artisan migrate --force`, then `php artisan optimize` only after target configuration is final.
5. Supervise `php artisan queue:work --tries=3 --timeout=60` with automatic restart. Effective Redis `retry_after` must exceed the worker timeout; the repository default is 90 seconds. Supervisor termination grace must exceed job execution time. Reconcile job-specific timeouts before tuning either setting. Restart workers after deploying changed code.
6. Configure the platform to run `php artisan schedule:run` every minute and verify `php artisan schedule:list`. Use shared Redis for scheduled overlap/single-server locks. Verify both operations commands and existing authorized CRM/notification schedules. Monitor command exit failures and the preventive-plan error field; successful queue checks alone do not prove direct scheduled commands are healthy.
7. Verify `/up`, authentication, organization permissions, private document/evidence downloads, stock balances and job confirmation/reopening. Rehearse the owner scenarios in the [acceptance packet](PHASE_6_RELEASE_ACCEPTANCE_2026_09_27.md). Use invented organization data and explicitly enable preventive automation/SLA targets only for that rehearsal.

## Backup and restore proof

1. Record database/private-storage backup identifiers, timestamp and intended recovery point; protect application-key/secret recovery through the platform's secret-management procedure.
2. Restore the database and associated private files into a second isolated staging target. Never restore over the running organization merely to test restoration.
3. Verify representative counts, organization access, stock ledger/balance consistency, completed and reopened SLA cycles, and document/evidence downloads. Compare at least one private file checksum with its source backup.
4. Record measured restore duration, achievable recovery point and discrepancies. Confirm they meet the owner's selected recovery objectives. A backup's existence is not restore evidence.

## Monitoring and release decision

Monitor application errors, failed jobs/queue lag, scheduler exits, generation failures, database connectivity, Redis availability, private-storage capacity and backup freshness. Agree who responds and how incidents reach that person before launch. Record independent security-review findings and their disposition. Verify TLS and that the web server cannot serve private storage directly.

Collect owner/device, operator/staging/restore/security and written accountant decisions separately. Reference actual evidence in the acceptance packet. Finance/tax tests are internal-policy evidence, not an actual filing comparison or regulatory approval.

Before a production action, identify the concrete target, reviewed artifact, migration/backup plan, expected interruption and rollback plan for user approval. Local Phase 6.6 approval authorizes readiness preparation; it is not a production deployment or external-message instruction.

## Rollback preparation

Retain the previous application artifact and compatible configuration. Prefer a compatible application rollback or reviewed forward fix. Do not automatically roll back business migrations or restore an old database over new financial/stock activity. If database recovery is necessary, stop writes, define the data-loss window, coordinate database/private-file recovery and obtain an explicit recovery decision before execution. Record the affected transactions and reconciliation plan.

## Evidence fields

Operator: ______ Environment/domain: ______ Artifact/checksum: ______

Configuration check/exit: ______ Migration/backup evidence: ______

Worker/scheduler persistence and monitoring: ______ Restore duration/recovery point: ______

Security-review result: ______ Rehearsal/issues: ______

Decision: ready / blocked. Signed/date: ______
