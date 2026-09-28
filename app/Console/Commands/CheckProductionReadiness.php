<?php

namespace App\Console\Commands;

use App\Support\Deployment\ProductionReadiness;
use Illuminate\Console\Command;

class CheckProductionReadiness extends Command
{
    protected $signature = 'release:check';

    protected $description = 'Check production configuration without printing secrets or changing state';

    public function handle(ProductionReadiness $readiness): int
    {
        $failures = $readiness->failures();

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            return self::FAILURE;
        }

        $this->info('Production configuration checks passed. Complete the manual release gates before deploying.');

        return self::SUCCESS;
    }
}
