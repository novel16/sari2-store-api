<?php

namespace App\Models\Billing;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionOrder extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'request_hash', 'checkout_url', 'terms'];

    protected function casts(): array
    {
        return ['terms' => 'array', 'checkout_url' => 'encrypted', 'livemode' => 'boolean',
            'months' => 'integer', 'amount_centavos' => 'integer', 'plan_version' => 'integer',
            'expires_at' => 'datetime', 'paid_at' => 'datetime', 'fulfilled_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
