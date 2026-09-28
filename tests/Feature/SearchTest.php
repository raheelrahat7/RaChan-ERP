<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_only_returns_records_from_the_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        Property::create(['organization_id' => $organization->id, 'name' => 'Marina Tower', 'type' => 'residential']);
        Property::create(['organization_id' => $other->id, 'name' => 'Secret Tower', 'type' => 'residential']);

        $this->actingAs($manager)->get(route('search.index', ['q' => 'Tower']))->assertInertia(fn (Assert $page) => $page->component('Search')->has('results', 1)->where('results.0.title', 'Marina Tower'));
        $this->actingAs($manager)->get(route('search.index', ['q' => 'Secret']))->assertInertia(fn (Assert $page) => $page->component('Search')->has('results', 0));
        $this->actingAs($manager)->get(route('search.index', ['q' => 'x']))->assertSessionHasErrors('q');
    }

    public function test_search_requires_organization_membership(): void
    {
        $organization = Organization::factory()->create();
        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actingAs($outsider)->get(route('search.index', ['q' => 'Tower']))->assertForbidden();
    }
}
