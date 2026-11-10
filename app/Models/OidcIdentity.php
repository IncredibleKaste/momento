<?php

namespace App\Models;

use Database\Factories\OidcIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $issuer
 * @property string $subject
 * @property string|null $institution_domain
 * @property Carbon|null $disabled_at
 */
#[Fillable(['user_id', 'provider', 'issuer', 'subject', 'institution_domain'])]
class OidcIdentity extends Model
{
    /** @use HasFactory<OidcIdentityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['disabled_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
