<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmActivity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StageFollowUpAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_stage_entry_creates_one_audited_task_per_entry_with_frozen_due_date(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $target = $pipeline->stages()->where('name', 'Qualified')->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $url = route('crm.pipeline-stages.follow-up-rule', [$pipeline, $target]);
        $this->actingAs($owner)->put($url, ['enabled' => true, 'due_days' => 2])->assertRedirect();
        $this->assertSame(2, $target->fresh()->follow_up_due_days);
        $stageIndex = $pipeline->stages()->get()->pluck('id')->search($target->id);
        $this->get(route('crm.pipelines.index'))->assertInertia(fn (AssertableInertia $page) => $page->component('crm/Pipelines')->where("pipelines.0.stages.$stageIndex.follow_up_due_days", 2));
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Avery', 'last_name' => 'Lead']);
        $this->actingAs($owner)->put(route('crm.leads.assignment', $lead), ['assigned_to' => $agent->id])->assertRedirect();
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $history = $lead->history()->latest('id')->firstOrFail();
        $task = CrmActivity::where('stage_history_id', $history->id)->sole();
        $this->assertSame('task', $task->type);
        $this->assertSame($lead->id, $task->subject_id);
        $this->assertNull($task->created_by);
        $this->assertSame($history->changed_at->addDays(2)->toDateTimeString(), $task->due_at->toDateTimeString());
        $this->assertSame(2, $history->snapshot['follow_up_due_days']);
        $this->actingAs($agent)->get(route('crm.leads.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('followUps', 1)->where('followUps.0.id', $task->id));
        $this->actingAs($owner)->put($url, ['enabled' => false])->assertRedirect();
        event(new LeadStageChanged($history));
        $this->assertDatabaseCount('crm_activities', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'crm.stage_follow_up.created')->count());
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $initial->id, 'expected_stage_id' => $target->id]);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $this->assertDatabaseCount('crm_activities', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.stage.follow_up_rule_updated', 'subject_id' => $target->id]);
    }

    public function test_unassigned_entry_and_rolled_back_entry_do_not_create_tasks(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $target = $pipeline->stages()->where('name', 'Qualified')->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.follow-up-rule', [$pipeline, $target]), ['enabled' => true, 'due_days' => 0])->assertRedirect();
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Test', 'last_name' => 'Lead']);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $this->assertDatabaseCount('crm_activities', 0);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $initial->id, 'expected_stage_id' => $target->id]);
        $this->actingAs($owner)->put(route('crm.leads.assignment', $lead), ['assigned_to' => $agent->id])->assertRedirect();
        try {
            DB::transaction(function () use ($org, $owner, $lead, $target, $initial): void {
                app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('crm_activities', 0);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $history = $lead->history()->latest('id')->firstOrFail();
        $this->assertSame($history->changed_at->toDateTimeString(), CrmActivity::where('stage_history_id', $history->id)->sole()->due_at->toDateTimeString());
        $this->actingAs($owner)->put(route('crm.leads.assignment', $lead), ['assigned_to' => null])->assertRedirect();
        event(new LeadStageChanged($history));
        $this->assertDatabaseCount('crm_activities', 1);
    }

    public function test_only_authorized_pipeline_managers_can_set_valid_rules_on_own_stages(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $stage = $pipeline->stages()->firstOrFail();
        $foreign = Organization::factory()->create();
        $foreignPipeline = Pipeline::where('organization_id', $foreign->id)->sole();
        $foreignStage = $foreignPipeline->stages()->firstOrFail();
        $url = route('crm.pipeline-stages.follow-up-rule', [$pipeline, $stage]);
        $this->actingAs($manager)->put($url, ['enabled' => true, 'due_days' => 1])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.follow-up-rule', [$foreignPipeline, $foreignStage]), ['enabled' => true, 'due_days' => 1])->assertNotFound();
        $this->put(route('crm.pipeline-stages.follow-up-rule', [$pipeline, $foreignStage]), ['enabled' => true, 'due_days' => 1])->assertNotFound();
        $this->put($url, ['enabled' => true])->assertSessionHasErrors('due_days');
        $this->put($url, ['enabled' => true, 'due_days' => 366])->assertSessionHasErrors('due_days');
        $this->assertNull($stage->fresh()->follow_up_due_days);
    }
}
