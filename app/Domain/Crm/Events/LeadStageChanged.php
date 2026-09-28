<?php

namespace App\Domain\Crm\Events;

use App\Domain\Crm\Models\LeadStageHistory;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class LeadStageChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly LeadStageHistory $history) {}
}
