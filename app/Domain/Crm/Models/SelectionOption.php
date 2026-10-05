<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class SelectionOption extends Model
{
    protected $table = 'crm_selection_options';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
