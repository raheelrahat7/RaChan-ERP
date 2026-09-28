<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentRoute extends Model
{
    protected $table = 'crm_assignment_routes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['member_ids' => 'array', 'active' => 'boolean'];
    }
}
