<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class DealAccessRule extends Model
{
    protected $table = 'crm_deal_access_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
