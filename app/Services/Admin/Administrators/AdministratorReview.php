<?php

namespace App\Services\Admin\Administrators;

use App\Exceptions\CheckoutConflict;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class AdministratorReview
{
    public function create(array $snapshot, User $actor, ?int $targetRevision = null, ?int $rosterRevision = null): array
    {
        $expires = CarbonImmutable::now('UTC')->addMinutes(10)->toIso8601String();
        $payload = ['schema' => 1, 'actor_id' => $actor->id, 'actor_revision' => (int) $actor->admin_status_revision,
            'target_revision' => $targetRevision, 'roster_revision' => $rosterRevision,
            'snapshot' => $snapshot, 'expires_at' => $expires];

        return [...$snapshot, 'review_token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'expires_at' => $expires];
    }

    public function read(string $token, User $actor, string $kind, ?int $targetId = null): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($payload['schema'] ?? null) !== 1 || ($payload['actor_id'] ?? null) !== $actor->id
                || ! is_int($payload['actor_revision'] ?? null) || ! is_array($payload['snapshot'] ?? null)
                || ! is_string($payload['expires_at'] ?? null) || ! array_key_exists('target_revision', $payload)
                || ! array_key_exists('roster_revision', $payload)) {
                $this->invalid();
            }
            $snapshot = $payload['snapshot'];
            if (($snapshot['kind'] ?? null) !== $kind || ! is_string($snapshot['reason'] ?? null)
                || mb_strlen(trim($snapshot['reason'])) < 3 || mb_strlen($snapshot['reason']) > 500) {
                $this->invalid();
            }
            if ($kind === 'create') {
                if (! is_string($snapshot['name'] ?? null) || trim($snapshot['name']) === '' || mb_strlen($snapshot['name']) > 120
                    || ! is_string($snapshot['email'] ?? null) || mb_strlen($snapshot['email']) > 254
                    || $payload['target_revision'] !== null || $payload['roster_revision'] !== null) {
                    $this->invalid();
                }
            } elseif (! is_array($snapshot['administrator'] ?? null) || ($snapshot['administrator']['id'] ?? null) !== $targetId
                || ! in_array($snapshot['from_status'] ?? null, ['active', 'suspended'], true)
                || ! in_array($snapshot['to_status'] ?? null, ['active', 'suspended'], true)
                || ! is_int($payload['target_revision']) || ! is_int($payload['roster_revision'])) {
                $this->invalid();
            }
            CarbonImmutable::parse($payload['expires_at']);

            return $payload;
        } catch (Throwable $error) {
            if ($error instanceof CheckoutConflict) {
                throw $error;
            }
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new CheckoutConflict('administrator_review_invalid', 'This administrator review is invalid. Review the change again.');
    }
}
