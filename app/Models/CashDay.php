<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CashDay extends Model
{
    protected $fillable = ['store_id', 'business_date', 'status', 'opening_cash_centavos', 'notes',
        'opened_by_user_id', 'opened_by_name_snapshot', 'opened_at', 'request_key', 'request_hash',
        'closed_by_user_id', 'closed_by_name_snapshot', 'closed_at', 'closing', 'closing_request_key', 'closing_request_hash'];

    protected function casts(): array
    {
        return ['business_date' => 'date:Y-m-d', 'opening_cash_centavos' => 'integer', 'opened_at' => 'datetime',
            'closed_at' => 'datetime', 'closing' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (CashDay $day) {
            if ($day->getOriginal('status') === 'closed') {
                throw new LogicException('A final cash closing cannot be changed.');
            }
            $closingFields = ['status', 'closed_by_user_id', 'closed_by_name_snapshot', 'closed_at',
                'closing', 'closing_request_key', 'closing_request_hash', 'updated_at'];
            if (array_diff(array_keys($day->getDirty()), $closingFields)) {
                throw new LogicException('Cash day opening details are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Cash day history must be retained.'));
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
