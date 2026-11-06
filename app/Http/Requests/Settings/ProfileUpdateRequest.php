<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        if (blank($this->user()->getAuthPassword())) {
            return [
                'name' => $this->nameRules(),
                'email' => ['required', 'string', 'email', 'max:255', Rule::in([$this->user()->email])],
            ];
        }

        return $this->profileRules($this->user()->id);
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (blank($this->user()->getAuthPassword()) && is_string($email)
            && mb_strtolower(trim($email)) === mb_strtolower(trim($this->user()->email))) {
            $this->merge(['email' => $this->user()->email]);
        }
    }
}
