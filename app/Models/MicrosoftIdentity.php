<?php

namespace App\Models;

use Database\Factories\MicrosoftIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property string $tenant_id
 * @property string $object_id
 * @property string $subject
 * @property Carbon|null $disabled_at
 * @property-read User $user
 */
#[Hidden(['tenant_id', 'object_id', 'subject'])]
class MicrosoftIdentity extends Model
{
    /** @use HasFactory<MicrosoftIdentityFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['disabled_at' => 'datetime'];
    }
}
