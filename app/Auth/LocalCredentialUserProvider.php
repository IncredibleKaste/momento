<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class LocalCredentialUserProvider extends EloquentUserProvider
{
    /** @param array<string, mixed> $credentials */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        if ($credentials === []) {
            return null;
        }

        return parent::retrieveByCredentials([
            ...$credentials,
            fn ($query) => $query->whereNotNull('password')->whereNull('disabled_at'),
        ]);
    }
}
