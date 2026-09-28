<?php

namespace Tests\Feature\Transactions;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Leasing\Models\HandoverInspectionItem;
use App\Domain\Leasing\Models\VacancyRecord;
use App\Models\Document;
use App\Models\HandoverChecklist;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HandoverWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_a_move_out_releases_the_unit_and_closes_the_lease(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Creek', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '701', 'type' => 'apartment', 'status' => 'leased']);
        $lease = Lease::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'LSE-HANDOVER', 'status' => 'active', 'starts_on' => now()->subYear(), 'ends_on' => now()->addDay()]);

        $this->actingAs($manager)->post(route('handovers.store'), ['lease_id' => $lease->id, 'type' => 'move_out', 'scheduled_on' => now()->toDateString()])->assertRedirect();
        $handoverId = (int) HandoverChecklist::value('id');
        $this->post(route('handovers.inspection-items.store', $handoverId), ['area' => 'Kitchen walls', 'condition' => 'damaged', 'notes' => 'Paint chipped near the sink'])->assertRedirect();
        $this->post(route('handovers.inspection-items.store', $handoverId), ['area' => 'Entrance lock', 'condition' => 'good'])->assertRedirect();
        $this->assertSame(2, HandoverInspectionItem::count());
        $inspection = HandoverInspectionItem::firstOrFail();
        $this->post(route('handovers.inspection-items.evidence.store', $inspection), ['file' => $this->fakePng('kitchen-damage.png')])->assertRedirect();
        $document = Document::firstOrFail();
        Storage::disk('local')->assertExists($document->path);
        $this->get(route('handovers.index'))->assertInertia(fn (Assert $page) => $page
            ->where('handovers.0.inspectionItems.0.area', 'Kitchen walls')
            ->where('handovers.0.inspectionItems.0.condition', 'damaged')
            ->where('handovers.0.inspectionItems.0.documents.0.name', 'kitchen-damage.png'));
        $this->get(route('handovers.inspection-items.evidence.download', $document))->assertOk()->assertDownload('kitchen-damage.png');
        $this->actingAs($manager)->post(route('handovers.complete', $handoverId))->assertRedirect();
        $this->post(route('handovers.inspection-items.store', $handoverId), ['area' => 'Late note', 'condition' => 'good'])->assertStatus(422);
        $this->post(route('handovers.inspection-items.evidence.store', $inspection), ['file' => $this->fakePng('late.png')])->assertStatus(422);

        $this->assertDatabaseHas('handover_checklists', ['id' => $handoverId, 'status' => 'completed']);
        $this->assertDatabaseHas('leases', ['id' => $lease->id, 'status' => 'completed']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'available']);
        $vacancy = VacancyRecord::firstOrFail();
        $this->assertSame($unit->id, $vacancy->unit_id);
        $this->assertSame('inspection', $vacancy->readiness_status);
        $this->put(route('vacancies.update', $vacancy), ['readiness_status' => 'ready_to_list', 'target_ready_on' => today()->addDays(3)->toDateString(), 'notes' => 'Cleaning completed'])->assertRedirect();
        $this->get(route('handovers.index'))->assertInertia(fn (Assert $page) => $page
            ->where('vacancies.0.id', $vacancy->id)
            ->where('vacancies.0.readiness_status', 'ready_to_list')
            ->where('vacancies.0.unit.number', '701')
            ->where('vacancies.0.age_days', 0));
        $this->assertDatabaseHas('audit_logs', ['event' => 'transactions.handover.inspection_recorded', 'subject_id' => HandoverInspectionItem::firstOrFail()->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transactions.handover.evidence_attached', 'subject_id' => $document->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transactions.vacancy.opened', 'subject_id' => $vacancy->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transactions.vacancy.updated', 'subject_id' => $vacancy->id]);
    }

    public function test_vacancies_are_tenant_scoped_and_resolve_when_a_new_lease_activates(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $outsider = User::factory()->create(['current_organization_id' => $other->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $other->users()->attach($outsider, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Marina', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '204', 'type' => 'apartment', 'status' => 'available']);
        $vacancy = VacancyRecord::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'vacant_from' => today()->subDays(10), 'readiness_status' => 'listed']);

        $this->actingAs($outsider)->put(route('vacancies.update', $vacancy), ['readiness_status' => 'maintenance'])->assertNotFound();

        $reservation = Reservation::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'RSV-VACANCY', 'status' => 'active', 'expires_at' => now()->addDay(), 'created_by' => $manager->id]);
        $lease = Lease::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reservation_id' => $reservation->id, 'reference' => 'LSE-NEW', 'status' => 'draft', 'starts_on' => today(), 'ends_on' => today()->addYear()]);
        $this->actingAs($manager)->post(route('agreements.leases.activate', $lease))->assertRedirect();

        $this->assertDatabaseHas('vacancy_records', ['id' => $vacancy->id, 'resolution' => 'leased']);
        $this->assertNotNull($vacancy->fresh()?->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transactions.vacancy.resolved', 'subject_id' => $vacancy->id]);
    }

    public function test_viewers_and_other_organizations_cannot_record_inspection_observations(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $viewer = User::factory()->create(['current_organization_id' => $organization->id]);
        $outsider = User::factory()->create(['current_organization_id' => $other->id]);
        $organization->users()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
        $other->users()->attach($outsider, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Harbor', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'number' => '101', 'type' => 'apartment']);
        $lease = Lease::create(['organization_id' => $organization->id, 'unit_id' => $unit->id, 'reference' => 'LSE-INSPECT', 'status' => 'active', 'starts_on' => today(), 'ends_on' => today()->addYear()]);
        $handover = HandoverChecklist::create(['organization_id' => $organization->id, 'lease_id' => $lease->id, 'type' => 'move_in', 'status' => 'planned']);
        $input = ['area' => 'Living room', 'condition' => 'good'];

        $this->actingAs($viewer)->post(route('handovers.inspection-items.store', $handover), $input)->assertForbidden();
        $this->actingAs($outsider)->post(route('handovers.inspection-items.store', $handover), $input)->assertNotFound();
        $inspection = HandoverInspectionItem::create(['organization_id' => $organization->id, 'handover_checklist_id' => $handover->id, 'area' => 'Living room', 'condition' => 'good']);
        $this->actingAs($viewer)->post(route('handovers.inspection-items.evidence.store', $inspection), ['file' => $this->fakePng('room.png')])->assertForbidden();
        $this->actingAs($outsider)->post(route('handovers.inspection-items.evidence.store', $inspection), ['file' => $this->fakePng('room.png')])->assertNotFound();
        $this->assertDatabaseCount('handover_inspection_items', 1);
        $this->assertDatabaseCount('documents', 0);
    }

    private function fakePng(string $name): UploadedFile
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->assertIsString($contents);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
