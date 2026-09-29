<?php

namespace App\Domain\Chat\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageChatCalls
{
    public function __construct(private ManageInternalChat $chat, private RecordOrganizationAuditLog $audit) {}

    public function start(Organization $org, User $actor, int $roomId): object
    {
        $room = $this->chat->room($org, $actor, $roomId);
        abort_unless(data_get($room, 'kind') === 'direct', 422);

        return DB::transaction(function () use ($org, $actor, $roomId): object {
            DB::table('internal_chat_rooms')->where('id', $roomId)->lockForUpdate()->firstOrFail();
            $this->expire($roomId);
            if (DB::table('internal_chat_calls')->where('room_id', $roomId)->whereIn('status', ['ringing', 'active'])->exists()) {
                throw ValidationException::withMessages(['call' => 'A call is already in progress in this chat.']);
            }
            $recipientId = DB::table('internal_chat_members')->where('room_id', $roomId)->where('user_id', '!=', $actor->id)->value('user_id');
            abort_unless($recipientId !== null && $org->users()->whereKey($recipientId)->exists(), 422);
            $id = DB::table('internal_chat_calls')->insertGetId([
                'organization_id' => $org->id, 'room_id' => $roomId, 'initiator_id' => $actor->id,
                'recipient_id' => $recipientId, 'status' => 'ringing', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'chat.call.started', $org, ['call_id' => $id, 'room_id' => $roomId]);

            return DB::table('internal_chat_calls')->where('id', $id)->first();
        });
    }

    public function active(Organization $org, User $actor, int $roomId): ?object
    {
        $this->chat->room($org, $actor, $roomId);
        $this->expire($roomId);

        return DB::table('internal_chat_calls')->where('organization_id', $org->id)->where('room_id', $roomId)
            ->whereIn('status', ['ringing', 'active'])->latest('id')->first();
    }

    public function call(Organization $org, User $actor, int $callId): object
    {
        $call = DB::table('internal_chat_calls')->where('organization_id', $org->id)->where('id', $callId)->first();
        abort_unless($call !== null && in_array($actor->id, [(int) $call->initiator_id, (int) $call->recipient_id], true), 404);
        $this->chat->room($org, $actor, $call->room_id);

        return $call;
    }

    /** @return list<object> */
    public function signals(Organization $org, User $actor, int $callId, int $after): array
    {
        $this->call($org, $actor, $callId);

        return array_values(DB::table('internal_chat_call_signals')->where('call_id', $callId)->where('recipient_id', $actor->id)
            ->where('id', '>', $after)->orderBy('id')->limit(100)->get(['id', 'type', 'payload'])->map(fn ($row) => (object) ['id' => $row->id, 'type' => $row->type, 'payload' => json_decode(Crypt::decryptString($row->payload), true)])->all());
    }

    /** @param array<string,mixed> $payload */
    public function signal(Organization $org, User $actor, int $callId, string $type, array $payload): void
    {
        $call = $this->call($org, $actor, $callId);
        abort_unless(in_array(data_get($call, 'status'), ['ringing', 'active'], true), 422);
        abort_unless(in_array($type, ['offer', 'answer', 'ice'], true), 422);
        abort_unless(($type !== 'offer' || $actor->id === data_get($call, 'initiator_id')) && ($type !== 'answer' || $actor->id === data_get($call, 'recipient_id')), 403);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 30000) {
            throw ValidationException::withMessages(['payload' => 'Call signal is too large.']);
        }
        $recipient = $actor->id === data_get($call, 'initiator_id') ? data_get($call, 'recipient_id') : data_get($call, 'initiator_id');
        DB::table('internal_chat_call_signals')->insert(['call_id' => $callId, 'sender_id' => $actor->id,
            'recipient_id' => $recipient, 'type' => $type, 'payload' => Crypt::encryptString($encoded), 'created_at' => now()]);
        if ($type === 'answer') {
            DB::table('internal_chat_calls')->where('id', $callId)->where('status', 'ringing')->update(['status' => 'active', 'updated_at' => now()]);
        }
    }

    public function end(Organization $org, User $actor, int $callId): void
    {
        $call = $this->call($org, $actor, $callId);
        DB::table('internal_chat_calls')->where('id', $callId)->whereIn('status', ['ringing', 'active'])
            ->update(['status' => 'ended', 'ended_at' => now(), 'updated_at' => now()]);
        DB::table('internal_chat_call_signals')->where('call_id', $callId)->delete();
        $this->audit->handle($org, $actor, 'chat.call.ended', $org, ['call_id' => $callId, 'room_id' => data_get($call, 'room_id')]);
    }

    private function expire(int $roomId): void
    {
        $ids = DB::table('internal_chat_calls')->where('room_id', $roomId)->where(fn ($query) => $query->where(fn ($ringing) => $ringing->where('status', 'ringing')->where('created_at', '<', now()->subMinutes(5)))
            ->orWhere(fn ($active) => $active->where('status', 'active')->where('updated_at', '<', now()->subHours(2))))->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('internal_chat_calls')->whereIn('id', $ids)->update(['status' => 'ended', 'ended_at' => now(), 'updated_at' => now()]);
            DB::table('internal_chat_call_signals')->whereIn('call_id', $ids)->delete();
        }
    }
}
