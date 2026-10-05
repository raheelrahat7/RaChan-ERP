<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'entity', 'tooltip', 'show_in_filter', 'show_in_list', 'name', 'key', 'type', 'options', 'required', 'active', 'view_roles', 'edit_roles', 'sort_order'])]
class CustomField extends Model
{
    protected $table = 'crm_custom_fields';

    protected function casts(): array
    {
        return ['show_in_filter' => 'boolean', 'show_in_list' => 'boolean', 'options' => 'array', 'required' => 'boolean', 'active' => 'boolean', 'view_roles' => 'array', 'edit_roles' => 'array'];
    }
}
