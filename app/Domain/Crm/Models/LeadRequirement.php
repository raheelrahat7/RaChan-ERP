<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LeadRequirement extends Model
{
    protected $table = 'crm_lead_requirements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer'];
    }
}
