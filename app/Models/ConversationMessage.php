<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['conversation_id', 'sender_id', 'body', 'client_nonce'])]
class ConversationMessage extends Model
{
    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return HasOne<ConversationAttachmentSend, $this> */
    public function attachmentSend(): HasOne
    {
        return $this->hasOne(ConversationAttachmentSend::class, 'message_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
