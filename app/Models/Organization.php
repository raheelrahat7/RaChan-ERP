<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $vat_return_frequency
 * @property int $id
 * @property string $name
 * @property string $slug
 */
#[Fillable(['name', 'slug', 'timezone', 'crm_follow_up_reminder_days', 'crm_follow_up_escalation_enabled', 'vat_enabled', 'tax_registration_number', 'vat_return_frequency', 'corporate_tax_profile', 'corporate_tax_year_start_month'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['vat_enabled' => 'boolean', 'crm_follow_up_reminder_days' => 'integer', 'crm_follow_up_escalation_enabled' => 'boolean'];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /** @return HasMany<CrmLead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(CrmLead::class);
    }
}
