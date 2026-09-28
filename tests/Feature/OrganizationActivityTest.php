<?php

namespace Tests\Feature;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_organization_owners_and_administrators_can_view_projected_audit_events(): void
    {
        $this->withoutVite();
        $org = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach([$owner->id => ['role' => OrganizationRole::Owner->value], $manager->id => ['role' => OrganizationRole::Manager->value]]);
        $audit = app(RecordOrganizationAuditLog::class);
        $audit->handle($org, $owner, 'inventory.property.created', $org, ['token' => 'hidden-secret']);
        $audit->handle($foreign, $owner, 'inventory.property.created', $foreign, ['token' => 'foreign-secret']);
        $audit->handle($org, $owner, 'operations.job.created', $org, []);
        $this->actingAs($manager)->get('/organization/activity')->assertForbidden();
        $this->actingAs($owner)->get('/organization/activity?module=inventory')->assertOk()->assertInertia(fn (Assert $page) => $page->component('organization/Activity')->has('events.data', 1)->where('events.data.0.event', 'inventory.property.created')->missing('events.data.0.properties'));
        $this->get('/organization/activity')->assertDontSee('hidden-secret')->assertDontSee('foreign-secret');
    }
}
