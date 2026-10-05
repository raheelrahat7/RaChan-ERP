<?php

namespace App\Domain\Chat\Actions;

use App\Domain\Chat\Models\ChatAttachment;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageChatAttachments
{
    public const FILE_TYPES = 'jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,csv,txt,mp3,m4a,wav,ogg,oga,webm,mp4';

    public const VOICE_TYPES = 'webm,ogg,oga,mp3,m4a,wav,mp4';

    public const MAX_VOICE_SECONDS = 300;

    public function __construct(private ManageInternalChat $chat, private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $mentionIds
     * @return array<string,mixed>
     */
    public function send(Organization $org, User $actor, int $roomId, array $files, string $body, array $mentionIds, bool $voice, ?int $duration): array
    {
        $this->chat->room($org, $actor, $roomId);
        Validator::make(
            ['files' => $files, 'duration' => $duration],
            [
                'files' => ['required', 'array', 'min:1', 'max:'.($voice ? 1 : 5)],
                'files.*' => ['file', 'max:'.($voice ? 5120 : 10240), 'mimes:'.($voice ? self::VOICE_TYPES : self::FILE_TYPES)],
                'duration' => $voice ? ['required', 'integer', 'min:1', 'max:'.self::MAX_VOICE_SECONDS] : ['nullable'],
            ],
        )->validate();

        $stored = [];
        try {
            $message = DB::transaction(function () use ($org, $actor, $roomId, $files, $body, $mentionIds, $voice, $duration, &$stored) {
                $message = $this->chat->createMessage($org, $actor, $roomId, $body, $mentionIds);
                foreach ($files as $file) {
                    $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
                    $path = "organizations/{$org->id}/chat/{$roomId}/".Str::uuid().'.'.$extension;
                    Storage::disk('local')->put($path, $file->getContent());
                    $stored[] = $path;
                    $mime = (string) $file->getMimeType();
                    ChatAttachment::create([
                        'organization_id' => $org->id, 'room_id' => $roomId, 'message_id' => $message->id, 'user_id' => $actor->id,
                        'path' => $path, 'original_name' => $this->safeName($file->getClientOriginalName(), $extension),
                        'mime' => $mime, 'size' => $file->getSize(), 'duration_seconds' => $voice ? $duration : null,
                        'kind' => $voice ? 'voice' : (str_starts_with($mime, 'image/') ? 'image' : 'file'),
                    ]);
                }

                return $message;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
        $this->audit->handle($org, $actor, 'chat.attachment.sent', $org, ['room_id' => $roomId, 'message_id' => $message->id, 'count' => count($files), 'voice' => $voice]);

        return $this->chat->messages($org, $actor, $roomId, $message->id - 1)[0] ?? ['id' => $message->id];
    }

    public function download(Organization $org, User $actor, int $attachmentId): StreamedResponse
    {
        $attachment = ChatAttachment::where('organization_id', $org->id)->findOrFail($attachmentId);
        $this->chat->room($org, $actor, $attachment->room_id);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $inline = str_starts_with($attachment->mime, 'image/') || str_starts_with($attachment->mime, 'audio/') || ($attachment->kind === 'voice');

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }

    private function safeName(string $name, string $extension): string
    {
        $base = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '_', basename($name)) ?? '');

        return mb_substr($base !== '' ? $base : 'file.'.$extension, 0, 255);
    }
}
