<?php

namespace App\Domain\Chat\Actions;

use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageInternalChat
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function organization(User $actor): Organization
    {
        $org = $actor->currentOrganization;
        abort_unless($org !== null && $actor->belongsToOrganization($org), 404);

        return $org;
    }

    public function workspace(Organization $org, User $actor): int
    {
        $this->member($org, $actor);
        DB::table('internal_chat_rooms')->insertOrIgnore([
            'organization_id' => $org->id, 'kind' => 'workspace', 'name' => 'Company chat',
            'room_key' => 'workspace', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('internal_chat_rooms')->where('organization_id', $org->id)->where('room_key', 'workspace')->value('id');
    }

    public function direct(Organization $org, User $actor, int $recipientId): int
    {
        $this->member($org, $actor);
        if ($recipientId === $actor->id || ! $org->users()->whereKey($recipientId)->exists()) {
            throw ValidationException::withMessages(['recipient_id' => 'Choose another current organization member.']);
        }
        $ids = [$actor->id, $recipientId];
        sort($ids);
        $key = 'direct:'.$ids[0].':'.$ids[1];

        return DB::transaction(function () use ($org, $actor, $ids, $key): int {
            DB::table('internal_chat_rooms')->insertOrIgnore([
                'organization_id' => $org->id, 'kind' => 'direct', 'room_key' => $key,
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $roomId = (int) DB::table('internal_chat_rooms')->where('organization_id', $org->id)->where('room_key', $key)->value('id');
            foreach ($ids as $id) {
                DB::table('internal_chat_members')->insertOrIgnore(['room_id' => $roomId, 'user_id' => $id, 'joined_at' => now()]);
            }

            return $roomId;
        });
    }

    /** @param list<int> $memberIds */
    public function group(Organization $org, User $actor, string $name, array $memberIds): int
    {
        $this->member($org, $actor);
        $ids = array_values(array_unique([...$memberIds, $actor->id]));
        if (count($ids) < 3 || count($ids) > 50 || $org->users()->whereIn('users.id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['members' => 'Choose at least two other organization members (50 total maximum).']);
        }

        return DB::transaction(function () use ($org, $actor, $name, $ids): int {
            $roomId = DB::table('internal_chat_rooms')->insertGetId([
                'organization_id' => $org->id, 'kind' => 'group', 'name' => trim($name),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($ids as $id) {
                DB::table('internal_chat_members')->insert(['room_id' => $roomId, 'user_id' => $id, 'joined_at' => now()]);
            }
            $this->audit->handle($org, $actor, 'chat.group.created', $org, ['room_id' => $roomId]);

            return $roomId;
        });
    }

    public function room(Organization $org, User $actor, int $roomId): object
    {
        $this->member($org, $actor);
        $room = DB::table('internal_chat_rooms')->where('organization_id', $org->id)->where('id', $roomId)->first();
        abort_unless($room !== null, 404);
        if ($room->kind !== 'workspace') {
            abort_unless(DB::table('internal_chat_members')->where('room_id', $roomId)->where('user_id', $actor->id)->exists(), 404);
        }

        return $room;
    }

    /** @return list<array<string,mixed>> */
    public function rooms(Organization $org, User $actor): array
    {
        $workspaceId = $this->workspace($org, $actor);
        $rooms = DB::table('internal_chat_rooms as room')->where('room.organization_id', $org->id)
            ->where(fn ($query) => $query->where('room.id', $workspaceId)->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('internal_chat_members as membership')->whereColumn('membership.room_id', 'room.id')->where('membership.user_id', $actor->id)))
            ->orderByDesc('room.updated_at')->limit(100)->get(['room.id', 'room.kind', 'room.name']);
        $memberNames = DB::table('internal_chat_members as membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->whereIn('membership.room_id', $rooms->pluck('id'))->get(['membership.room_id', 'users.id', 'users.name'])->groupBy('room_id');

        return array_values($rooms->map(function ($room) use ($memberNames, $actor): array {
            $members = $memberNames->get($room->id, collect());
            $title = match ($room->kind) {
                'workspace' => 'Company chat',
                'direct' => $members->firstWhere('id', '!=', $actor->id)->name ?? 'Direct chat',
                default => $room->name ?? 'Group',
            };

            return ['id' => $room->id, 'kind' => $room->kind, 'name' => $title,
                'members' => $members->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])->values()->all()];
        })->all());
    }

    /** @return list<array<string,mixed>> */
    public function messages(Organization $org, User $actor, int $roomId, ?int $after = null, ?int $before = null): array
    {
        $this->room($org, $actor, $roomId);
        $messages = ChatMessage::where('organization_id', $org->id)->where('room_id', $roomId)
            ->when($after, fn ($query) => $query->where('id', '>', $after))
            ->when($before, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $names = User::whereIn('id', $messages->pluck('user_id')->filter())->pluck('name', 'id');

        return array_values($messages->map(fn (ChatMessage $message) => [
            'id' => $message->id, 'room_id' => $roomId, 'user_id' => $message->user_id,
            'sender' => $names[$message->user_id] ?? 'Former member', 'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ])->all());
    }

    /** @return array<string,mixed> */
    public function send(Organization $org, User $actor, int $roomId, string $body): array
    {
        $this->room($org, $actor, $roomId);
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Enter a message.']);
        }
        $message = ChatMessage::create(['organization_id' => $org->id, 'room_id' => $roomId, 'user_id' => $actor->id, 'body' => trim($body)]);
        DB::table('internal_chat_rooms')->where('id', $roomId)->update(['updated_at' => now()]);

        return ['id' => $message->id, 'room_id' => $roomId, 'user_id' => $actor->id, 'sender' => $actor->name,
            'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String()];
    }

    private function member(Organization $org, User $actor): void
    {
        abort_unless($actor->belongsToOrganization($org), 404);
    }
}
