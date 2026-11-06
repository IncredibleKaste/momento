<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UniqueContactEmail implements ValidationRule
{
    public function __construct(private ?int $userId = null) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && User::query()->whereRaw('lower(email) = lower(?)', [trim($value)])
            ->when($this->userId !== null, fn ($query) => $query->where('id', '!=', $this->userId))->exists()) {
            $fail('This contact email is already registered. Use its existing sign-in method or choose another address.');
        }
    }
}
