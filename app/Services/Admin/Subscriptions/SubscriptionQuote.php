<?php

namespace App\Services\Admin\Subscriptions;

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

class SubscriptionQuote
{
    public function __construct(private SubscriptionProposal $proposals) {}

    public function create(Account $account, User $actor, array $input): array
    {
        $at = CarbonImmutable::now()->startOfSecond();
        $proposal = $this->proposals->build($account, Plan::whereKey($input['plan_id'])->firstOrFail(), (int) $input['months'], $at);
        $paid = $input['settlement_method'] === 'manual_payment';
        if ($paid && (int) $input['amount_centavos'] !== $proposal['total_centavos']) {
            throw ValidationException::withMessages(['amount_centavos' => ['Verified payment must exactly match the reviewed plan total.']]);
        }
        $payload = ['schema' => 1, 'actor_id' => $actor->id, 'reviewed_at' => $at->toIso8601String(),
            'expires_at' => $at->addMinutes(5)->toIso8601String(), 'proposal' => $proposal,
            'settlement' => ['settlement_method' => $input['settlement_method'],
                'amount_centavos' => $paid ? $proposal['total_centavos'] : 0,
                'payment_reference' => $paid ? $input['payment_reference'] : null, 'reason' => $input['reason']]];

        return ['quote' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), ...$payload];
    }

    public function read(string $quote, Account $account, User $actor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($quote), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $error) {
            throw ValidationException::withMessages(['quote' => ['The review is invalid. Review the subscription again.']]);
        }
        abort_unless(($payload['schema'] ?? null) === 1 && ($payload['actor_id'] ?? null) === $actor->id
            && ($payload['proposal']['account_id'] ?? null) === $account->id, 409,
            'This review belongs to a different account or administrator. Review again.');

        return $payload;
    }
}
