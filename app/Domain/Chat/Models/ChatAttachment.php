<?php

namespace App\Domain\Chat\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'room_id', 'message_id', 'user_id', 'path', 'original_name', 'mime', 'size', 'kind', 'duration_seconds'])]
class ChatAttachment extends Model
{
    protected $table = 'internal_chat_attachments';

    public const UPDATED_AT = null;
}
