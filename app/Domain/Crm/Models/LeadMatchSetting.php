<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LeadMatchSetting extends Model
{
    protected $table = 'crm_lead_match_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['viewing_statuses' => 'array', 'version' => 'integer'];
    }
}
