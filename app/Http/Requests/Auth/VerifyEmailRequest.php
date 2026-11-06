<?php

namespace App\Http\Requests\Auth;

use Laravel\Fortify\Http\Requests\VerifyEmailRequest as FortifyVerifyEmailRequest;

class VerifyEmailRequest extends FortifyVerifyEmailRequest
{
    public function authorize(): bool
    {
        return ! $this->user()->hasVerifiedEmail() && parent::authorize();
    }
}
