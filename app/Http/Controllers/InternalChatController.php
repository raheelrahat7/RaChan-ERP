<?php

namespace App\Http\Controllers;

use App\Domain\Chat\Actions\ManageChatCalls;
use App\Domain\Chat\Actions\ManageInternalChat;
use App\Domain\Chat\Queries\ExportChats;
use App\Domain\Identity\Enums\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InternalChatController extends Controller
{
    public function index(Request $request, ManageInternalChat $chat): Response
    {
        $org = $chat->organization($request->user());
        $rooms = $chat->rooms($org, $request->user());
        $roomId = (int) ($request->query('room') ?: $rooms[0]['id']);
        $chat->room($org, $request->user(), $roomId);
        $canExport = $request->user()->hasOrganizationRole($org, OrganizationRole::Owner) || $request->user()->hasOrganizationRole($org, OrganizationRole::Administrator);
        $exportRooms = [];
        if ($canExport) {
            $allRooms = DB::table('internal_chat_rooms')->where('organization_id', $org->id)->orderBy('id')->get(['id', 'kind', 'name']);
            $names = DB::table('internal_chat_members as member')->join('users', 'users.id', '=', 'member.user_id')
                ->whereIn('member.room_id', $allRooms->pluck('id'))->get(['member.room_id', 'users.name'])->groupBy('room_id');
            $exportRooms = $allRooms->map(fn ($room) => ['id' => $room->id, 'name' => match ($room->kind) {
                'workspace' => 'Company chat',
                'direct' => 'Direct: '.$names->get($room->id, collect())->pluck('name')->implode(', '),
                default => $room->name ?? 'Group',
            }])->all();
        }

        return Inertia::render('chat/Index', [
            'rooms' => $rooms, 'activeRoomId' => $roomId,
            'messages' => $chat->messages($org, $request->user(), $roomId),
            'members' => $org->users()->orderBy('name')->get(['users.id', 'users.name']),
            'currentUserId' => $request->user()->id,
            'canExportChats' => $canExport,
            'exportRooms' => $exportRooms,
            'iceServers' => config('chat.ice_servers'),
        ]);
    }

    public function direct(Request $request, ManageInternalChat $chat): JsonResponse
    {
        $data = $request->validate(['recipient_id' => ['required', 'integer']]);

        return response()->json(['room_id' => $chat->direct($chat->organization($request->user()), $request->user(), $data['recipient_id'])]);
    }

    public function group(Request $request, ManageInternalChat $chat): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'members' => ['required', 'array', 'min:2', 'max:49'], 'members.*' => ['required', 'integer', 'distinct']]);

        return response()->json(['room_id' => $chat->group($chat->organization($request->user()), $request->user(), $data['name'], $data['members'])]);
    }

    public function messages(Request $request, int $room, ManageInternalChat $chat): JsonResponse
    {
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'before' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['messages' => $chat->messages($chat->organization($request->user()), $request->user(), $room, $data['after'] ?? null, $data['before'] ?? null)]);
    }

    public function send(Request $request, int $room, ManageInternalChat $chat): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return response()->json(['message' => $chat->send($chat->organization($request->user()), $request->user(), $room, $data['body'])]);
    }

    public function activeCall(Request $request, int $room, ManageInternalChat $chat, ManageChatCalls $calls): JsonResponse
    {
        return response()->json(['call' => $calls->active($chat->organization($request->user()), $request->user(), $room)]);
    }

    public function startCall(Request $request, int $room, ManageInternalChat $chat, ManageChatCalls $calls): JsonResponse
    {
        return response()->json(['call' => $calls->start($chat->organization($request->user()), $request->user(), $room)]);
    }

    public function signals(Request $request, int $call, ManageInternalChat $chat, ManageChatCalls $calls): JsonResponse
    {
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);

        return response()->json(['signals' => $calls->signals($chat->organization($request->user()), $request->user(), $call, (int) ($data['after'] ?? 0))]);
    }

    public function signal(Request $request, int $call, ManageInternalChat $chat, ManageChatCalls $calls): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:offer,answer,ice'], 'payload' => ['required', 'array']]);
        $calls->signal($chat->organization($request->user()), $request->user(), $call, $data['type'], $data['payload']);

        return response()->json(['ok' => true]);
    }

    public function endCall(Request $request, int $call, ManageInternalChat $chat, ManageChatCalls $calls): JsonResponse
    {
        $calls->end($chat->organization($request->user()), $request->user(), $call);

        return response()->json(['ok' => true]);
    }

    public function export(Request $request, ManageInternalChat $chat, ExportChats $exports): StreamedResponse
    {
        $data = $request->validate(['room_id' => ['nullable', 'integer']]);

        return $exports->download($chat->organization($request->user()), $request->user(), $data['room_id'] ?? null);
    }
}
