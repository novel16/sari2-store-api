<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CashMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'cash_day_id', 'actor_user_id', 'actor_name_snapshot', 'direction',
        'amount_centavos', 'reason', 'request_key', 'request_hash', 'created_at'];

    protected function casts(): array
    {
        return ['amount_centavos' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Cash movement history must be retained.'));
    }

    public function cashDay(): BelongsTo
    {
        return $this->belongsTo(CashDay::class);
    }
}
