<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentHold extends Model
{
    protected $table = 'crm_assignment_holds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
