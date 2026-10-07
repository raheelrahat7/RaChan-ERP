<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LeadRequirementSetting extends Model
{
    protected $table = 'crm_lead_requirement_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['choices' => 'array', 'version' => 'integer'];
    }
}
