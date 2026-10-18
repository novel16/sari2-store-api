<?php

namespace App\Services\Admin\Subscriptions;

use App\Models\Account;
use App\Models\Admin\SubscriptionActivation;
use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\AdministratorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ActivateSubscription
{
    public function __construct(private SubscriptionQuote $quotes, private SubscriptionProposal $proposals) {}

    public function handle(Account $account, User $actor, array $input): array
    {
        $payload = $this->quotes->read($input['quote'], $account, $actor);
        $quoteHash = hash('sha256', $input['quote']);
        $requestHash = hash('sha256', $actor->id.':'.$account->id.':'.$quoteHash);
        try {
            return DB::transaction(function () use ($account, $actor, $input, $payload, $quoteHash, $requestHash) {
                // Same account lock as catalog writes: quota and subscription changes serialize.
                $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
                $review = $payload['proposal'];
                // Catalog writers take plan before administrator; keep that order
                // while checking an actor who may have just been suspended.
                $plan = Plan::whereKey($review['plan_id'])->lockForUpdate()->firstOrFail();
                $actor = AdministratorAccess::lock($actor);
                $previous = SubscriptionActivation::where('request_key', $input['request_key'])->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'This request key was used for different details. Check history before retrying.');

                    return ['activation' => $previous, 'replayed' => true];
                }
                abort_if(SubscriptionActivation::where('quote_hash', $quoteHash)->exists(), 409, 'This review was already confirmed. Refresh the subscription history.');
                abort_if(CarbonImmutable::parse($payload['expires_at'])->lte(now()), 409, 'This review expired. Review the subscription again.');
                abort_unless($plan->version === $review['plan_version'], 409, 'The plan changed after review. Refresh and review the current price and allowances.');
                $current = $this->proposals->build($account, $plan, $review['months'], CarbonImmutable::parse($payload['reviewed_at']));
                abort_unless($current === $review, 409, 'The account or subscription changed after review. Refresh and review again.');
                $settlement = $payload['settlement'];
                abort_if($settlement['payment_reference'] !== null && SubscriptionActivation::where('payment_reference', $settlement['payment_reference'])->exists(),
                    409, 'This payment reference was already recorded. Check history before retrying.');
                $subscription = $account->subscriptions()->create(['plan_id' => $plan->id,
                    'plan_version_id' => $review['plan_version_id'], 'status' => 'active',
                    'starts_at' => $review['starts_at'], 'ends_at' => $review['ends_at'],
                    'provider' => 'manual', 'provider_reference' => $settlement['payment_reference']]);
                $activation = SubscriptionActivation::create([
                    'account_id' => $account->id, 'subscription_id' => $subscription->id,
                    'actor_user_id' => $actor->id, 'actor_name_snapshot' => $actor->name,
                    'action' => $review['action'], 'months' => $review['months'], ...$settlement,
                    'snapshot' => $review, 'request_key' => $input['request_key'],
                    'request_hash' => $requestHash, 'quote_hash' => $quoteHash,
                ]);

                return ['activation' => $activation, 'replayed' => false];
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            abort(409, 'This payment reference or confirmation was already recorded. Refresh history before retrying.');
        }
    }
}
