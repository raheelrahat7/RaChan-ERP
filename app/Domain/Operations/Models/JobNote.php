<?php

namespace App\Domain\Operations\Models;

use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'maintenance_request_id', 'author_id', 'note'])]
class JobNote extends Model
{
    protected $table = 'maintenance_job_notes';

    protected function casts(): array
    {
        return [];
    }

    /** @return BelongsTo<MaintenanceRequest, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
