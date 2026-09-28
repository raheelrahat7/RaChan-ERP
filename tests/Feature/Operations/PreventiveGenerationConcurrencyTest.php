<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PreventiveGenerationConcurrencyTest extends TestCase
{
    // Committed fixtures allow two independent connections to race the same occurrence.
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('testing', config('database.connections.'.config('database.default').'.database'));
        $this->assertFalse($this->app->configurationIsCached());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_occurrence_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_09_27_000072_link_preventive_maintenance_occurrences.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('maintenance_requests', 'preventive_maintenance_plan_id'));
        $this->assertFalse(Schema::hasColumn('maintenance_requests', 'preventive_due_on'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('maintenance_requests', 'preventive_maintenance_plan_id'));
        $this->assertTrue(Schema::hasColumn('maintenance_requests', 'preventive_due_on'));
    }

    public function test_parallel_generation_creates_one_request_and_advances_once(): void
    {
        $this->race(false);
    }

    public function test_parallel_automatic_and_manual_generation_share_one_occurrence(): void
    {
        $this->race(true);
    }

    private function race(bool $automatic): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Local', 'type' => 'residential']);
        $dueOn = ($automatic ? today() : today()->subDays(5))->toDateString();
        $plan = PreventiveMaintenancePlan::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'title' => 'Daily inspection', 'frequency_days' => 1, 'next_due_on' => $dueOn, 'auto_generate_enabled' => $automatic]);
        $barrier = tempnam(sys_get_temp_dir(), 'preventive-race-');
        $this->assertNotFalse($barrier);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->configurationIsCached() || config('database.connections.'.config('database.default').'.database') !== 'testing') {
    throw new RuntimeException('Testing database required');
}
$organization = App\Models\Organization::findOrFail((int) $argv[1]);
$actor = App\Models\User::findOrFail((int) $argv[2]);
$plan = App\Models\PreventiveMaintenancePlan::findOrFail((int) $argv[3]);
echo "ready\n";
flush();
$deadline = microtime(true) + 20;
while (file_get_contents($argv[5]) !== 'go') {
    if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout'); }
    usleep(10000);
}
if (($argv[6] ?? '') === 'auto') {
    echo app(App\Domain\Operations\Actions\ManagePreventiveMaintenance::class)->generateAutomaticNext($organization, $plan) ? '1' : '0';
    exit;
}
$item = app(App\Domain\Operations\Actions\ManagePreventiveMaintenance::class)->generate($organization, $actor, $plan, $argv[4]);
echo $item->id;
PHP;
        $arguments = [PHP_BINARY, '-r', $code, (string) $organization->id, (string) $manager->id, (string) $plan->id, $dueOn, $barrier];
        $environment = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $first = new Process($arguments, base_path(), $environment, null, 30);
        $second = new Process($automatic ? [...$arguments, 'auto'] : $arguments, base_path(), $environment, null, 30);
        try {
            foreach ([$first, $second] as $process) {
                $process->start();
                $this->assertTrue($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready')));
            }
            file_put_contents($barrier, 'go');
            $this->assertSame(0, $first->wait(), $first->getErrorOutput());
            $this->assertSame(0, $second->wait(), $second->getErrorOutput());
            if ($automatic) {
                $this->assertContains($second->getOutput(), ["ready\n0", "ready\n1"]);
            } else {
                $this->assertSame($first->getOutput(), $second->getOutput());
            }
            $this->assertDatabaseCount('maintenance_requests', 1);
            $this->assertSame("ready\n".MaintenanceRequest::sole()->id, $first->getOutput());
            $this->assertSame(($automatic ? today()->addDay() : today()->subDays(4))->toDateString(), $plan->fresh()->next_due_on->toDateString());
            $this->assertSame(1, AuditLog::where('event', 'operations.preventive_plan.generated')->count());
        } finally {
            $first->stop();
            $second->stop();
            unlink($barrier);
        }
    }
}
