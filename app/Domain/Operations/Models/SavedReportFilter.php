<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'name', 'filters'])]
class SavedReportFilter extends Model
{
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
