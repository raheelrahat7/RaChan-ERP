<?php

namespace App\Domain\Chat\Queries;

use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportChats
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function download(Organization $org, User $actor, ?int $roomId): StreamedResponse
    {
        abort_unless($actor->belongsToOrganization($org) && (
            $actor->hasOrganizationRole($org, OrganizationRole::Owner) ||
            $actor->hasOrganizationRole($org, OrganizationRole::Administrator)
        ), 403);
        if ($roomId !== null) {
            abort_unless(DB::table('internal_chat_rooms')->where('organization_id', $org->id)->where('id', $roomId)->exists(), 404);
        }
        $this->audit->handle($org, $actor, 'chat.exported', $org, ['room_id' => $roomId]);

        return response()->streamDownload(function () use ($org, $roomId): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Could not open chat export stream.');
            }
            fputcsv($output, ['Room ID', 'Room type', 'Room name', 'Sender', 'Sent at', 'Message'], ',', '"', '');
            $rooms = DB::table('internal_chat_rooms')->where('organization_id', $org->id)->pluck('id');
            $query = ChatMessage::where('organization_id', $org->id)->whereIn('room_id', $rooms)
                ->when($roomId, fn ($q) => $q->where('room_id', $roomId));
            $senderIds = ChatMessage::where('organization_id', $org->id)->whereIn('room_id', $rooms)->distinct()->pluck('user_id');
            $names = DB::table('users')->whereIn('id', $senderIds)->pluck('name', 'id');
            $roomDetails = DB::table('internal_chat_rooms')->where('organization_id', $org->id)->get(['id', 'kind', 'name'])->keyBy('id');
            $query->orderBy('id')->chunkById(200, function ($messages) use ($output, $names, $roomDetails): void {
                foreach ($messages as $message) {
                    $room = $roomDetails->get($message->room_id);
                    fputcsv($output, array_map(function ($value): string {
                        $text = (string) ($value ?? '');

                        return preg_match('/^[\s]*[=+\-@]/u', $text) ? "'".$text : $text;
                    }, [$message->room_id, $room?->kind, $room?->name, $names[$message->user_id] ?? 'Former member', $message->created_at, $message->body]), ',', '"', '');
                }
            });
            fclose($output);
        }, 'organization-chats-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
