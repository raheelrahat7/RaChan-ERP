<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationInvitation> */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Member->value,
            'token' => Str::random(64),
            'invited_by' => User::factory(),
            'expires_at' => now()->addWeek(),
            'accepted_at' => null,
        ];
    }
}
