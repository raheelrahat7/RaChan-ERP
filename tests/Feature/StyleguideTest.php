<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StyleguideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/styleguide')->assertRedirect(route('login'));
    }

    public function test_the_styleguide_renders_outside_production(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/styleguide')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Styleguide'));
    }

    public function test_the_styleguide_is_hidden_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->actingAs(User::factory()->create())
            ->get('/styleguide')
            ->assertNotFound();
    }
}
