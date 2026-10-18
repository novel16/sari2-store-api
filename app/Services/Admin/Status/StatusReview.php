<?php

namespace App\Services\Admin\Status;

use App\Exceptions\CheckoutConflict;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Throwable;

class StatusReview
{
    public function create(array $snapshot, User $actor, int $revision, ?int $parentRevision): array
    {
        $expires = CarbonImmutable::now('UTC')->addMinutes(10)->toIso8601String();
        $payload = ['schema' => 1, 'actor_id' => $actor->id, 'revision' => $revision,
            'parent_revision' => $parentRevision, 'snapshot' => $snapshot, 'expires_at' => $expires];

        return [...$snapshot, 'review_token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'expires_at' => $expires];
    }

    public function read(string $token, User $actor, string $type, int $id): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($payload['schema'] ?? null) !== 1 || ($payload['actor_id'] ?? null) !== $actor->id
                || ! is_int($payload['revision'] ?? null) || ! array_key_exists('parent_revision', $payload)
                || (! is_int($payload['parent_revision']) && $payload['parent_revision'] !== null)
                || ! is_array($payload['snapshot'] ?? null) || ! is_string($payload['expires_at'] ?? null)) {
                $this->invalid();
            }
            $snapshot = $payload['snapshot'];
            if (($snapshot['target_type'] ?? null) !== $type || ($snapshot['target_id'] ?? null) !== $id
                || ! in_array($snapshot['to_status'] ?? null, ['active', 'suspended'], true)
                || ! is_string($snapshot['reason'] ?? null) || mb_strlen(trim($snapshot['reason'])) < 3
                || mb_strlen($snapshot['reason']) > 500) {
                $this->invalid();
            }
            CarbonImmutable::parse($payload['expires_at']);

            return $payload;
        } catch (DecryptException|JsonException $error) {
            $this->invalid();
        } catch (Throwable $error) {
            if ($error instanceof CheckoutConflict) {
                throw $error;
            }
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new CheckoutConflict('status_review_invalid', 'This status review is invalid. Review the requested change again.');
    }
}
