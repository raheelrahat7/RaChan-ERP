<?php

namespace Tests\Feature\Chat;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InternalChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_direct_group_and_workspace_messages_are_private_and_encrypted(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $other = $this->member($org, OrganizationRole::Member);
        $outsider = $this->member(Organization::factory()->create(), OrganizationRole::Owner);
        $this->actingAs($owner)->get(route('chat.index'))->assertInertia(fn (Assert $page) => $page->where('rooms.0.kind', 'workspace')->etc());
        $direct = $this->postJson(route('chat.direct'), ['recipient_id' => $agent->id])->assertOk()->json('room_id');
        $this->postJson(route('chat.send', $direct), ['body' => 'Private plan'])->assertOk()->assertJsonPath('message.body', 'Private plan');
        $this->assertNotSame('Private plan', DB::table('internal_chat_messages')->first()->body);
        $this->actingAs($agent)->getJson(route('chat.messages', $direct))->assertOk()->assertJsonPath('messages.0.body', 'Private plan');
        $this->actingAs($other)->getJson(route('chat.messages', $direct))->assertNotFound();
        $this->actingAs($outsider)->getJson(route('chat.messages', $direct))->assertNotFound();
        $this->actingAs($owner)->postJson(route('chat.group'), ['name' => 'Field team', 'members' => [$agent->id, $other->id]])->assertOk();
        $this->assertDatabaseHas('internal_chat_rooms', ['organization_id' => $org->id, 'kind' => 'group', 'name' => 'Field team']);
        $colleagueRoom = $this->actingAs($agent)->postJson(route('chat.direct'), ['recipient_id' => $other->id])->assertOk()->json('room_id');
        $this->postJson(route('chat.send', $colleagueRoom), ['body' => 'Colleague-only message'])->assertOk();
        $this->actingAs($agent)->get(route('chat.export'))->assertForbidden();
        $export = $this->actingAs($owner)->get(route('chat.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Private plan', $export);
        $this->assertStringContainsString('Colleague-only message', $export);
    }

    public function test_only_direct_participants_can_signal_a_voice_call(): void
    {
        $org = Organization::factory()->create();
        $caller = $this->member($org, OrganizationRole::Member);
        $recipient = $this->member($org, OrganizationRole::Member);
        $other = $this->member($org, OrganizationRole::Member);
        $this->actingAs($caller);
        $room = $this->postJson(route('chat.direct'), ['recipient_id' => $recipient->id])->json('room_id');
        $call = $this->postJson(route('chat.calls.start', $room))->assertOk()->json('call.id');
        $this->postJson(route('chat.calls.start', $room))->assertUnprocessable();
        $this->postJson(route('chat.calls.signal', $call), ['type' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => 'test']])->assertOk();
        $this->assertStringNotContainsString('"sdp"', DB::table('internal_chat_call_signals')->first()->payload);
        $this->actingAs($other)->getJson(route('chat.calls.signals', $call))->assertNotFound();
        $this->actingAs($recipient)->getJson(route('chat.calls.signals', $call))->assertOk()->assertJsonPath('signals.0.type', 'offer');
        $this->postJson(route('chat.calls.signal', $call), ['type' => 'answer', 'payload' => ['type' => 'answer', 'sdp' => 'test']])->assertOk();
        $this->assertDatabaseHas('internal_chat_calls', ['id' => $call, 'status' => 'active']);
        $this->postJson(route('chat.calls.end', $call))->assertOk();
        $this->assertDatabaseCount('internal_chat_call_signals', 0);
    }

    public function test_company_chat_automatically_includes_new_members_without_crm_access(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        DB::table('accounting_companies')->insert([
            ['organization_id' => $org->id, 'code' => 'A', 'name' => 'Company A', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $org->id, 'code' => 'B', 'name' => 'Company B', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAs($owner)->get(route('chat.index'))->assertOk();
        $workspace = DB::table('internal_chat_rooms')->where('organization_id', $org->id)->where('kind', 'workspace')->value('id');
        $this->postJson(route('chat.send', $workspace), ['body' => 'Welcome everyone'])->assertOk();

        $newMember = $this->member($org, OrganizationRole::Viewer);
        $this->actingAs($newMember)->get(route('chat.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rooms.0.kind', 'workspace')
                ->where('rooms.0.name', 'Company chat')->where('messages.0.body', 'Welcome everyone')->etc());
        $this->postJson(route('chat.send', $workspace), ['body' => 'Joined from another department'])->assertOk();
        $this->postJson(route('chat.direct'), ['recipient_id' => $owner->id])->assertOk();
    }
}
