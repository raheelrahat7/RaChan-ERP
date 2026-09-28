<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Queries\PipelineReport;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PipelineReportTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);

        return $user;
    }

    public function test_report_uses_as_of_history_preserves_lost_names_and_clips_open_intervals(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 1)->startOfDay());
        $org = Organization::factory()->create();
        $user = $this->member($org);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $lost = $pipeline->stages()->where('type', 'lost')->sole();
        $reason = $pipeline->reasons()->first();
        $action = app(ManageLeadPipeline::class);
        $lead = $action->create($org, $user, ['first_name' => 'Test', 'last_name' => 'Lead', 'assigned_to' => $user->id]);
        $initial = $lead->current_stage_id;
        $this->travelTo(now()->setDate(2026, 9, 3)->startOfDay());
        $action->move($org, $user, $lead, ['stage_id' => $lost->id, 'expected_stage_id' => $initial, 'lost_reason_id' => $reason->id]);
        $reason->update(['name' => 'Renamed']);
        $lost->update(['name' => 'Closed unsuccessful']);
        $this->travelTo(now()->setDate(2026, 9, 5)->startOfDay());
        $action->move($org, $user, $lead, ['stage_id' => $initial, 'expected_stage_id' => $lost->id]);
        $this->travelTo(now()->setDate(2026, 9, 7)->startOfDay());
        $query = app(PipelineReport::class);
        $past = $query->handle($org, ['from_date' => '2026-09-02', 'to_date' => '2026-09-04']);
        $this->assertSame(1, $past['summary']['lost']);
        $this->assertSame(0, $past['summary']['open']);
        $this->assertSame('No Finance', $past['lostReasons'][0]['reason']);
        $this->assertEquals(24, $past['stageTimes'][0]['total_hours']);
        $current = $query->handle($org, ['from_date' => '2026-09-02', 'to_date' => '2026-09-06']);
        $this->assertSame(1, $current['summary']['open']);
        $this->assertCount(0, $current['lostReasons']);
        $this->assertEqualsWithDelta(72, $current['stageTimes'][0]['total_hours'], 0.01);
        $this->assertSame(2, $current['stageTimes'][0]['intervals']);
        $this->assertSame(1, $current['stageTimes'][0]['leads']);
        $this->assertNull($current['summary']['win_rate']);
        $this->assertNull($current['summary']['conversion_rate']);
        $this->getAsUser($user, $org);
    }

    private function getAsUser(User $user, Organization $org): void
    {
        $this->actingAs($user)->get(route('crm.pipeline-report', ['from_date' => '2026-09-02', 'to_date' => '2026-09-06']))->assertInertia(fn (AssertableInertia $page) => $page->component('crm/PipelineReport')->where('summary.open', 1)->has('assignees', 1)->where('assignees.0.name', $user->name));
    }

    public function test_conversion_cohort_and_won_outcome_are_separate_and_untracked_data_is_explicit(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 1)->startOfDay());
        $org = Organization::factory()->create();
        $user = $this->member($org);
        $action = app(ManageLeadPipeline::class);
        $old = $action->create($org, $user, ['first_name' => 'Old', 'last_name' => 'Lead']);
        $this->travelTo(now()->setDate(2026, 9, 10)->startOfDay());
        $new = $action->create($org, $user, ['first_name' => 'New', 'last_name' => 'Lead']);
        $manual = $action->create($org, $user, ['first_name' => 'Manual', 'last_name' => 'Won']);
        $untracked = $org->leads()->create(['first_name' => 'No', 'last_name' => 'History']);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $won = $pipeline->stages()->where('type', 'won')->sole();
        $action->convert($org, $user, $old);
        $action->convert($org, $user, $new);
        $action->move($org, $user, $manual, ['stage_id' => $won->id, 'expected_stage_id' => $manual->current_stage_id]);
        $report = app(PipelineReport::class)->handle($org, ['from_date' => '2026-09-10', 'to_date' => '2026-09-30']);
        $this->assertSame(4, $report['summary']['total']);
        $this->assertSame(3, $report['summary']['won']);
        $this->assertSame(1, $report['summary']['unknown']);
        $this->assertSame(2, $report['summary']['converted']);
        $this->assertSame(3, $report['summary']['created']);
        $this->assertSame(1, $report['summary']['cohort_converted']);
        $this->assertEquals(33.3, $report['summary']['conversion_rate']);
        $this->assertEquals(100, $report['summary']['win_rate']);
        $this->assertCount(0, $report['stageTimes']);
    }

    public function test_pipeline_and_current_assignee_filters_are_scoped_and_dates_are_validated(): void
    {
        $org = Organization::factory()->create();
        $user = $this->member($org);
        $other = $this->member($org);
        $foreign = Organization::factory()->create();
        $foreignUser = $this->member($foreign);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $second = Pipeline::create(['organization_id' => $org->id, 'name' => 'Second']);
        $second->stages()->create(['name' => 'Initial', 'position' => 1, 'is_initial' => true]);
        $action = app(ManageLeadPipeline::class);
        $action->create($org, $user, ['first_name' => 'Selected', 'last_name' => 'Lead', 'assigned_to' => $user->id]);
        $action->create($org, $user, ['first_name' => 'Other', 'last_name' => 'Member', 'assigned_to' => $other->id]);
        $action->create($org, $user, ['first_name' => 'Second', 'last_name' => 'Pipeline', 'pipeline_id' => $second->id, 'assigned_to' => $user->id]);
        $action->create($foreign, $foreignUser, ['first_name' => 'Foreign', 'last_name' => 'Lead']);
        $this->actingAs($user)->get(route('crm.pipeline-report', ['pipeline_id' => $pipeline->id, 'assignee_id' => $user->id]))->assertInertia(fn (AssertableInertia $page) => $page->where('summary.total', 1)->has('pipelines', 2)->has('members', 2));
        $this->get(route('crm.pipeline-report', ['pipeline_id' => Pipeline::where('organization_id', $foreign->id)->sole()->id]))->assertSessionHasErrors('pipeline_id');
        $this->get(route('crm.pipeline-report', ['assignee_id' => $foreignUser->id]))->assertSessionHasErrors('assignee_id');
        $this->get(route('crm.pipeline-report', ['from_date' => '2026-01-10', 'to_date' => '2026-01-01']))->assertSessionHasErrors('to_date');
        $this->get(route('crm.pipeline-report', ['from_date' => 'bad']))->assertSessionHasErrors('from_date');
        $outsider = User::factory()->create(['current_organization_id' => $org->id]);
        $this->actingAs($outsider)->get(route('crm.pipeline-report'))->assertForbidden();
        $this->actingAs($user)->get(route('crm.pipeline-report'))->assertInertia(fn (AssertableInertia $page) => $page->where('summary.total', 3));
    }

    public function test_stage_movements_count_observed_edges_and_include_cross_pipeline_exits(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 1)->startOfDay());
        $org = Organization::factory()->create();
        $user = $this->member($org);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $new = $pipeline->stages()->where('is_initial', true)->sole();
        $qualified = $pipeline->stages()->where('name', 'Qualified')->sole();
        $second = Pipeline::create(['organization_id' => $org->id, 'name' => 'Second']);
        $destination = $second->stages()->create(['name' => 'Review', 'position' => 1, 'is_initial' => true]);
        $action = app(ManageLeadPipeline::class);
        $first = $action->create($org, $user, ['first_name' => 'First', 'last_name' => 'Lead', 'assigned_to' => $user->id]);
        $other = $action->create($org, $user, ['first_name' => 'Other', 'last_name' => 'Lead', 'assigned_to' => $user->id]);
        $this->travelTo(now()->setDate(2026, 9, 2)->startOfDay());
        $action->move($org, $user, $first, ['stage_id' => $qualified->id, 'expected_stage_id' => $new->id]);
        $action->move($org, $user, $other, ['stage_id' => $qualified->id, 'expected_stage_id' => $new->id]);
        $this->travelTo(now()->setDate(2026, 9, 3)->startOfDay());
        $action->move($org, $user, $first, ['stage_id' => $new->id, 'expected_stage_id' => $qualified->id]);
        $this->travelTo(now()->setDate(2026, 9, 4)->startOfDay());
        $action->transfer($org, $user, $first, ['pipeline_id' => $second->id, 'stage_id' => $destination->id, 'expected_pipeline_id' => $pipeline->id, 'expected_stage_id' => $new->id]);
        $this->travelTo(now()->setDate(2026, 9, 5)->startOfDay());
        $query = app(PipelineReport::class);
        $report = $query->handle($org, ['from_date' => '2026-09-02', 'to_date' => '2026-09-04']);
        $this->assertCount(3, $report['stageMovements']);
        $toQualified = collect($report['stageMovements'])->firstWhere('to_stage', 'Qualified');
        $this->assertSame(2, $toQualified['moves']);
        $this->assertSame(2, $toQualified['leads']);
        $this->assertSame(66.7, $toQualified['share_of_source_exits']);
        $outbound = collect($report['stageMovements'])->firstWhere('to_stage', 'Review');
        $this->assertSame('Sales Pipeline', $outbound['from_pipeline']);
        $this->assertSame('Second', $outbound['to_pipeline']);
        $this->assertSame(33.3, $outbound['share_of_source_exits']);
        $secondOnly = $query->handle($org, ['from_date' => '2026-09-02', 'to_date' => '2026-09-04', 'pipeline_id' => $second->id]);
        $this->assertCount(1, $secondOnly['stageMovements']);
        $firstOnly = $query->handle($org, ['from_date' => '2026-09-02', 'to_date' => '2026-09-04', 'pipeline_id' => $pipeline->id]);
        $this->assertCount(3, $firstOnly['stageMovements']);
        $earlier = $query->handle($org, ['from_date' => '2026-09-02', 'to_date' => '2026-09-03']);
        $this->assertCount(2, $earlier['stageMovements']);
        $this->actingAs($user)->get(route('crm.pipeline-report', ['from_date' => '2026-09-02', 'to_date' => '2026-09-04']))->assertInertia(fn (AssertableInertia $page) => $page->component('crm/PipelineReport')->has('stageMovements', 3));
    }
}
