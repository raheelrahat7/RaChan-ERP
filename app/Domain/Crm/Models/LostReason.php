<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LostReason extends Model
{
    protected $table = 'crm_lost_reasons';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
