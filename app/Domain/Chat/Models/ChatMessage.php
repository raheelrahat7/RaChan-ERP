<?php

namespace App\Domain\Chat\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'room_id', 'user_id', 'body'])]
class ChatMessage extends Model
{
    protected $table = 'internal_chat_messages';

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
