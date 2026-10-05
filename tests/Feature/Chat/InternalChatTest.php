<?php

namespace Tests\Feature\Chat;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InternalChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // These tests are about chat behaviour; scanning has its own tests and defaults to on.
        config(['chat.virus_scan.driver' => 'off']);
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

    private function directRoom(User $from, User $to): int
    {
        return $this->actingAs($from)->postJson(route('chat.direct'), ['recipient_id' => $to->id])->assertOk()->json('room_id');
    }

    public function test_room_list_reports_unread_previews_and_read_state_that_only_moves_forward(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $room = $this->directRoom($owner, $agent);
        $first = $this->postJson(route('chat.send', $room), ['body' => 'Hello there'])->json('message.id');
        $second = $this->postJson(route('chat.send', $room), ['body' => str_repeat('long ', 60)])->json('message.id');

        $rooms = $this->actingAs($agent)->get(route('chat.index'))->inertiaProps('rooms');
        $direct = collect($rooms)->firstWhere('id', $room);
        $this->assertSame(2, $direct['unread']);
        $this->assertSame($second, $direct['last_message']['id']);
        $this->assertLessThanOrEqual(121, mb_strlen($direct['last_message']['body']));
        $this->assertFalse($direct['last_message']['own']);
        $this->assertSame(2, $direct['member_count']);

        $this->postJson(route('chat.read', $room), ['message_id' => $second])->assertOk();
        $this->postJson(route('chat.read', $room), ['message_id' => $first])->assertOk();
        $this->assertSame($second, DB::table('internal_chat_members')->where('room_id', $room)->where('user_id', $agent->id)->value('last_read_message_id'));
        $this->assertSame(0, collect($this->get(route('chat.index'))->inertiaProps('rooms'))->firstWhere('id', $room)['unread']);
        $this->postJson(route('chat.read', $room), ['message_id' => 999999])->assertNotFound();

        $viewed = $this->actingAs($owner)->getJson(route('chat.messages', $room))->assertOk()->json('viewed_by');
        $this->assertSame($second, $viewed['message_id']);
        $this->assertSame([$agent->id], array_column($viewed['users'], 'id'));
        $this->actingAs($this->member(Organization::factory()->create(), OrganizationRole::Owner))->postJson(route('chat.read', $room), ['message_id' => $second])->assertNotFound();
    }

    public function test_company_chat_starts_caught_up_for_new_users_and_counts_the_organization(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $this->actingAs($owner)->get(route('chat.index'));
        $workspace = DB::table('internal_chat_rooms')->where('kind', 'workspace')->value('id');
        $this->postJson(route('chat.send', $workspace), ['body' => 'Old news'])->assertOk();
        $late = $this->member($org, OrganizationRole::Member);

        $room = collect($this->actingAs($late)->get(route('chat.index'))->inertiaProps('rooms'))->firstWhere('kind', 'workspace');
        $this->assertSame(0, $room['unread']);
        $this->assertSame(2, $room['member_count']);
        $this->actingAs($owner)->postJson(route('chat.send', $workspace), ['body' => 'New news'])->assertOk();
        $this->assertSame(1, collect($this->actingAs($late)->get(route('chat.index'))->inertiaProps('rooms'))->firstWhere('kind', 'workspace')['unread']);
    }

    public function test_mentions_are_validated_stored_notified_once_and_flag_the_room(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $third = $this->member($org, OrganizationRole::Member);
        $room = $this->directRoom($owner, $agent);

        $this->postJson(route('chat.send', $room), ['body' => 'Hey @third', 'mentions' => [$third->id]])->assertUnprocessable();
        $this->postJson(route('chat.send', $room), ['body' => 'Hey @agent', 'mentions' => [$agent->id, $agent->id]])->assertUnprocessable();
        $message = $this->postJson(route('chat.send', $room), ['body' => 'Hey @agent', 'mentions' => [$agent->id]])->assertOk()->assertJsonPath('message.mentions.0.id', $agent->id)->json('message');
        $this->assertDatabaseHas('organization_notifications', ['user_id' => $agent->id, 'category' => 'chat_mention', 'event_key' => 'chat_mention:'.$message['id'], 'href' => '/chat?room='.$room]);
        $this->assertSame(1, DB::table('organization_notifications')->where('user_id', $agent->id)->where('category', 'chat_mention')->count());
        $this->assertTrue(collect($this->actingAs($agent)->get(route('chat.index'))->inertiaProps('rooms'))->firstWhere('id', $room)['mentioned']);
        $workspace = DB::table('internal_chat_rooms')->where('kind', 'workspace')->value('id');
        $this->actingAs($owner)->postJson(route('chat.send', $workspace), ['body' => '@third', 'mentions' => [$third->id]])->assertOk();
        $this->postJson(route('chat.send', $workspace), ['body' => '@stranger', 'mentions' => [User::factory()->create()->id]])->assertUnprocessable();
    }

    public function test_attachments_are_private_typed_limited_and_downloadable_only_by_room_members(): void
    {
        Storage::fake('local');
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $other = $this->member($org, OrganizationRole::Member);
        $room = $this->directRoom($owner, $agent);

        $sent = $this->postJson(route('chat.attach', $room), ['body' => 'Floor plan', 'files' => [UploadedFile::fake()->createWithContent('plan.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')), UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf')]])
            ->assertOk()->assertJsonCount(2, 'message.attachments')->assertJsonPath('message.body', 'Floor plan')->json('message');
        [$image, $pdf] = $sent['attachments'];
        $this->assertSame('image', $image['kind']);
        $this->assertSame('file', $pdf['kind']);
        $stored = DB::table('internal_chat_attachments')->get();
        $this->assertCount(2, $stored);
        $this->assertStringStartsWith("organizations/{$org->id}/chat/{$room}/", $stored[0]->path);
        Storage::disk('local')->assertExists($stored[0]->path);

        $this->actingAs($agent)->get($image['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $this->get($image['url'])->headers->get('Content-Disposition'));
        $this->assertStringContainsString('attachment', $this->get($pdf['url'])->headers->get('Content-Disposition'));
        $this->actingAs($other)->get($image['url'])->assertNotFound();
        $this->actingAs($this->member(Organization::factory()->create(), OrganizationRole::Owner))->get($image['url'])->assertNotFound();

        $this->actingAs($owner)->postJson(route('chat.attach', $room), ['files' => [UploadedFile::fake()->create('run.php', 1, 'text/x-php')]])->assertUnprocessable();
        $this->postJson(route('chat.attach', $room), ['files' => [UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')]])->assertUnprocessable();
        $this->postJson(route('chat.attach', $room), ['files' => [UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]])->assertUnprocessable();
        $this->postJson(route('chat.attach', $room), ['files' => array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.txt", 1, 'text/plain'), range(1, 6))])->assertUnprocessable();
        $this->assertCount(2, DB::table('internal_chat_attachments')->get());
        $this->assertSame(1, DB::table('internal_chat_messages')->count());
    }

    public function test_voice_notes_need_a_bounded_duration_and_one_audio_file(): void
    {
        Storage::fake('local');
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $room = $this->directRoom($owner, $agent);
        $audio = fn () => UploadedFile::fake()->create('note.ogg', 40, 'audio/ogg');

        $this->postJson(route('chat.attach', $room), ['voice' => true, 'files' => [$audio()]])->assertUnprocessable();
        $this->postJson(route('chat.attach', $room), ['voice' => true, 'duration' => 301, 'files' => [$audio()]])->assertUnprocessable();
        $this->postJson(route('chat.attach', $room), ['voice' => true, 'duration' => 20, 'files' => [$audio(), $audio()]])->assertUnprocessable();
        $this->postJson(route('chat.attach', $room), ['voice' => true, 'duration' => 20, 'files' => [UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]])->assertUnprocessable();
        $this->postJson(route('chat.attach', $room), ['voice' => true, 'duration' => 20, 'files' => [$audio()]])->assertOk()
            ->assertJsonPath('message.attachments.0.kind', 'voice')->assertJsonPath('message.attachments.0.duration_seconds', 20);
        $last = collect($this->actingAs($agent)->get(route('chat.index'))->inertiaProps('rooms'))->firstWhere('id', $room)['last_message'];
        $this->assertSame('voice', $last['attachment_kind']);
    }

    public function test_search_looks_through_encrypted_history_for_room_members_only(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $other = $this->member($org, OrganizationRole::Member);
        $room = $this->directRoom($owner, $agent);
        foreach (['Villa handover Friday', 'Lunch?', 'handover keys ready'] as $text) {
            $this->postJson(route('chat.send', $room), ['body' => $text]);
        }

        $this->getJson(route('chat.messages', [$room, 'q' => 'HANDOVER']))->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.0.body', 'Villa handover Friday');
        $this->getJson(route('chat.messages', [$room, 'q' => 'nothing like this']))->assertOk()->assertJsonCount(0, 'messages');
        $this->actingAs($other)->getJson(route('chat.messages', [$room, 'q' => 'handover']))->assertNotFound();
    }

    public function test_video_calls_are_direct_only_and_record_their_kind(): void
    {
        $org = Organization::factory()->create();
        $caller = $this->member($org, OrganizationRole::Member);
        $recipient = $this->member($org, OrganizationRole::Member);
        $room = $this->directRoom($caller, $recipient);

        $this->postJson(route('chat.calls.start', $room), ['kind' => 'screen'])->assertUnprocessable();
        $this->postJson(route('chat.calls.start', $room), ['kind' => 'video'])->assertOk()->assertJsonPath('call.kind', 'video');
        $this->actingAs($recipient)->getJson(route('chat.calls.active', $room))->assertOk()->assertJsonPath('call.kind', 'video');
        $this->actingAs($caller)->get(route('chat.index'));
        $workspace = DB::table('internal_chat_rooms')->where('kind', 'workspace')->value('id');
        $this->actingAs($caller)->postJson(route('chat.calls.start', $workspace), ['kind' => 'video'])->assertUnprocessable();
    }
}
