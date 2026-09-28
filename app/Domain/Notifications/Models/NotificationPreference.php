<?php

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'daily_digest_enabled', 'enabled_categories'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return ['daily_digest_enabled' => 'boolean', 'enabled_categories' => 'array'];
    }
}
