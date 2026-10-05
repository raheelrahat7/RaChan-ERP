<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class DealStageHistory extends Model
{
    public $timestamps = false;

    protected $table = 'crm_deal_stage_histories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'changed_at' => 'datetime'];
    }
}
