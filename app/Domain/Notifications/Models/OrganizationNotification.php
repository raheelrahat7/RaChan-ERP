<?php

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'category', 'event_key', 'title', 'count', 'href', 'read_at'])]
class OrganizationNotification extends Model
{
    protected function casts(): array
    {
        return ['count' => 'integer', 'read_at' => 'datetime'];
    }
}
