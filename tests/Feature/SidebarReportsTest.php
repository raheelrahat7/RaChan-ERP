<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SidebarReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_sidebar_report_routes_render_for_owner_without_source_data(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($owner);

        foreach ([
            'crm.broker-performance' => 'crm/BrokerPerformance',
            'transactions.pdc' => 'transactions/PdcRegister',
            'reports.index' => 'reports/Index',
            'crm.lead-gateway' => 'crm/LeadGateway',
            'crm.follow-up-settings' => 'crm/FollowUpSettings',
        ] as $name => $component) {
            $this->get(route($name))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component, false)->etc());
        }
    }
}
