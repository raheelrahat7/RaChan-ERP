<?php

namespace Tests\Feature\Finance;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_finance_events_and_exports_spreadsheet_safe_csv(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id, 'name' => '=Manager', 'email' => 'manager@example.com']);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AuditLog::create(['organization_id' => $organization->id, 'actor_id' => $manager->id, 'event' => 'accounting.period.reopened', 'subject_type' => '=Period', 'subject_id' => 4, 'properties' => ['note' => '=review']]);
        AuditLog::create(['organization_id' => $organization->id, 'actor_id' => $manager->id, 'event' => 'crm.contact.created']);
        $secondManager = User::factory()->create();
        $organization->users()->attach($secondManager, ['role' => OrganizationRole::Manager->value]);
        AuditLog::create(['organization_id' => $organization->id, 'actor_id' => $secondManager->id, 'event' => 'accounting.period.reopened']);
        $other = Organization::factory()->create();
        AuditLog::create(['organization_id' => $other->id, 'event' => 'accounting.period.closed']);

        $this->actingAs($manager)->get(route('accounting.audit-trail', ['event' => 'accounting.period.reopened', 'actor_id' => $manager->id]))->assertInertia(fn (Assert $page) => $page->component('finance/FinanceAuditTrail')->where('filters.event', 'accounting.period.reopened')->where('filters.actor_id', (string) $manager->id)->has('events', 1)->has('actors', 2)->has('logs.data', 1)->where('logs.data.0.actor.name', '=Manager'));
        $csv = $this->actingAs($manager)->get(route('accounting.audit-trail.export', ['event' => 'accounting.period.reopened', 'actor_id' => $manager->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString("accounting.period.reopened,'=Manager", $csv);
        $this->assertStringContainsString("'=Period", $csv);
        $this->assertStringContainsString('{""note"":""=review""}', $csv);
        $this->assertStringNotContainsString('crm.contact.created', $csv);
        $this->actingAs($manager)->get(route('accounting.audit-trail', ['event' => 'crm.contact.created']))->assertSessionHasErrors('event');
        $this->actingAs($manager)->get(route('accounting.audit-trail', ['actor_id' => 999999]))->assertSessionHasErrors('actor_id');
    }

    public function test_it_requires_finance_access(): void
    {
        $organization = Organization::factory()->create();
        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actingAs($outsider)->get(route('accounting.audit-trail'))->assertForbidden();
        $this->actingAs($outsider)->get(route('accounting.audit-trail.export'))->assertForbidden();
    }
}
