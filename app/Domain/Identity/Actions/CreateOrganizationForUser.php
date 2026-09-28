<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

class CreateOrganizationForUser
{
    public function handle(User $user): Organization
    {
        $organization = Organization::create([
            'name' => "{$user->name}'s Organization",
            'slug' => Str::slug($user->name).'-'.Str::lower(Str::random(6)),
        ]);

        $organization->users()->attach($user, ['role' => OrganizationRole::Owner->value]);
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $organization;
    }
}
