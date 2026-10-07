<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LeadCommercialRecord extends Model
{
    protected $table = 'crm_lead_commercial_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'submitted_on' => 'date:Y-m-d', 'signed_on' => 'date:Y-m-d', 'version' => 'integer'];
    }
}
