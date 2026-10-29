<?php

namespace App\Http\Controllers\Auth;

use App\Http\Responses\PasswordResetLinkResponse;
use Illuminate\Contracts\Support\Responsable;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        try {
            return parent::store($request);
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return app(PasswordResetLinkResponse::class);
        }
    }
}
