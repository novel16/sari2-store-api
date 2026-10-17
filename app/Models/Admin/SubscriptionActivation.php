<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SubscriptionActivation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'subscription_id', 'actor_user_id', 'actor_name_snapshot',
        'action', 'settlement_method', 'months', 'amount_centavos', 'payment_reference', 'reason',
        'snapshot', 'request_key', 'request_hash', 'quote_hash'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'months' => 'integer', 'amount_centavos' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Subscription activation history is immutable.'));
        static::deleting(fn () => throw new LogicException('Subscription activation history must be retained.'));
    }
}
