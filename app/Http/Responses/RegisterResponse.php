<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        $message = 'Check your inbox if this address is eligible for registration. Sign in to continue or resend verification.';

        return $request->wantsJson()
            ? response()->json(['message' => $message], 202)
            : to_route('login')->with('status', $message);
    }
}
