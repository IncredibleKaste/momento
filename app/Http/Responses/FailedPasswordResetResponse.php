<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Symfony\Component\HttpFoundation\Response;

class FailedPasswordResetResponse implements FailedPasswordResetResponseContract
{
    public function toResponse($request): Response
    {
        throw ValidationException::withMessages([
            'email' => 'This password reset link is invalid or has expired.',
        ]);
    }
}
