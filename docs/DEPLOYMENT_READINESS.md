# Deployment Readiness

## Required production configuration

- Set `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, and the public `APP_URL`.
- Use managed MySQL 8.4 and Redis. Set `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`.
- Configure a persistent private filesystem disk for tenant documents; do not expose `storage/app/private` publicly.
- Set a production mail transport before sending invitations or password-reset notifications.

## Release procedure

1. Configure the production environment and run `php artisan release:check`. It checks configuration only, reports no secrets, and exits nonzero for unsafe defaults. It cannot verify credentials, connectivity, persistent storage, backups, or regulatory readiness.
2. Build frontend assets with `npm run build`.
3. Install Composer dependencies with production optimization enabled.
4. Run `php artisan migrate --force`.
5. Run `php artisan optimize` after configuration is final.
6. Start a supervised queue worker: `php artisan queue:work --tries=3 --timeout=60`. Keep the effective Redis `retry_after` strictly greater than the worker timeout (the repository default is 90 seconds). Check job-specific timeouts and supervisor shutdown grace periods before deployment; tune these together for any longer-running jobs.
7. Schedule `php artisan schedule:run` every minute through the platform scheduler.
8. Verify `/up`, authenticated dashboard, tenant isolation, document download authorization, and an AED invoice payment flow.

## Operational safeguards

- Back up MySQL before each migration and test restoration regularly.
- Retain Redis only as cache/queue infrastructure; MySQL remains the system of record.
- Restrict document storage access to the application runtime identity.
- Monitor failed jobs, queue lag, database health, disk capacity, and application errors.

## Local access review — 2026-09-21

- Inventory and compliance document downloads use the configured private `local` disk. Focused HTTP tests verify valid downloads, missing-file 404s, and denial of another organization's document and upload route. PHP static analysis and formatting pass for both changed controllers.
- The first-organization pilot journey also checks that a second-organization Manager cannot activate a lease, settle its bank line, or update its maintenance request.
- These checks are local regression evidence only. Before launch, verify the private disk is persistent, inaccessible from the web server, included in backup/restore drills, and review all role/tenant access in the deployed environment. An independent security review remains open.

## Inputs needed to deploy

- Hosting target and domain name.
- Production MySQL, Redis, mail, and private-object-storage credentials.
- Optional external integration credentials, such as payment gateway, accounting export, or messaging provider.

## Phase 6 release-readiness continuation

The user approved a platform-neutral plan and preparation of the owner/device review checklist on 2026-09-27. See [the Phase 6 acceptance packet](PHASE_6_RELEASE_ACCEPTANCE_2026_09_27.md) and [staging/release runbook](PHASE_6_RELEASE_RUNBOOK.md). Hosting/domain remain unselected. No staging deployment, production deployment, external delivery, or human sign-off is implied by local validation.

Production must schedule both `operations:generate-preventive` and `operations:notify-sla-breaches` through Laravel's scheduler. Their overlap and single-server locks use Redis. Monitor generation failures and SLA command failures as well as queue failures; these scheduled commands execute directly and are not covered solely by queue monitoring. Verify persistent private job evidence, stock movements, preserved SLA cycles and report exports in the staging rehearsal.
