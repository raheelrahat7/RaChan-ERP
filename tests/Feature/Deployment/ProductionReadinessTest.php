<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\ProductionReadiness;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_local_defaults_fail_with_actionable_messages_without_exposing_secrets(): void
    {
        config()->set('app.key', 'sensitive-test-key');

        $this->artisan('release:check')
            ->expectsOutput('APP_ENV must be production.')
            ->expectsOutput('APP_URL must be a valid public HTTPS URL.')
            ->assertFailed();

        $this->assertStringNotContainsString('sensitive-test-key', implode(' ', app(ProductionReadiness::class)->failures()));
    }

    public function test_production_configuration_passes(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'sensitive-test-key',
            'app.url' => 'https://erp.example.test',
            'database.default' => 'mysql',
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'mail.default' => 'smtp',
            'chat.virus_scan.driver' => 'clamav',
        ]);

        $this->artisan('release:check')
            ->expectsOutput('Production configuration checks passed. Complete the manual release gates before deploying.')
            ->assertSuccessful();
    }
}
