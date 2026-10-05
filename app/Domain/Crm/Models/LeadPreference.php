<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LeadPreference extends Model
{
    protected $table = 'crm_lead_preferences';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['selected_field_keys' => 'array', 'presets' => 'encrypted:array'];
    }
}
