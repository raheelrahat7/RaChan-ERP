<?php

namespace App\Domain\Chat\Actions;

use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Notifications\Models\OrganizationNotification;
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

    public function room(Organization $org, User $actor, int $roomId): \stdClass
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
        $this->ensureMembership($workspaceId, $actor, true);
        $rooms = DB::table('internal_chat_rooms as room')->where('room.organization_id', $org->id)
            ->where(fn ($query) => $query->where('room.id', $workspaceId)->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('internal_chat_members as membership')->whereColumn('membership.room_id', 'room.id')->where('membership.user_id', $actor->id)))
            ->orderByDesc('room.updated_at')->limit(100)->get(['room.id', 'room.kind', 'room.name']);
        $ids = $rooms->pluck('id');
        $memberNames = DB::table('internal_chat_members as membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->whereIn('membership.room_id', $ids)->get(['membership.room_id', 'users.id', 'users.name'])->groupBy('room_id');
        $summary = $this->roomSummaries($actor, array_map('intval', $ids->all()));
        $organizationSize = $org->users()->count();

        return array_values($rooms->map(function ($room) use ($memberNames, $actor, $summary, $organizationSize): array {
            $members = $memberNames->get($room->id, collect());
            $title = match ($room->kind) {
                'workspace' => 'Company chat',
                'direct' => $members->firstWhere('id', '!=', $actor->id)->name ?? 'Direct chat',
                default => $room->name ?? 'Group',
            };

            return ['id' => $room->id, 'kind' => $room->kind, 'name' => $title,
                'member_count' => $room->kind === 'workspace' ? $organizationSize : $members->count(),
                'unread' => $summary['unread'][$room->id] ?? 0,
                'mentioned' => in_array($room->id, $summary['mentioned'], true),
                'last_message' => $summary['last'][$room->id] ?? null,
                'members' => $members->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])->values()->all()];
        })->all());
    }

    /**
     * @param  array<int, int>  $roomIds
     * @return array{unread: array<int, int>, mentioned: array<int, int>, last: array<int, array<string, mixed>>}
     */
    private function roomSummaries(User $actor, array $roomIds): array
    {
        if (! $roomIds) {
            return ['unread' => [], 'mentioned' => [], 'last' => []];
        }
        $pointer = fn ($join) => $join->on('pointer.room_id', '=', 'message.room_id')->where('pointer.user_id', $actor->id);
        $unread = DB::table('internal_chat_messages as message')->leftJoin('internal_chat_members as pointer', $pointer)
            ->whereIn('message.room_id', $roomIds)
            ->where(fn ($query) => $query->whereNull('message.user_id')->orWhere('message.user_id', '!=', $actor->id))
            ->whereRaw('message.id > coalesce(pointer.last_read_message_id, 0)')
            ->selectRaw('message.room_id, count(*) as total')->groupBy('message.room_id')->pluck('total', 'room_id')
            ->map(fn ($total) => (int) $total)->all();
        $mentioned = DB::table('internal_chat_mentions as mention')->join('internal_chat_messages as message', 'message.id', '=', 'mention.message_id')
            ->leftJoin('internal_chat_members as pointer', $pointer)
            ->where('mention.user_id', $actor->id)->whereIn('message.room_id', $roomIds)
            ->whereRaw('message.id > coalesce(pointer.last_read_message_id, 0)')
            ->distinct()->pluck('message.room_id')->map(fn ($id) => (int) $id)->all();
        $latestIds = DB::table('internal_chat_messages')->whereIn('room_id', $roomIds)->selectRaw('max(id) as id')->groupBy('room_id')->pluck('id');
        $latest = ChatMessage::whereIn('id', $latestIds)->get();
        $names = User::whereIn('id', $latest->pluck('user_id')->filter())->pluck('name', 'id');
        $attachments = DB::table('internal_chat_attachments')->whereIn('message_id', $latest->pluck('id'))->orderBy('id')->get(['message_id', 'kind'])->groupBy('message_id');
        $last = [];
        foreach ($latest as $message) {
            $attached = $attachments->get($message->id);
            $last[$message->room_id] = [
                'id' => $message->id,
                'sender' => $names[$message->user_id] ?? 'Former member',
                'own' => $message->user_id === $actor->id,
                'body' => mb_strimwidth((string) $message->body, 0, 120, '…'),
                'attachment_kind' => $attached?->first()->kind,
                'created_at' => $message->created_at?->toIso8601String(),
            ];
        }

        return ['unread' => $unread, 'mentioned' => $mentioned, 'last' => $last];
    }

    /** Latest 2,000 messages are searched because bodies are encrypted at rest. */
    private const SEARCH_WINDOW = 2000;

    /** @return list<array<string,mixed>> */
    public function messages(Organization $org, User $actor, int $roomId, ?int $after = null, ?int $before = null, ?string $search = null): array
    {
        $this->room($org, $actor, $roomId);
        $needle = $search !== null ? mb_strtolower(trim($search)) : '';
        if ($needle !== '') {
            $messages = ChatMessage::where('organization_id', $org->id)->where('room_id', $roomId)->orderByDesc('id')->limit(self::SEARCH_WINDOW)->get()
                ->filter(fn (ChatMessage $message) => str_contains(mb_strtolower((string) $message->body), $needle))->take(100)->reverse()->values();
        } else {
            $messages = ChatMessage::where('organization_id', $org->id)->where('room_id', $roomId)
                ->when($after, fn ($query) => $query->where('id', '>', $after))
                ->when($before, fn ($query) => $query->where('id', '<', $before))
                ->orderByDesc('id')->limit(100)->get()->reverse()->values();
        }

        return $this->payloads($messages->all(), $roomId);
    }

    /**
     * @param  array<int, ChatMessage>  $messages
     * @return list<array<string,mixed>>
     */
    private function payloads(array $messages, int $roomId): array
    {
        $collection = collect($messages);
        $ids = $collection->pluck('id');
        $names = User::whereIn('id', $collection->pluck('user_id')->filter())->pluck('name', 'id');
        $mentions = DB::table('internal_chat_mentions as mention')->join('users', 'users.id', '=', 'mention.user_id')
            ->whereIn('mention.message_id', $ids)->get(['mention.message_id', 'users.id', 'users.name'])->groupBy('message_id');
        $attachments = DB::table('internal_chat_attachments')->whereIn('message_id', $ids)->orderBy('id')
            ->get(['id', 'message_id', 'original_name', 'mime', 'size', 'kind', 'duration_seconds'])->groupBy('message_id');

        return array_values($collection->map(fn (ChatMessage $message) => [
            'id' => $message->id, 'room_id' => $roomId, 'user_id' => $message->user_id,
            'sender' => $names[$message->user_id] ?? 'Former member', 'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
            'mentions' => $mentions->get($message->id, collect())->map(fn ($mention) => ['id' => $mention->id, 'name' => $mention->name])->values()->all(),
            'attachments' => $attachments->get($message->id, collect())->map(fn ($file) => [
                'id' => $file->id, 'name' => $file->original_name, 'mime' => $file->mime, 'size' => (int) $file->size,
                'kind' => $file->kind, 'duration_seconds' => $file->duration_seconds, 'url' => route('chat.attachments.show', $file->id, false),
            ])->values()->all(),
        ])->all());
    }

    /**
     * @param  list<int>  $mentionIds
     * @return array<string,mixed>
     */
    public function send(Organization $org, User $actor, int $roomId, string $body, array $mentionIds = []): array
    {
        $this->room($org, $actor, $roomId);
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Enter a message.']);
        }
        $message = DB::transaction(fn (): ChatMessage => $this->createMessage($org, $actor, $roomId, $body, $mentionIds));

        return $this->payloads([$message], $roomId)[0];
    }

    /**
     * Creates the message row, mentions, notifications and the sender's read pointer.
     *
     * @param  list<int>  $mentionIds
     */
    public function createMessage(Organization $org, User $actor, int $roomId, string $body, array $mentionIds = []): ChatMessage
    {
        $room = $this->room($org, $actor, $roomId);
        $mentionIds = array_values(array_unique(array_map('intval', $mentionIds)));
        $mentionIds = array_values(array_diff($mentionIds, [$actor->id]));
        if ($mentionIds && count(array_diff($mentionIds, $this->participantIds($org, $room))) > 0) {
            throw ValidationException::withMessages(['mentions' => 'Mention only people who are in this chat.']);
        }
        $message = ChatMessage::create(['organization_id' => $org->id, 'room_id' => $roomId, 'user_id' => $actor->id, 'body' => trim($body)]);
        foreach ($mentionIds as $userId) {
            DB::table('internal_chat_mentions')->insert(['message_id' => $message->id, 'user_id' => $userId]);
            OrganizationNotification::query()->insertOrIgnore([
                'organization_id' => $org->id, 'user_id' => $userId, 'category' => 'chat_mention',
                'event_key' => 'chat_mention:'.$message->id, 'title' => $actor->name.' mentioned you in a chat',
                'count' => 1, 'href' => '/chat?room='.$roomId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('internal_chat_rooms')->where('id', $roomId)->update(['updated_at' => now()]);
        $this->ensureMembership($roomId, $actor, $room->kind === 'workspace');
        $this->advanceRead($roomId, $actor->id, $message->id);

        return $message;
    }

    /** @return array<int, int> */
    public function participantIds(Organization $org, \stdClass $room): array
    {
        if ($room->kind === 'workspace') {
            return $org->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        }

        return DB::table('internal_chat_members')->where('room_id', $room->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public function markRead(Organization $org, User $actor, int $roomId, int $messageId): void
    {
        $room = $this->room($org, $actor, $roomId);
        abort_unless(DB::table('internal_chat_messages')->where('room_id', $roomId)->where('id', $messageId)->exists(), 404);
        $this->ensureMembership($roomId, $actor, $room->kind === 'workspace');
        $this->advanceRead($roomId, $actor->id, $messageId);
    }

    /**
     * Members who have read the actor's latest message in the room.
     *
     * @return array{message_id: int, users: array<int, array{id: int, name: string}>}|null
     */
    public function viewedBy(Organization $org, User $actor, int $roomId): ?array
    {
        $this->room($org, $actor, $roomId);
        $messageId = DB::table('internal_chat_messages')->where('room_id', $roomId)->where('user_id', $actor->id)->max('id');
        if (! $messageId) {
            return null;
        }
        $users = DB::table('internal_chat_members as membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->where('membership.room_id', $roomId)->where('membership.user_id', '!=', $actor->id)
            ->where('membership.last_read_message_id', '>=', $messageId)->orderBy('users.name')->get(['users.id', 'users.name']);

        return ['message_id' => (int) $messageId, 'users' => $users->map(fn ($user) => ['id' => (int) $user->id, 'name' => (string) $user->name])->all()];
    }

    private function ensureMembership(int $roomId, User $actor, bool $startCaughtUp): void
    {
        $exists = DB::table('internal_chat_members')->where('room_id', $roomId)->where('user_id', $actor->id)->exists();
        if ($exists) {
            return;
        }
        DB::table('internal_chat_members')->insertOrIgnore([
            'room_id' => $roomId, 'user_id' => $actor->id, 'joined_at' => now(),
            'last_read_message_id' => $startCaughtUp ? DB::table('internal_chat_messages')->where('room_id', $roomId)->max('id') : null,
        ]);
    }

    private function advanceRead(int $roomId, int $userId, int $messageId): void
    {
        DB::table('internal_chat_members')->where('room_id', $roomId)->where('user_id', $userId)
            ->where(fn ($query) => $query->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $messageId))
            ->update(['last_read_message_id' => $messageId]);
    }

    private function member(Organization $org, User $actor): void
    {
        abort_unless($actor->belongsToOrganization($org), 404);
    }
}
