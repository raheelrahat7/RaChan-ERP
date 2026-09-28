# Local Development

The project uses Laravel Sail with PHP 8.5, MySQL 8.4, and Redis.

## Start services

```sh
./vendor/bin/sail up -d
```

The application is available at `http://localhost`. MySQL is available on port `3306` and Redis on port `6379`.

## Application commands

```sh
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test
./vendor/bin/sail npm run dev
./vendor/bin/sail npm run build
./vendor/bin/sail npm run typecheck
./vendor/bin/sail npm run lint
```

## Environment configuration

Sail resolves `DB_HOST=mysql` and `REDIS_HOST=redis` over its Docker network. The MySQL database is `z1_erp`. Sail uses Redis-backed cache and queues; the current split-container workaround retains its existing file cache and synchronous queue settings, while scheduler locks explicitly use Redis. Keep credentials local; `.env.example` contains safe template values for non-Sail environments.

If PHP and Node run in separate containers, generate Wayfinder routes in the PHP container with `php artisan wayfinder:generate --with-form`, then run the Node build with `WAYFINDER_GENERATED=1`. A normal Sail build generates routes automatically.

## PHP Redis support in the split-container setup

The normal Sail PHP image includes phpredis. The alternate `php:8.5-cli-alpine` web container needs the extension for Redis scheduler locks:

```sh
sh scripts/install-local-redis.sh
```

This installs/enables the extension in `z1erp-web` and skips an already prepared container. Run it after recreating that alternate container. It does not change local credentials or queue/cache configuration. Redis must be running before schedule commands execute.

## Notifications and preventive automation

For the current split-container environment (`z1erp-web`, application at `http://localhost:8000`), start the detached scheduler with:

```sh
sh scripts/start-local-scheduler.sh
```

The script uses an exclusive file lock to prevent duplicate workers. Output is written to `storage/logs/local-scheduler.log`. Rerun the script after restarting or recreating the web container; it is a local worker, not a production process supervisor. Notifications remain scheduled for 08:00 in the application timezone. Opted-in preventive plans generate missed occurrences every 15 minutes using organization-local dates; configure them on Preventive maintenance. See [the preventive automation checkpoint](PHASE_6_PREVENTIVE_AUTOMATION_2026_09_27.md) for processing limits and failure handling.

The scheduler runs `notifications:generate-daily` at 08:00 in the application timezone (UTC by default). It creates in-app notifications for current dashboard alerts, once per category per user per day. Organization owners and administrators can choose categories or disable the daily run on the Notifications page. Run the command manually with `./vendor/bin/sail artisan notifications:generate-daily` when testing local delivery. Production must run `schedule:run` every minute as described in `DEPLOYMENT_READINESS.md`. Email delivery is not enabled.

## Port 3306 conflict workaround (2026-09-27)

Another local project occupies host port 3306. The ERP database is currently running as `z1erp-mysql-local`, created with `docker compose run -d --no-deps --use-aliases --name z1erp-mysql-local mysql`. It uses the existing ERP MySQL volume and `mysql` network alias without exposing a host port. The original `z1-erp-mysql-1` container remains stopped. The web and Redis containers retain their existing configuration; the app is at `http://localhost:8000`.

Resume this setup with `docker start z1erp-mysql-local z1-erp-redis-1 z1erp-web`. Do not start the original MySQL container while the workaround container is running: both use the same database volume. To return to the original container after host port 3306 is free, stop `z1erp-mysql-local` first, then start `z1-erp-mysql-1`.
