<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->has('message_requests_enabled') && ! array_key_exists('name', $this->all())
                ? ['missing']
                : $this->nameRules(),
            'email' => ['missing'],
            'message_requests_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
