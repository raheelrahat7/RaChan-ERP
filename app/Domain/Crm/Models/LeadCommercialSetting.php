<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LeadCommercialSetting extends Model
{
    protected $table = 'crm_lead_commercial_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['statuses' => 'array', 'version' => 'integer'];
    }
}
