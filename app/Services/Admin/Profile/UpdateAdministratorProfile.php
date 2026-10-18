<?php

namespace App\Services\Admin\Profile;

use App\Models\User;
use App\Services\Admin\AdministratorAccess;
use App\Services\Settings\UpdateAccount;
use Illuminate\Support\Facades\DB;

class UpdateAdministratorProfile
{
    public function __construct(private UpdateAccount $settings) {}

    public function profile(User $actor, array $input, ?string $sessionId): void
    {
        $saved = DB::transaction(function () use ($actor, $input, $sessionId) {
            $current = AdministratorAccess::lock($actor);
            $this->settings->profile($current, $input, $sessionId);

            return $current;
        }, 3);

        $actor->setRawAttributes($saved->getAttributes(), true);
    }

    public function password(User $actor, array $input, ?string $sessionId): void
    {
        $saved = DB::transaction(function () use ($actor, $input, $sessionId) {
            $current = AdministratorAccess::lock($actor);
            $this->settings->password($current, $input, $sessionId);

            return $current;
        }, 3);

        $actor->setRawAttributes($saved->getAttributes(), true);
    }
}
