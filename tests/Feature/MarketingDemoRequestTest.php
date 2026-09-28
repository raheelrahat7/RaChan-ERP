<?php

namespace Tests\Feature;

use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingDemoRequestTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'first_name' => 'Amina', 'last_name' => 'Khan', 'email' => 'amina@example.test',
            'company' => 'Canal Realty', 'role' => 'Owner', 'portfolio_size' => '11-50',
            'preferred_language' => 'ar', 'message' => 'Please contact me.', 'website' => '',
        ];
    }

    public function test_it_records_a_public_demo_lead_in_the_configured_organization(): void
    {
        $organization = Organization::factory()->create();
        config()->set('marketing.lead_organization', $organization->slug);

        $this->from('/demo')->post(route('marketing.demo.store'), $this->payload())
            ->assertRedirect('/demo')->assertSessionHas('success');

        $lead = CrmLead::sole();
        $this->assertSame($organization->id, $lead->organization_id);
        $this->assertSame('Website demo request', $lead->source);
        $this->assertSame('Marketing website', $lead->campaign_name);
        $this->assertStringContainsString('Preferred language: ar', $lead->notes);
        $this->assertDatabaseHas('crm_lead_stage_histories', ['lead_id' => $lead->id, 'notes' => 'Submitted through the marketing demo request form.']);
    }

    public function test_it_validates_fields_and_rejects_a_filled_honeypot(): void
    {
        $organization = Organization::factory()->create();
        config()->set('marketing.lead_organization', $organization->slug);

        $this->post(route('marketing.demo.store'), ['first_name' => '', 'email' => 'bad', 'company' => ''])
            ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'company']);
        $this->post(route('marketing.demo.store'), [...$this->payload(), 'website' => 'bot.example'])
            ->assertSessionHasErrors(['website']);
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_it_fails_visibly_when_the_destination_is_not_configured(): void
    {
        config()->set('marketing.lead_organization', null);

        $this->post(route('marketing.demo.store'), $this->payload())->assertSessionHasErrors(['demo']);
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_it_throttles_public_requests(): void
    {
        config()->set('marketing.lead_organization', null);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('marketing.demo.store'), $this->payload())->assertRedirect();
        }
        $this->post(route('marketing.demo.store'), $this->payload())->assertTooManyRequests();
    }
}
