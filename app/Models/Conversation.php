<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['member_one_id', 'member_two_id'])]
class Conversation extends Model
{
    /** @return BelongsTo<User, $this> */
    public function memberOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_one_id');
    }

    /** @return BelongsTo<User, $this> */
    public function memberTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_two_id');
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return HasMany<ConversationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    /** @return HasOne<ConversationMessage, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany();
    }
}
