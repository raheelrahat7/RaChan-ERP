<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationExecution extends Model
{
    protected $table = 'crm_automation_executions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'scheduled_at' => 'immutable_datetime'];
    }
}
