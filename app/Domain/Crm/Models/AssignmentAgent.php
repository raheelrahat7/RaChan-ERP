<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentAgent extends Model
{
    protected $table = 'crm_assignment_agents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['available_on' => 'date', 'max_active_leads' => 'integer'];
    }
}
