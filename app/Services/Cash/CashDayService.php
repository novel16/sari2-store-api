<?php

namespace App\Services\Cash;

use App\Exceptions\CheckoutConflict;
use App\Models\CashDay;
use App\Models\CashMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\Stores\StoreWriteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class CashDayService
{
    public function __construct(private CashTotals $totals) {}

    public function current(Store $store): array
    {
        return DB::transaction(function () use ($store) {
            $date = CashDayGuard::businessDate(CashDayGuard::capture());
            $day = $store->cashDays()->where('business_date', $date)->first();

            return ['day' => $day, 'business_date' => $date, 'totals' => $day ? $this->totals->forDay($day) : null];
        });
    }

    public function read(Store $store, CashDay $day): array
    {
        return DB::transaction(fn () => $this->result($store->cashDays()->findOrFail($day->id), false));
    }

    public function open(Store $store, User $actor, array $input): array
    {
        return DB::transaction(function () use ($store, $actor, $input) {
            $store = StoreWriteAccess::lock($store);
            $at = CashDayGuard::capture();
            $key = Str::lower($input['request_key']);
            $notes = $this->notes($input['notes'] ?? null);
            $hash = $this->hash([$actor->id, $input['business_date'], (int) $input['opening_cash_centavos'], $notes]);
            $previous = $store->cashDays()->where('request_key', $key)->first();
            if ($previous) {
                $this->same($previous->request_hash, $hash);

                return $this->result($previous, true);
            }
            $date = CashDayGuard::businessDate($at);
            if ($input['business_date'] !== $date) {
                throw new CheckoutConflict('cash_date_changed', 'The business date changed. Review today before opening its cash day.');
            }
            if ($store->cashDays()->where('business_date', $date)->exists()) {
                throw new CheckoutConflict('cash_day_exists', 'A cash day already exists for today. Open its saved record.');
            }
            $day = $store->cashDays()->create(['business_date' => $date, 'status' => 'open',
                'opening_cash_centavos' => (int) $input['opening_cash_centavos'], 'notes' => $notes,
                'opened_by_user_id' => $actor->id, 'opened_by_name_snapshot' => $actor->name,
                'opened_at' => $at, 'request_key' => $key, 'request_hash' => $hash]);

            return $this->result($day, false);
        }, 3);
    }

    public function move(Store $store, CashDay $day, User $actor, array $input): array
    {
        return DB::transaction(function () use ($store, $day, $actor, $input) {
            $store = StoreWriteAccess::lock($store);
            $at = CashDayGuard::capture();
            $key = Str::lower($input['request_key']);
            $amount = (int) $input['amount_centavos'];
            $reason = trim($input['reason']);
            $hash = $this->hash([$actor->id, $day->id, $input['direction'], $amount, $reason]);
            $previous = CashMovement::where('store_id', $store->id)->where('request_key', $key)->first();
            if ($previous) {
                $this->same($previous->request_hash, $hash);

                return $this->movementResult($store, $previous, true);
            }
            $day = $store->cashDays()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            CashDayGuard::ensureToday($day, $at);
            $before = $this->totals->forDay($day);
            if ($input['direction'] === 'out' && $amount > (int) $before['expected_cash_centavos']) {
                throw new CheckoutConflict('cash_insufficient_cash', 'Cash out cannot exceed the expected available drawer cash.');
            }
            $movement = $day->movements()->create(['store_id' => $store->id, 'actor_user_id' => $actor->id,
                'actor_name_snapshot' => $actor->name, 'direction' => $input['direction'], 'amount_centavos' => $amount,
                'reason' => $reason, 'request_key' => $key, 'request_hash' => $hash, 'created_at' => $at]);

            return $this->movementResult($store, $movement, false);
        }, 3);
    }

    public function preview(Store $store, CashDay $day, User $actor, array $input): array
    {
        return DB::transaction(function () use ($store, $day, $actor, $input) {
            $store = StoreWriteAccess::lock($store);
            $at = CashDayGuard::capture();
            $day = $store->cashDays()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            CashDayGuard::ensureOpen($day);
            $activity = $this->totals->activity($day);
            $counted = (int) $input['counted_cash_centavos'];
            $variance = CashTotals::subtract($counted, (int) $activity['totals']['expected_cash_centavos']);
            $notes = $this->notes($input['notes'] ?? null);
            if ($variance !== 0 && ($notes === null || mb_strlen($notes) < 3)) {
                throw ValidationException::withMessages(['notes' => ['Explain the cash variance in at least three characters.']]);
            }
            $expires = $at->addMinutes(10)->toIso8601String();
            $snapshot = ['totals' => $activity['totals'], 'counted_cash_centavos' => (string) $counted,
                'variance_centavos' => (string) $variance, 'notes' => $notes];
            $payload = ['schema' => 1, 'actor_id' => $actor->id, 'store_id' => $store->id, 'cash_day_id' => $day->id,
                'snapshot' => $snapshot, 'activity_fingerprint' => $activity['fingerprint'], 'expires_at' => $expires];

            return ['cash_day_id' => $day->id, 'store_id' => $store->id, 'business_date' => $day->business_date->toDateString(),
                ...$snapshot, 'review_token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'expires_at' => $expires];
        }, 3);
    }

    public function close(Store $store, CashDay $day, User $actor, array $input): array
    {
        return DB::transaction(function () use ($store, $day, $actor, $input) {
            $store = StoreWriteAccess::lock($store);
            $at = CashDayGuard::capture();
            $key = Str::lower($input['request_key']);
            $hash = $this->hash([$actor->id, $day->id, hash('sha256', $input['review_token'])]);
            $previous = $store->cashDays()->where('closing_request_key', $key)->first();
            if ($previous) {
                $this->same($previous->closing_request_hash, $hash);

                return $this->result($previous, true);
            }
            $payload = $this->review($input['review_token'], $store, $day, $actor);
            if (CarbonImmutable::parse($payload['expires_at'])->lte($at)) {
                throw new CheckoutConflict('cash_review_expired', 'This cash closing review expired. Count and review the cash again.');
            }
            $day = $store->cashDays()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            CashDayGuard::ensureOpen($day);
            $activity = $this->totals->activity($day);
            if (! hash_equals($payload['activity_fingerprint'], $activity['fingerprint'])
                || $payload['snapshot']['totals'] !== $activity['totals']) {
                throw new CheckoutConflict('cash_activity_changed', 'Cash activity changed after review. Count and review the cash again.');
            }
            $day->forceFill(['status' => 'closed', 'closed_by_user_id' => $actor->id,
                'closed_by_name_snapshot' => $actor->name, 'closed_at' => $at, 'closing' => $payload['snapshot'],
                'closing_request_key' => $key, 'closing_request_hash' => $hash])->save();

            return $this->result($day, false);
        }, 3);
    }

    public function openingByKey(Store $store, string $key): array
    {
        $this->validKey($key);

        return DB::transaction(fn () => $this->result($store->cashDays()->where('request_key', Str::lower($key))->firstOrFail(), true));
    }

    public function closingByKey(Store $store, string $key): array
    {
        $this->validKey($key);

        return DB::transaction(fn () => $this->result($store->cashDays()->where('closing_request_key', Str::lower($key))->where('status', 'closed')->firstOrFail(), true));
    }

    public function movementByKey(Store $store, string $key): array
    {
        $this->validKey($key);

        return DB::transaction(fn () => $this->movementResult($store,
            CashMovement::where('store_id', $store->id)->where('request_key', Str::lower($key))->firstOrFail(), true));
    }

    private function result(CashDay $day, bool $replayed): array
    {
        return ['day' => $day, 'totals' => $this->totals->forDay($day), 'replayed' => $replayed];
    }

    private function movementResult(Store $store, CashMovement $movement, bool $replayed): array
    {
        return ['movement' => $movement, ...$this->result($store->cashDays()->findOrFail($movement->cash_day_id), $replayed)];
    }

    private function notes(?string $notes): ?string
    {
        return $notes === null || trim($notes) === '' ? null : trim($notes);
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function same(string $saved, string $hash): void
    {
        if (! hash_equals($saved, $hash)) {
            throw new CheckoutConflict('cash_key_conflict', 'This cash request key was already used with different details. Check the saved record before continuing.');
        }
    }

    private function validKey(string $key): void
    {
        abort_unless(Str::isUuid($key), 404);
    }

    private function review(string $token, Store $store, CashDay $day, User $actor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $error) {
            throw new CheckoutConflict('cash_review_invalid', 'This cash closing review is invalid. Review the cash closing again.');
        }
        if (($payload['schema'] ?? null) !== 1 || ($payload['actor_id'] ?? null) !== $actor->id
            || ($payload['store_id'] ?? null) !== $store->id || ($payload['cash_day_id'] ?? null) !== $day->id) {
            throw new CheckoutConflict('cash_review_invalid', 'This review belongs to another cash day, store, or owner. Review the cash closing again.');
        }

        return $payload;
    }
}
