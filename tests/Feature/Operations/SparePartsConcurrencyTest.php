<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Actions\ManageSpareParts;
use App\Domain\Operations\Models\SparePart;
use App\Domain\Operations\Models\StockBalance;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Models\StockStore;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SparePartsConcurrencyTest extends TestCase
{
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

    public function test_stock_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_09_27_000076_create_spare_parts_stock.php');
        $costMigration = require database_path('migrations/2026_09_27_000078_create_stock_receipt_costs.php');
        $costMigration->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('stock_movements'));
        $this->assertFalse(Schema::hasTable('stock_balances'));
        $migration->up();
        $costMigration->up();
        $this->assertTrue(Schema::hasTable('stock_movements'));
        $this->assertTrue(Schema::hasTable('stock_stores'));
    }

    public function test_concurrent_jobs_cannot_issue_more_than_the_same_store_has_available(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Local', 'type' => 'residential']);
        $jobs = [];
        foreach (['A', 'B'] as $suffix) {
            $jobs[] = MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'reference' => 'MNT-'.$suffix, 'title' => 'Repair '.$suffix]);
        }
        $part = SparePart::create(['organization_id' => $organization->id, 'code' => 'FILTER', 'name' => 'Filter', 'unit' => 'pcs']);
        $store = StockStore::create(['organization_id' => $organization->id, 'code' => 'MAIN', 'name' => 'Main']);
        app(ManageSpareParts::class)->movement($organization, $manager, ['type' => 'receipt', 'spare_part_id' => $part->id, 'stock_store_id' => $store->id, 'quantity' => '5', 'reference' => 'Opening', 'operation_key' => (string) Str::uuid()]);
        $barrier = tempnam(sys_get_temp_dir(), 'stock-race-');
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
echo "ready\n";
flush();
$deadline = microtime(true) + 20;
while (file_get_contents($argv[7]) !== 'go') {
    if (microtime(true) > $deadline) throw new RuntimeException('Barrier timeout');
    usleep(10000);
}
try {
    app(App\Domain\Operations\Actions\ManageSpareParts::class)->movement($organization, $actor, ['type' => 'issue', 'spare_part_id' => (int) $argv[3], 'stock_store_id' => (int) $argv[4], 'maintenance_request_id' => (int) $argv[5], 'quantity' => '4', 'reference' => 'Race job', 'operation_key' => $argv[6]]);
    echo 'issued';
} catch (Illuminate\Validation\ValidationException $exception) {
    if (! isset($exception->errors()['quantity'])) throw $exception;
    echo 'shortage';
}
PHP;
        $environment = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $processes = [];
        foreach ($jobs as $job) {
            $processes[] = new Process([PHP_BINARY, '-r', $code, (string) $organization->id, (string) $manager->id, (string) $part->id, (string) $store->id, (string) $job->id, (string) Str::uuid(), $barrier], base_path(), $environment, null, 30);
        }
        try {
            foreach ($processes as $process) {
                $process->start();
                $this->assertTrue($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready')));
            }
            file_put_contents($barrier, 'go');
            $outputs = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outputs[] = $process->getOutput();
            }
            sort($outputs);
            $this->assertSame(["ready\nissued", "ready\nshortage"], $outputs);
            $this->assertDatabaseCount('stock_movements', 2);
            $this->assertSame(1000, StockBalance::sole()->quantity);
            $this->assertSame(1000, (int) StockMovement::sum('delta'));
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            unlink($barrier);
        }
    }
}
