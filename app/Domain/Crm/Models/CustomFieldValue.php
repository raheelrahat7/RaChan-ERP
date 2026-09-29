<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'lead_id', 'field_id', 'value', 'search_text', 'number_value', 'date_value', 'user_value'])]
class CustomFieldValue extends Model
{
    protected $table = 'crm_custom_field_values';

    protected function casts(): array
    {
        return ['value' => 'json', 'number_value' => 'decimal:4', 'date_value' => 'datetime'];
    }
}
