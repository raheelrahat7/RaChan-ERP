<?php

namespace App\Support\Deployment;

class ProductionReadiness
{
    /** @return list<string> */
    public function failures(): array
    {
        $failures = [];

        if (config('app.env') !== 'production') {
            $failures[] = 'APP_ENV must be production.';
        }

        if (config('app.debug')) {
            $failures[] = 'APP_DEBUG must be false.';
        }

        if (! is_string(config('app.key')) || config('app.key') === '') {
            $failures[] = 'APP_KEY must be configured.';
        }

        $url = config('app.url');
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            $failures[] = 'APP_URL must be a valid public HTTPS URL.';
        }

        if (config('database.default') !== 'mysql') {
            $failures[] = 'DB_CONNECTION must be mysql.';
        }

        if (config('queue.default') !== 'redis') {
            $failures[] = 'QUEUE_CONNECTION must be redis.';
        }

        if (config('cache.default') !== 'redis') {
            $failures[] = 'CACHE_STORE must be redis.';
        }

        if (in_array(config('mail.default'), [null, 'log', 'array'], true)) {
            $failures[] = 'MAIL_MAILER must use a delivery transport.';
        }

        if (config('chat.virus_scan.driver') === 'off') {
            $failures[] = 'CHAT_VIRUS_SCAN must not be off; chat attachments must be scanned.';
        }

        return $failures;
    }
}
