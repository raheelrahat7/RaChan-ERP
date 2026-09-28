<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Inventory\Actions\ImportInventory;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_commit_replay_and_scope_are_enforced(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);
        $action = app(ImportInventory::class);
        $file = UploadedFile::fake()->createWithContent('properties.csv', "name,type,city,address_line_1\nSample,residential,Karachi,Example street\nSecond,commercial,,\n");
        $batch = $action->preview($org, $user, 'properties', $file);
        $this->assertSame([], $batch->errors);
        $this->assertSame(0, Property::count());
        $action->commit($org, $user, $batch->id);
        $action->commit($org, $user, $batch->id);
        $this->assertSame(2, Property::count());
        $this->assertCount(2, $batch->fresh()->created_ids);
        $other = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($other, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($other)->post(route('inventory.imports.commit', $batch->id))->assertNotFound();
    }

    public function test_invalid_and_changed_previews_never_partially_import(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);
        $action = app(ImportInventory::class);
        $batch = $action->preview($org, $user, 'properties', UploadedFile::fake()->createWithContent('invalid.csv', "name,type,city,address_line_1\nValid,residential,,\nInvalid,wrong,,\n"));
        $this->assertCount(1, $batch->errors);
        $this->actingAs($user)->post(route('inventory.imports.commit', $batch->id))->assertSessionHasErrors('batch');
        $this->assertSame(0, Property::count());
        $batch = $action->preview($org, $user, 'properties', UploadedFile::fake()->createWithContent('valid.csv', "name,type,city,address_line_1\nValid,residential,,\nChanged,commercial,,\n"));
        Property::create(['organization_id' => $org->id, 'name' => 'Changed', 'type' => 'commercial']);
        $this->post(route('inventory.imports.commit', $batch->id))->assertSessionHasErrors('batch');
        $this->assertSame(1, Property::count());
        $this->assertNull($batch->fresh()->committed_at);
        $foreign = Organization::factory()->create();
        $property = Property::create(['organization_id' => $foreign->id, 'name' => 'Foreign', 'type' => 'commercial']);
        $unit = $action->preview($org, $user, 'units', UploadedFile::fake()->createWithContent('units.csv', "property_id,number,type,status,area,asking_price\n{$property->id},A,office,leased,10,20\n"));
        $this->assertNotEmpty($unit->errors);
        $this->travel(2)->days();
        $this->post(route('inventory.imports.commit', $batch->id))->assertSessionHasErrors('batch');
        $this->travelBack();
    }

    public function test_csv_header_and_duplicate_records_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user, ['role' => OrganizationRole::Owner->value]);
        $action = app(ImportInventory::class);
        $batch = $action->preview($org, $user, 'properties', UploadedFile::fake()->createWithContent('duplicate.csv', "name,type,city,address_line_1\nSame,residential,,\nSame,residential,,\n"));
        $this->assertCount(1, $batch->errors);
        $this->expectException(ValidationException::class);
        $action->preview($org, $user, 'properties', UploadedFile::fake()->createWithContent('header.csv', "organization_id,name,type,city,address_line_1\n1,Example,residential,,\n"));
    }
}
