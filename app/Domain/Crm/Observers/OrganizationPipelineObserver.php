<?php

namespace App\Domain\Crm\Observers;

use App\Domain\Crm\Actions\InitializePipelines;
use App\Models\Organization;

class OrganizationPipelineObserver
{
    public function created(Organization $organization): void
    {
        app(InitializePipelines::class)->handle($organization);
    }
}
