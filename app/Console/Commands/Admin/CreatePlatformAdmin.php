<?php

namespace App\Console\Commands\Admin;

use App\Models\User;
use App\Rules\PasswordInput;
use App\Services\Admin\Administrators\AdministratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'app:create-platform-admin';

    protected $description = 'Securely provision a new platform administrator using interactive prompts';

    public function handle(AdministratorService $administrators): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively. Administrator passwords are accepted only through hidden prompts.');

            return self::FAILURE;
        }

        $attributes = [
            'name' => trim((string) $this->ask('Name')),
            'email' => Str::lower(trim((string) $this->ask('Email address'))),
            'password' => $this->secret('Password (8–72 characters, at most 72 UTF-8 bytes)', false),
            'password_confirmation' => $this->secret('Confirm password', false),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique(User::class)],
            'password' => ['bail', 'required', 'string', new PasswordInput, 'confirmed', 'max:72', Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if (! $this->confirm('Create this platform administrator?', false)) {
            $this->info('No administrator was created.');

            return self::SUCCESS;
        }

        try {
            $administrators->provisionFromConsole($attributes);
        } catch (ValidationException $error) {
            foreach ($error->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info('Platform administrator created. Sign in through the administrator login endpoint.');

        return self::SUCCESS;
    }
}
