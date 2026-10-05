<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class DealAutomationRule extends Model
{
    protected $table = 'crm_deal_automation_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'conditions' => 'array', 'working_hours_only' => 'boolean'];
    }
}
