<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'sale_id', 'actor_user_id', 'entry_type', 'amount_centavos', 'balance_before_centavos', 'balance_after_centavos',
        'request_key', 'request_hash', 'payment_method', 'notes', 'actor_name_snapshot', 'customer_name_snapshot', 'customer_phone_snapshot', 'sale_reversal_id'];

    protected function casts(): array
    {
        return ['amount_centavos' => 'integer', 'balance_before_centavos' => 'integer', 'balance_after_centavos' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(SaleReversal::class, 'sale_reversal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
