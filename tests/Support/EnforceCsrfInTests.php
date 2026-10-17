<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

class EnforceCsrfInTests extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
