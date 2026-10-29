<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\RegisteredUserController as FortifyRegisteredUserController;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class RegisteredUserController extends FortifyRegisteredUserController
{
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        if (config('fortify.lowercase_usernames') && $request->has(Fortify::username())) {
            $request->merge([Fortify::username() => Str::lower($request->input(Fortify::username()))]);
        }

        try {
            $user = $creator->create($request->all());
        } catch (ValidationException $exception) {
            if (! isset($exception->validator->failed()['email']['Unique'])) {
                throw $exception;
            }

            $errors = $exception->errors();
            unset($errors['email']);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            Hash::make($request->input('password'));

            return app(RegisterResponse::class);
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration may have claimed this address after validation.
            return app(RegisterResponse::class);
        }

        try {
            event(new Registered($user));
        } catch (TransportExceptionInterface $exception) {
            report($exception);
        }

        // Keep registration anonymous so a duplicate email cannot be detected by login state.
        return app(RegisterResponse::class);
    }
}
