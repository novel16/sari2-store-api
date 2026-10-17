<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (str_contains($value, "\0")) {
            $fail('This password contains an unsupported character. Please choose another password.');
        } elseif (strlen($value) > 72) {
            $fail('This password is too long. Please use a shorter password.');
        }
    }
}
