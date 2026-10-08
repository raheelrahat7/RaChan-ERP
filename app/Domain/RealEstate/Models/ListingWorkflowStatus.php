<?php

namespace App\Domain\RealEstate\Models;

use Illuminate\Database\Eloquent\Model;

class ListingWorkflowStatus extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer', 'version' => 'integer'];
    }
}
