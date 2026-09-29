<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'pipeline_id', 'stage_id', 'name', 'trigger', 'condition_field', 'condition_operator', 'condition_value', 'action', 'due_days', 'active', 'created_by'])]
class AutomationRule extends Model
{
    protected $table = 'crm_automation_rules';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
