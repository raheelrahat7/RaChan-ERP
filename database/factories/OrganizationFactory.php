<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => Str::slug(fake()->unique()->company()).'-'.Str::lower(Str::random(6)),
        ];
    }
}
