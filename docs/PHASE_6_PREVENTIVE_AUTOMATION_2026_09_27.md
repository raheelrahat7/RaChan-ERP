# Phase 6.3 — preventive automation checkpoint, 2026-09-27

Status: Preventive-automation implementation complete and validated locally. Spare-part tracking remains to be implemented. This increment covers scheduled preventive generation and recent occurrence history. Spare-part quantities and stock/financial policy remain separate work.

## Approved scope and behavior

After the next-step recommendation, the user said “goahead”. This proceeds with each missed occurrence, the recommended option, rather than dropping missed dates. The implementation uses existing organization-local eligibility dates, UTC end-of-day job deadlines and unique plan/date occurrence keys.

Each existing/new plan defaults to automatic generation off. Operations managers can enable automation, pause the plan, and select whether automatically created jobs require manager confirmation. Configuration is audited and does not generate jobs immediately. Generated jobs inherit the saved completion choice at creation, remain unassigned for manager allocation and use the existing job-card completion workflow. Later plan settings do not rewrite existing jobs. Manual generation retains its explicit per-job confirmation choice.

The scheduled command runs every 15 minutes. It examines at most 100 candidate plans ordered by due date, creates at most 25 occurrences per plan and 1,000 jobs per invocation, and continues large backlogs in later runs. The same locked domain creation path serves manual and automatic generation. The plan schedule advances only with a successfully committed job and audit. Each occurrence commits independently; a later failure retains earlier successful occurrences.

Eligibility is rechecked after locking the plan: active, opted in, due according to the current organization timezone and a valid positive frequency. Existing plan/date uniqueness and parent locks protect concurrent manual/automatic processing. Cancelled/completed occurrence records are not regenerated. A schedule pointing at an already recorded occurrence is flagged for review rather than silently dropping it.

A failed plan does not stop other candidates. It records a safe user-facing error and last attempt time, reports details in application logs, and waits one hour before automatic retry. Saving corrected settings clears the error and permits the next run; broken plans can be paused even if links are invalid. Successful generation clears previous errors. Managers see the latest 20 occurrence links, states and due dates on the preventive-maintenance page.

These jobs create no stock movements, finance postings or external messages. Their current due dates continue to drive existing operational alerts and aging.

## Running the scheduler

The entry is registered in `routes/console.php` with overlap protection and a single-server Redis scheduler lock. `.env.example` now documents Redis queue/cache defaults, matching the repository's required stack; existing local `.env` values are unchanged. No queue jobs or new dependency are introduced in this increment.

For a foreground development scheduler, run `php artisan schedule:work`. Production normally runs `php artisan schedule:run` from its host scheduler every minute. Production host cron/deployment configuration has not been installed by this increment. The local scheduler was not running at verification; it was started with the existing exclusive-lock script and now invokes fresh `schedule:run` processes each minute, picking up the registered entry. Redis and phpredis must be available for scheduler locks. The normal Sail image includes phpredis; `sh scripts/install-local-redis.sh` prepares the alternate Alpine PHP container after recreation.

The command `php artisan operations:generate-preventive` processes only opted-in active due plans. It prints created/failed counts and returns a nonzero exit status if any processed plan fails. Running it is a real write operation, not a preview.

## Validation

- Operations regression: **50 tests passed, 677 assertions**, including seven automation tests and a new real two-process manual/automatic race.
- Configured PHPStan: zero errors. PHP formatting: 422 files passed.
- Frontend formatting/lint, TypeScript and production build passed.
- Full backend regression: **240 tests passed, 3,490 assertions**.
- Migration75 was dry-run reviewed and applied locally after the full regression passed. Existing plans remain opted out.
- Guarded post-test inspection confirmed zero records in nine checked identity, operations, document and audit tables in `testing`.
- Local scheduler verification found the split PHP container lacked phpredis. Installed/enabled phpredis 6.3.0 using the new reproducible setup script; build dependencies were removed afterward. Redis-backed `schedule:list` now succeeds and shows the 15-minute preventive entry.
- Started the existing local scheduler script and verified one PHP scheduler worker under its exclusive lock.
- Local manual command verification returned **0 created, 0 failed**, confirming existing plans remain opted out. Shell syntax checks pass for the extension setup and scheduler scripts.

Fixtures use the isolated `testing` database; real concurrency checks guard that database and clean committed fixtures.

Owner/device acceptance and production scheduler operation remain separate from local automated validation. Phase 5 written accountant acceptance remains outstanding.
