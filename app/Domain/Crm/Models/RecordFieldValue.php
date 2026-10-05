<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/** @property mixed $value */
class RecordFieldValue extends Model
{
    protected $table = 'crm_record_field_values';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
